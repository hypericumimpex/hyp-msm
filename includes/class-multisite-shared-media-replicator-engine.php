<?php
/**
 * Author:  Aikadesign Oy (JG) <tuki@aikadesign.fi>
 * Since:   2018/02
 */

/**
 * MSMReplicatorEngine. Responsible for the replication process. The very core and brains of the plugin.
 *
 */
final class MSMReplicatorEngine
{
	private $network;

	/**
	 * @var \MSMMediaItem $item_in_process
	 */
	private $item_in_process;

	/**
	 * @var \MSMMediaItem $item_in_queue
	 */
	private $item_in_queue;

    /**
     * Call this method to get singleton
     *
     * @return MSMReplicatorEngine
     */
    public static function instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new MSMReplicatorEngine();
        }
        return $inst;
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    private function __construct() {
	    $this->network = MSMNetwork::instance();
    }


	/**
	 * Get MSMMediaItem currently in replication process.
	 * @return \MSMMediaItem|bool Return the item in process, or false if nothing in process.
	 */
	public function what_is_replicating() {
		return isset( $this->item_in_process ) ? $this->item_in_process : false;
	}

	/**
	 * Get the MSMMediaItem which is enqueued for replication. (but not yet in progress)
	 * @return bool|\MSMMediaItem
	 */
	private function get_enqueued_item() {
		return isset( $this->item_in_queue) ? $this->item_in_queue : false;
	}

	/**
	 * Enqueue item for replication. Possible to make the plugin ignore items through hook 'msm_filter_replicable_attachments'.
	 * This method does not trigger the replication. Just enqueues item.
	 * Note, that this method will overwrite any previously enqueued item. Only one item can be enqueued at a time.
	 *
	 * @param \MSMMediaItem $media_item
	 */
	public function enqueue_for_replication( MSMMediaItem $media_item ) {
		// Possibility to prevent replication in certain cases. (compatibility issues perhaps)
		$media_item          = apply_filters( 'msm_filter_replicable_attachments', $media_item );
		$this->item_in_queue = $media_item;
	}

	/**
	 * Replicate the enqueued MSMMediaItem to sites which are linked with the site the item belongs to.
	 * Double-checks target site for existing copy to prevent duplicates.
	 * This method must be used only for the original MSMMediaItem. (not for the copies)
	 *
	 * @param mixed $arg1
	 * @param mixed $arg2
	 *
	 * @return bool
	 * @throws \LogicException if trying to replicate not-original attachment.
	 */
	public function replicate_item( $arg1 = false, $arg2 = false ) {

		// Start replication only if something is enqueued and nothing is in progress yet (no infinite loops)
		if ( ! $this->what_is_replicating() && false !== $this->get_enqueued_item() ) {

			// 'added_post_meta' action is often triggered multiple times. Make sure the post_id matches before proceeding.
			if( 'added_post_meta' === current_action() && (int)$arg2 !== (int)$this->get_enqueued_item()->get_id() ){
				return false;
			}

			// Pick the enqueued item
			$this->item_in_process = $this->get_enqueued_item();
			unset( $this->item_in_queue );

			/* don't replicate not-original items */
			if ( false === $this->item_in_process->is_original() ) {
				throw new LogicException( 'Replication requested for not-original attachment. Exiting...' );
			}

			// Remember current site
			$current_site_id = get_current_blog_id();

			// Prepare data
			$attachment            = $this->item_in_process->get_post();
			$att                   = array();
			$att['post_id']        = $attachment->ID;
			$att['post_mime_type'] = $attachment->post_mime_type;
			$att['filename']       = substr( strrchr( $attachment->guid, '/' ), 1 );
			$att['post_title']     = $attachment->post_title;
			$att['post_status']    = $attachment->post_status;
			$att['post_parent']    = 0;
			$att['post_content']   = '';
			$att['guid']           = str_replace( get_site_url(), '<<REPLACE_SITE_URL>>', $attachment->guid );

			$path = get_attached_file( $attachment->ID );

			// Get eventually existing replication info (references to existing copies of the item)
			$replication_info = get_post_meta( $this->item_in_process->get_id(), 'msm_replication_info', true );
			if( empty( $replication_info ) ){
				$replication_info = array();
			}

			// Check which sites the item should still be replicated to.
			$copies = $this->item_in_process->get_copies();
			$sites_in_sync = array();
			if( $copies ) {
				$sites_in_sync = array_keys( $copies );
			}

			$sites_linked = $this->network->get_sites_synced_with( $current_site_id );
			$sites_to_sync = array_diff( $sites_linked, $sites_in_sync );

			// Replicate item to sites which are not 'in sync' yet.
			foreach ( $sites_to_sync  as $site_id ) {

				if ( (int) $site_id !== $current_site_id ) { //failsafe
					switch_to_blog( $site_id );

					// Double-check on the target site that copy doesn't exist
					$args = array(
						'post_type'   => 'attachment',
						'post_status' => null,
						'posts_per_page' => - 1,
						'orderby'     => 'date',
						'order'       => 'ASC',
						'meta_query'  => array(
							array(
								// check if a attachment already exist on target site with reference to this image
								'key'     => 'msm_original_file',
								'compare' => '=',
								'value'   => serialize( array( $current_site_id => $att['post_id'] ) ),
							),
						),
					);

					$copies = get_posts( $args );

					// If no reference to the original file is found from target site, do replication.
					if ( count( $copies ) === 0 ) {
						$replicated_id = $this->create_attachment( $att, $path, $site_id );
						update_post_meta( $replicated_id, 'msm_original_file', array( $current_site_id => $att['post_id'] ) );
					} else {
						// Re-link copy to original item if it would be found for any reason. (should never ever happen though)
						$replicated_id = $copies[0]->ID;
					}

					// Annotate the new copy
					$replication_info[ $site_id ] = $replicated_id;
				}
			}

			// Back to source site
			switch_to_blog( $current_site_id );

			// Update the list of existing copies for the item
			update_post_meta( $this->item_in_process->get_id(), 'msm_replication_info', $replication_info );

			// Free the replication process
			unset( $this->item_in_process );
		}

		return true;
	}


	/**
	 * Creates the attachment on the target site. Registers a post guid(URL) belonging to target site's domain/path.
	 *
	 * @param $attachment
	 * @param $path
	 * @param $site_id
	 *
	 * @return int|\WP_Error The newly created attachment's id on success. WP_Error on error.
	 */
	private function create_attachment( $attachment, $path, $site_id ) {

		$attachment['guid'] = str_replace( '<<REPLACE_SITE_URL>>', get_site_url( $site_id ), $attachment['guid'] );

		$attachment_id = wp_insert_attachment( $attachment, $path, $attachment['post_parent'] );

		if ( ! is_wp_error( $attachment_id ) ) {

			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $path );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );
		}

		return $attachment_id;
	}


	/**
	 * Handles deletion of media items. Reserves the 'replication process', then triggers the deletion.
	 * Removes the original file and all copies across the network.
	 *
	 * Note, the controller calls the method only if 'shared deletion' is enabled.
	 *
	 * @param $attachment_id
	 */
	public function delete_item( $attachment_id ) {

		if ( ! $this->what_is_replicating() ) {
			$current_blog = get_current_blog_id();

			$this->item_in_process = $attachment_id;

			if ( $orig = get_post_meta( $attachment_id, 'msm_original_file', true ) ) {
				$this->delete_item_and_copies( key( $orig ), $orig[ key( $orig ) ] );
			} elseif ( is_array( $copies = get_post_meta( $attachment_id, 'msm_replication_info', true ) ) ) {
				$this->delete_copies( $copies, $current_blog );
			}

			switch_to_blog( $current_blog );
			$this->item_in_process = false;
		}
	}


	/**
	 * Helper method for delete_item method. Deletes the original item and all copies.
	 *
	 * @param $orig_blog
	 * @param $orig_id
	 */
	private function delete_item_and_copies( $orig_blog, $orig_id ) {
		$current_blog = get_current_blog_id();
		switch_to_blog( $orig_blog );
		$copies = get_post_meta( $orig_id, 'msm_replication_info', true );
		if( false !== $copies ){
			$this->delete_copies( $copies, $current_blog );
		}
		wp_delete_attachment( $orig_id );
		switch_to_blog( $current_blog );
	}

	/**
	 * Helper method for delete_item and delete_item_and_copies methods. Deletes all copies of given item.
	 *
	 * @param array $copies
	 * @param       $current_blog
	 */
	private function delete_copies( array $copies, $current_blog ) {
		foreach ( $copies as $blog_id => $attachment_id ) {
			if ( $blog_id !== $current_blog ) {
				switch_to_blog( $blog_id );
				wp_delete_attachment( $attachment_id );
				restore_current_blog();
			}
		}
	}
}