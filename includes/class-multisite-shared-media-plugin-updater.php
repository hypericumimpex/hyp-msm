<?php
/**
 * Author:  Aikadesign Oy (JG) <tuki@aikadesign.fi>
 * Since:   2018/02
 */

/**
 * Class MSMPluginUpdater. Responsible for compatibility between this plugin's versions.
 *
 * @author Aikadesign Oy (JG) <tuki@aikadesign.fi>
 *
 */

/**
 * MSM Plugin Updater
 *
 */
class MSMPluginUpdater
{

	/**
	 * MSMPluginUpdater constructor.
	 */
	public function __construct() {
		$file_version = MSM_PLUGIN_VERSION;
		$db_version = (string) get_network_option( null, 'msm_db_version' );

		$version_diff = version_compare( $db_version, $file_version );

	    if ( - 1 === $version_diff ) {
		    /* Plugin file version is higher than database version. Files have been upgraded or database downgraded. */
			$this->process_upgrade( $db_version, $file_version );
	    } elseif ( 1 === $version_diff ) {
		    /* Database version is newer than plugin file version. Files have been downgraded? */
		    $this->process_downgrade( $db_version, $file_version );
	    }

	    if( get_site_transient( 'msm_plugin_updated_msg' ) ){
		    add_action( 'network_admin_notices', array( $this, 'display_update_notice' ) );
	    }
	    if( get_site_transient( 'msm_plugin_downgrade_msg' ) ){
		    add_action( 'network_admin_notices', array( $this, 'display_downgrade_warning' ) );
	    }
    }

    private function process_upgrade( $from, $to ){
		$accept = true;

		// For version 1.1.0, enable sharing between all sites.
    	if( -1 === version_compare( $from, '1.2.0' ) ){
    		$this->share_all_sites();
	    }

	    set_site_transient( 'msm_plugin_updated_msg', sprintf( __( 'Thank you for updating Multisite Shared Media to version %s.', 'multisite-shared-media' ), $to ), 6 );
	    add_action( 'network_admin_notices', array( $this, 'display_update_notice' ) );

	    if( $accept ){
		    update_network_option( null, 'msm_db_version', $to );
	    }
    }


    private function process_downgrade( $from, $to ){
	    $accept = true;

    	set_site_transient( 'msm_plugin_downgrade_msg', sprintf( __( 'Warning! Multisite Shared Media plugin noticed a version change from %s to %s. There are not any implemented downgrade processes in place and the plugin data might get corrupted. Please contact the plugin author if the downgrade happened unintentionally, to examine what may have happened. To begin, take a full file and database backup now, before doing anything else. Copy this error message for you, and pass it along to the plugin author.', 'multisite-shared-media' ), $from, $to ), 30 );
	    add_action( 'network_admin_notices', array( $this, 'display_downgrade_warning' ) );

	    if( $accept ){
		    update_network_option( null, 'msm_db_version', $to );
	    }
    }

	/**
	 * Show a notice to anyone who has just updated this plugin.
	 * This notice shouldn't display to anyone who has just installed the plugin for the first time
	 */
    public function display_update_notice() {

    	// Check the transient to see if we've just updated the plugin
		if ( $msg = get_site_transient( 'msm_plugin_updated_msg' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
		}
	}


	/**
	 * Show a notice to anyone who has downgraded the plugin.
	 * This notice shouldn't display to anyone who has just installed the plugin for the first time
	 */
	public function display_downgrade_warning() {

		// Check the transient to see if a downgrade took place
		if ( $msg = get_site_transient( 'msm_plugin_downgrade_msg' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p><p>(' . __( 'This notice won\'t disturb you more than 30 seconds', 'multisite-shared-media' ) . ')</p></div>';
		}
	}


	/**
	 * From version 1.1.0 the sharing is enabled/disabled per site.
	 * To make no difference, sharing must be enabled between all sites.
	 * Admin can then disable as he wants.
	 */
	private function share_all_sites(){
		$map = array();
		$sites = (array) MSMNetwork::instance()->get_site_ids();

		foreach ( $sites as $site ) {
			$key         = 'site_' . (string) $site;
			$map[ $key ] = array_values( array_diff( $sites, array( $site ) ) );
		}

		update_network_option( null, 'msm_relationships', $map );
		MSMNetwork::instance()->initialize();   // Reload network.
	}

}