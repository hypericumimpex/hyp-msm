<?php
/**
 * Author:  Aikadesign Oy (JG) <tuki@aikadesign.fi>
 * Since:   2018/02
 */


/**
 * Class MSMMediaItemList.
 *
 * @author Aikadesign Oy (JG) <tuki@aikadesign.fi>
 *
 */
class MSMMediaItemList {

	/**
	 * Get array with next batch of MSMMediaItem(s) which are not synced from source to target.
	 *
	 * @param integer $source_site ID of the site.
	 * @param integer $target_site
	 * @param int     $max_batch_size
	 *
	 * @return array
	 */
	public static function get_unsynced_by_site( $source_site, $target_site, $max_batch_size = 999 ) {

		/** @global wpdb $wpdb */
		global $wpdb;

		// Gather results
		$attachments = [];

		// Switch to source site
		if( get_current_blog_id() !== (int) $source_site ) {
			switch_to_blog( $source_site );
			$switch_back = true;
		}

		// TODO: The LIKE comparison makes is theoretically possible that if some media item happens to have same id as media item, then it may count as synchronised.
		$unsynced_items = $wpdb->get_col(
			"SELECT p.ID
					FROM $wpdb->posts p
					  LEFT JOIN $wpdb->postmeta AS mt ON (p.ID = mt.post_id AND mt.meta_key = 'msm_original_file')
					  LEFT JOIN $wpdb->postmeta AS mt1 ON (p.ID = mt1.post_id AND mt1.meta_key = 'msm_replication_info')
					WHERE 1 = 1 AND (
					  mt.post_id IS NULL 
					  AND (mt1.meta_value NOT LIKE \"a:%:{%i:" . $target_site . ";i:%;%}\" OR mt1.meta_value IS NULL)
					) AND p.post_type = 'attachment'
					GROUP BY p.ID
					ORDER BY p.ID ASC"
		);
		$count_unsynced = $wpdb->num_rows;

		// Iterate results until batch size is full
		foreach( $unsynced_items as $item ){

			// Create MediaItem object
			$attachments[] = new MSMMediaItem( (int) $item );

			// Break out of loop when batch size is reached
			if( $max_batch_size <= count( $attachments ) ){
				break;
			}
		}

		// Revert to previous blog if needed
		if ( ! empty( $switch_back ) ) {
			restore_current_blog();
		}

		// Return array of items and total count of unsynced (from source to target)
		return array('items' => $attachments, 'count' => $count_unsynced );
	}


	/**
	 * Get count of original media items on 'current' site. (whatever that may be at the time of calling)
	 * @return int
	 */
	public static function cur_site_media_count(){
		/** @global wpdb $wpdb */
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(p.ID) as total_count
					FROM $wpdb->posts p
					  LEFT JOIN $wpdb->postmeta AS mt ON (p.ID = mt.post_id AND mt.meta_key = 'msm_original_file')
					WHERE 1 = 1 
						AND mt.post_id IS NULL
						AND p.post_type = 'attachment'"
		);

		return (int) $count;
	}

}