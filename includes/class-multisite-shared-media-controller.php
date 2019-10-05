<?php


/**
 * Class MSMController. Responsible for loading requirements, registering hooks and handling requests belonging to the plugin.
 *
 * @author Aikadesign Oy (JG) <tuki@aikadesign.fi>
 *
 */
class MSMController {

	/**
	 * The unique plugin name
	 *
	 * @access   private
	 * @var      string $plugin_name
	 */
	private $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access   private
	 * @var      string $version The current version of the plugin.
	 */
	private $version;

	/**
	 * ReplicatorEngine - the 'worker' class. Controls replication process.
	 * @access  private
	 * @var     $replicator \MSMReplicatorEngine
	 */
	private $replicator;

	/**
	 * Network class - the 'map' class. Tells which sites are linked together.
	 * @access  private
	 * @var     $network \MSMNetwork
	 */
	private $network;

	/**
	 * Settings page. Controls the plugin settings pages, and saving to the database.
	 * @var $settings \MSMSettingsPage
	 */
	private $settings;

	/**
	 * MSMController constructor.
	 */
	public function __construct() {

		if ( defined( 'MSM_PLUGIN_VERSION' ) ) {
			$this->version = MSM_PLUGIN_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'multisite-shared-media';
	}


	/**
	 * Loads the plugin dependencies and defines the hooks for admin area.
	 */
	public function run(){
		$this->load_dependencies();

		// Detect and process eventual up-/downgrades
		new MSMPluginUpdater();

		$this->load_settings();

		$this->replicator = MSMReplicatorEngine::instance();
		$this->network = MSMNetwork::instance();
		$this->define_hooks();
	}


	/**
	 * Load admin settings from the database.
	 */
	private function load_settings(){
		$this->settings = get_site_option( 'msm_sharing_settings' );
	}


	/**
	 * Load required classes and compatibility scripts.
	 */
	private function load_dependencies(){
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-settings.php';
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-item.php';
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-item-list.php';
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-network.php';
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-replicator-engine.php';
		require_once MSM_PLUGIN_PATH . 'includes/class-multisite-shared-media-plugin-updater.php';

		require_once ABSPATH . 'wp-admin' . '/includes/image.php';

		//TODO: Erota omaan metodiin (load_compatibility)
		require_once MSM_PLUGIN_PATH . 'compatibility/woocommerce-multistore.php';
	}


	/**
	 * Register hooks.
	 */
	private function define_hooks(){

		$settings_page = MSMSettingsPage::instance();

		add_action( 'network_admin_menu', array( $settings_page, 'add_plugin_page' ) );
		add_action( 'network_admin_edit_msm_sharing_settings', array( $settings_page, 'msm_save_network_options' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( 'yes' === $this->settings['msm_do_share_media'] ) {
			add_action( 'add_attachment', array( $this, 'maybe_enqueue_item' ), 10, 2 );
			add_filter( 'upload_dir', array( $this, 'rewrite_upload_dir' ) );
		}

		if ( 'yes' === $this->settings['msm_do_delete_shared_media'] ) {
			add_action( 'delete_attachment', array( $this->replicator, 'delete_item' ), 10, 1 );
		} else {
			add_filter( 'wp_delete_file', array( $this, 'prevent_file_removal' ) );
		}

		add_action( 'wp_ajax_msm_replicate_all_existing', array( $this, 'replicate_existing_media' ) );

		add_filter( 'wpmu_delete_blog_upload_dir', array( $this, 'prevent_uploads_removal' ), 10, 0 );

		add_action( 'admin_bar_menu', array( $this, 'add_media_library'), 999 );
	}


	/**
	 * Register the static assets (CSS, JS) on the admin area.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( $this->plugin_name, MSM_PLUGIN_URL . 'assets/admin-styles.css', array(), $this->version, 'all' );
		wp_enqueue_script( $this->plugin_name, MSM_PLUGIN_URL . 'assets/replication-dialog.js', array( 'jquery' ), $this->version, true );
	}


	/**
	 * Controller method for bulk replication process. Works in batches and returns status to client after every batch.
	 * Client must trigger next batch processing. Must be called with AJAX. Outputs JSON and exits.
	 *
	 * @see Network Admin > Settings > Multisite Shared Media > Tab: Replication
	 * @throws \LogicException if one tries to replicate a not-original media item.
	 */
	public function replicate_existing_media() {

		$source = isset( $_POST['source'] ) ? (int)$_POST['source'] : null;
		$target = isset( $_POST['target'] ) ? (int)$_POST['target'] : null;

		/* Make sure the combination is allowed */
		if ( null !== $source && null !== $target ){
			$source_targets = $this->network->get_sites_synced_with( $source );
			if ( ! in_array( $target, $source_targets, false ) ){
				wp_die( 'Illegal source-target combination.', 'Bad Request', 400 );
			}
		}

		// Switch to source site if needed
		if( $source !== get_current_blog_id() ){
			switch_to_blog( $source );
			$switch_back = true;
		}

		// Replicate X images at a time - if requests times out, one can reduce this and retry.
		$max_batch_size = isset( $_REQUEST['batch_size'] ) ? $_REQUEST['batch_size'] : 10;  // default 10

		// Total item count from source site.
		$item_count = MSMMediaItemList::cur_site_media_count();

		// Unsynced items before process start - get next batch
		$unsynced_items = MSMMediaItemList::get_unsynced_by_site( $source, $target, $max_batch_size );

		// Process batch one item at a time. First enqueue, then trigger replication.
		foreach( (array) $unsynced_items['items'] as $i => $item ){
			$this->replicator->enqueue_for_replication( $item );
			$this->replicator->replicate_item();
			unset( $unsynced_items['items'][ $i ] );
			$unsynced_items['count']--;
		}

		// Revert back to current blog if we switched to different site before process.
		if ( ! empty( $switch_back ) ) {
			restore_current_blog();
		}

		// Return total count and remaining unsynced items to client by JSON.
		$ret                   = array();
		$ret['total']          = $item_count;
		$ret['not_replicated'] = $unsynced_items['count'];

		echo json_encode( $ret );
		wp_die();
	}


	/**
	 * Centralize uploads from all sites to the same directory. (remove '/site/X' paths)
	 * Theoretically one could share media also from site-specific locations, but sites would then refer to images under other sites directories.
	 *
	 * Note, before version 1.0.0 the location had to be WP default location.
	 * @since 1.0.0 Directory respects custom locations too.
	 *
	 * @param   array $dirs
	 *
	 * @return  array
	 */
	public function rewrite_upload_dir( $dirs ) {

		if( defined( 'UPLOADS') ){
			$dirs['baseurl'] = untrailingslashit( site_url() ) . UPLOADS;
			$dirs['basedir'] = untrailingslashit( ABSPATH ) . UPLOADS;
		} else {
			$dirs['baseurl'] = WP_CONTENT_URL . '/uploads';
			$dirs['basedir'] = WP_CONTENT_DIR . '/uploads';
		}

		$dirs['path']    = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}


	/**
	 * Check that MediaItem is original, and that there is no ongoing replication, then enqueue for replication and
	 * set trigger for process start. (trigger must be different for AJAX and regular requests)
	 *
	 * @param $attachment_id
	 */
	public function maybe_enqueue_item( $attachment_id ) {

		// Only enqueue if A) item is original (not copy) and B) replicator engine is not in progress (meaning this item is copy in progress)
		$media_item = new MSMMediaItem( $attachment_id );

		if ( false !== $media_item->is_original() && false === $this->replicator->what_is_replicating() ) {

			$this->replicator->enqueue_for_replication( $media_item );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				add_filter( 'wp_die_ajax_handler', array( $this, 'set_wp_die_ajax_handler' ), 10, 0 );
			} else {
				add_action( 'added_post_meta', array( $this->replicator, 'replicate_item' ), 9, 2 );
			}
		}
	}


	/**
	 * Overrule WP's default ajax die handler for AJAX file uploads. (the replication is triggered right before wp_die() )
	 * @return array
	 */
	public function set_wp_die_ajax_handler(){
		return array( $this, 'wp_die_ajax_handler' );
	}


	/**
	 * Trigger enqueued replication, then call WP's default ajax die handler.
	 *
	 * @param $message
	 * @param $title
	 * @param $args
	 *
	 * @throws \LogicException
	 */
	public function wp_die_ajax_handler( $message, $title, $args ){
		/** @var \MSMController $this */
		$this->replicator->replicate_item();
		_ajax_wp_die_handler( $message, $title, $args );
	}


	/**
	 * Do not remove files from disk upon media deletion in case they are used by other sites.
	 * This is useful if _sharing_ is enabled but _shared deletion_ is disabled.
	 *
	 * @param $file
	 *
	 * @return string
	 */
	public function prevent_file_removal( $file ) {
		/** @global \WP_Post $post */
		global $post;
		if ( ! empty( get_post_meta( $post->ID, 'msm_original_file', true ) ) || ! empty( get_post_meta( $post->ID, 'msm_replication_info', true ) ) ) {
			return '';
		}
		return $file;
	}


	/**
	 * Do not remove the root uploads directory. This would happen upon deletion of a network site,
	 * when the site's upload path is the root directory.
	 *
	 * @see $this->rewrite_upload_dir()
	 *
	 * @return bool
	 */
	public function prevent_uploads_removal(){
		return false;
	}


	/**
	 * Add link to Media Library under My Sites menu for faster access.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function add_media_library( $wp_admin_bar ) {
		foreach( (array) $this->network->get_sites() as $site ){
			$node_id = $site->blog_id;

			$wp_admin_bar->add_node(
				array(
					'id' => "wp-admin-bar-blog-{$node_id}-m",
					'title' => __( 'Media' ),
					'parent'    =>  "blog-{$node_id}",
					'href'  =>  get_admin_url( $node_id, 'upload.php' )
				)
			);
		}
	}
}