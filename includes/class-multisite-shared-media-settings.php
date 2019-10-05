<?php
/**
 * Author:  Aikadesign Oy (JG) <tuki@aikadesign.fi>
 * Since:   2018/02
 */

/**
 * MSM Settings Page. Controls plugin settings.
 *
 */
final class MSMSettingsPage
{

	/**
	 * Holds the values to be used in the fields callbacks
	 */
	private $options;
	private $network;

    /**
     * Call this method to get singleton
     *
     * @return MSMSettingsPage
     */
    public static function instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new MSMSettingsPage();
        }
        return $inst;
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    private function __construct() {
	    $this->options = get_site_option( 'msm_sharing_settings' );
	    $this->network = MSMNetwork::instance();
    }


	/**
	 * Add options page to Wordpress Admin Menu
	 */
	public function add_plugin_page() {
		// This page will be under "Settings"
		add_submenu_page(
			'settings.php',
			__( 'Settings Admin', 'multisite-shared-media' ),
			__( 'Multisite Shared Media', 'multisite-shared-media' ),
			'manage_options',
			'msm-setting-admin',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Outputs the actual Settings Page.
	 */
	public function create_admin_page() {

		// Form action url
		$action_url    = esc_url(
			add_query_arg(
				'action',
				'msm_sharing_settings',
				network_admin_url( 'edit.php' )
			)
		);

		?>
        <div class="wrap">
            <h1><?php _e( 'Multisite Shared Media settings', 'multisite-shared-media' ); ?></h1>

            <?php
            // Navigation tabs
            $current_tab = ! empty( $_GET['tab'] ) ? esc_attr( $_GET['tab'] ) : 'general';
            $this->output_page_tabs( $current_tab );
            ?>

            <form method="post" action="<?php echo $action_url; ?>">
                <input type="hidden" name="tab" value="<?php echo $current_tab; ?>" />

                <?php

                if( 'general' === $current_tab ){
                    ?>
                    <h2><?php _e('General Settings', 'multisite-shared-media'); ?></h2>
                    <p>
                        <?php _e( 'Choose how the plugin is supposed to act upon media uploads and deletion', 'multisite-shared-media' ); ?>
                    </p>

                    <p>
                        <?php $this->field_share_media_cb(); ?>
                    </p>
                    <p>
                        <?php $this->field_delete_media_cb(); ?>
                    </p>
                    <?php
                    submit_button( null, 'primary', 'submit', false );

                } elseif ( 'relationships' === $current_tab ) {
                    ?>

                    <h2><?php echo __( 'Site Relationships', 'multisite-shared-media' ); ?></h2>
                    <p>
                        <?php _e( 'Choose which sites should share media between each other (both ways).', 'multisite-shared-media' ); ?>
                        <br/>
                        <?php _e( 'Only original media items will be shared to the other, which means that Media Item replicated from Site A to Site B won\'t appear to Site C if you decide to link sites B and C.', 'multisite-shared-media' ); ?>
                        <br/>
                        <?php _e( 'To get the Media Item from Site A to Site C, they must be linked with each other.', 'multisite-shared-media' ); ?>
                        <br/>
                    </p>
                    <br/>

                    <?php $this->site_relationship_matrix(); ?>
                    <br/>
                    <?php
                    submit_button( null, 'primary', 'submit', false );


                } elseif( 'replication' === $current_tab ){
                    ?>
                    <h2><?php _e( 'Replicate existing media', 'multisite-shared-media' ); ?></h2>

                    <p>
                        <?php _e( 'With this tool you can replicate media from one site to another. Select source site and target site, and click Replicate.', 'multisite-shared-media' ); ?>
                        <br/>
                        <?php _e( 'Only media which is not replicated yet, will be replicated. Also, only media which is originally uploaded to the source site will be replicated.', 'multisite-shared-media' ); ?>
                    </p>

                    <p>
                        <label for="msm-select-replication-source"><?php _e( 'Select Source', 'multisite-shared-media'); ?></label>
                        <select name="msm-replication-source" id="msm-select-replication-source">
                            <option>----</option>
                            <?php
                            foreach( (array) $this->network->get_sites() as $site ) {
                                echo '<option value="' . $site->blog_id . '">' . $site->blog_id . ': ' . $site->blogname . '</option>';
                            }
                            ?>
                        </select>

                        <label for="msm-select-replication-target"><?php _e( 'Select Target', 'multisite-shared-media' ); ?></label>
                        <select name="msm-replication-target" id="msm-select-replication-target">
                            <option>----</option>
                            <?php
                            foreach ( (array) $this->network->get_sites() as $site ) {
                                echo '<option value="' . $site->blog_id . '">' . $site->blog_id . ': ' . $site->blogname . '</option>';
                            }
                            ?>
                        </select>
                    </p>
                    <button type="button" name="replicate-all-existing" id="replicate-all-existing"
                        class="button button-primary" value="yes">
                        <?php echo __( 'Start replication process', 'multisite-shared-media' ); ?>
                    </button>
                    <p><em>
                            <?php _e( 'The process can take some time if you have lots of media. You can pause, resume and terminate the process at any time.', 'multisite-shared-media' ); ?>
                            <br/>
                            <?php _e( 'Next time the process will continue from where it left off.', 'multisite-shared-media' ); ?>
                    </em></p>
                    <?php
                }
                ?>
            </form>
        </div>
		<?php

        // The localized strings for Javascript use.
		$this->output_js_strings();
	}

	/**
	 * Checkbox for enabling/disabling the media sharing.
	 */
	public function field_share_media_cb() {
		$value = $this->options['msm_do_share_media'];
		printf(
			'<input type="checkbox" id="msm_do_share_media" name="msm_sharing_settings[msm_do_share_media]" value="yes" %s />',
			null !== $value && $value === 'yes' ? 'checked' : ''
		);
		echo '<label for="msm_do_share_media">' . __( 'Share media across network', 'multisite-shared-media' ) . '</label>';
	}

	/**
	 * Checkbox for enabling/disabling the 'shared deletion'. (deletion of an item deletes also all copies of it across the network)
	 */
	public function field_delete_media_cb() {
		$value = $this->options['msm_do_delete_shared_media'];
		printf(
			'<input type="checkbox" id="msm_do_delete_shared_media" name="msm_sharing_settings[msm_do_delete_shared_media]" value="yes" %s />',
			null !== $value && $value === 'yes' ? 'checked' : ''
		);
		echo '<label for="msm_do_delete_shared_media">' . __( 'Remove media from all sites upon media removal', 'multisite-shared-media' ) . '</label>';
	}

	/**
	 * The site relationship matrix, where admin chooses which sites share media with each other.
	 */
	private function site_relationship_matrix(){
	    $sites = (array) $this->network->get_sites();
        ?>
        <table class="msm-relationship-matrix">
            <thead>
            <tr>
                <th><?php _e( 'Site', 'multisite-shared-media' ); ?></th>
                <?php
                /** @var \WP_Site $site */
                foreach( $sites as $site ){
                    ?>
                    <th><div><span><?php echo $site->id . ': ' . substr( $site->blogname, 0, 30 ); ?><?php echo strlen($site->blogname) > 30 ? '...' : ''; ?></span></div></th>
                    <?php
                }
                ?>
            </tr>
            </thead>
            <tbody>
            <?php
            $site_count = count( $sites );

            for( $main_i = 0;  $main_i < $site_count; $main_i++ ){
                ?>
                <tr>
                    <th><?php echo $sites[ $main_i ]->id . ': ' . $sites[ $main_i ]->blogname; ?></th>
                    <?php
                    for( $sub_i = 0; $sub_i < $site_count; $sub_i++ ){
                        ?>
                        <td>
                            <?php if( $main_i > $sub_i ) {
                                $checked = $this->network->is_paired( $sites[ $main_i ]->id, $sites[ $sub_i ]->id ) ? 'checked="checked"' : '';
                                ?>
                                <input title="<?php printf( __( 'Share media between sites \'%s\' and \'%s\'?' ), $sites[ $main_i ]->blogname, $sites[ $sub_i ]->blogname ); ?>"
                                       type="checkbox" name="site_<?php echo $sites[ $main_i ]->id; ?>[]" value="<?php echo $sites[ $sub_i ]->id; ?>"
                                        <?php echo $checked; ?> />
                            <?php } ?>
                        </td>
                        <?php
                    }
                    ?>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
        <?php
    }


	/**
	 * The localized strings for Javascript use.
	 */
	private function output_js_strings() {
		?>
        <script type="text/javascript">
            var msm_strings = {
                'progress_heading': '<?php _e( 'Replication process', 'multisite-shared-media' ); ?>',
                'progress_total': '<?php _e( 'Total media:', 'multisite-shared-media' ); ?> <?php _e( 'calculating...', 'multisite-shared-media' ); ?>',
                'progress_remaining': '<?php _e( 'Remaining:', 'multisite-shared-media' ); ?> <?php _e( 'calculating...', 'multisite-shared-media' ); ?>',
                'confirm_termination': '<?php _e( 'Did you mean to terminate the process? No worries, you can continue it later from where you left off.', 'multisite-shared-media' ); ?>',
                'resume_btn_label': '<?php _e( 'Resume process', 'multisite-shared-media' ); ?>',
                'replication_success_msg': '<?php _e( 'Great, the replication process finished. Now go and check your Shared Media Library', 'multisite-shared-media' ); ?>',
                'close_btn_label': '<?php _e( 'Close window', 'multisite-shared-media' ); ?>',
                "replication_general_error_msg": '<?php _e( 'Sadly, the replication process failed. You can do troubleshooting by inspecting your browsers JS error log and servers PHP error log.', 'multisite-shared-media' ); ?>',
                'replication_illegal_pair_err_msg': '<?php _e( 'The selected sites are not allowed to share media with each other. Check your Site Relationships -tab.', 'multisite-shared-media' ); ?>',
                'replication_stagnated_err_msg': '<?php _e( 'Oops, seems like something is wrong, the process stagnated. You may find your browser JS error log or servers PHP error log useful.', 'multisite-shared-media' ); ?>',
                'replication_total_label': '<?php _e( 'Total media:', 'multisite-shared-media' ); ?>',
                'replication_files_label': '<?php _e( 'files', 'multisite-shared-media' ); ?>',
                'replication_remaining_label': '<?php _e( 'Remaining:', 'multisite-shared-media' ); ?>',
                'end_btn_label': '<?php _e( 'Terminate process', 'multisite-shared-media' ); ?>',
                'pause_btn_label': '<?php _e( 'Pause process', 'multisite-shared-media' ); ?>'
            }
        </script>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function page_init() {
		register_setting(
			'msm_option_group', // Option group
			'msm_sharing_settings', // Option name
			array( $this, 'sanitize' ) // Sanitize
		);

		add_settings_section(
			'msm_general_settings_section', // ID
			__( 'Network-wide settings', 'multisite-shared-media' ), // Title
			array( $this, 'print_section_info' ), // Callback
			'msm-setting-admin' // Page
		);

		add_settings_field(
			'msm_do_share_media', // ID
			__( 'Share media across network', 'multisite-shared-media' ), // Title
			array( $this, 'field_share_media_cb' ), // Callback
			'msm-setting-admin', // Page
			'msm_general_settings_section' // Section
		);

		add_settings_field(
			'msm_do_delete_shared_media',
			__( 'Remove media from all sites upon media removal', 'multisite-shared-media' ),
			array( $this, 'msm_do_delete_shared_media_callback' ),
			'msm-setting-admin',
			'msm_general_settings_section'
		);
	}


	/**
	 * Sanitize and save new settings. Process only settings from current tab. Ignore other fields.
     *
     * Note, this method redirects the client and exits script execution.
	 */
	public function msm_save_network_options() {

	    if( 'general' === $_POST['tab'] ) {

		    if ( isset( $_POST['msm_sharing_settings']['msm_do_share_media'] ) && $_POST['msm_sharing_settings']['msm_do_share_media'] === 'yes' ) {
			    $new_value['msm_do_share_media'] = 'yes';
            } else {
                $new_value['msm_do_share_media'] = 'no';
			}

		    if ( isset( $_POST['msm_sharing_settings']['msm_do_delete_shared_media'] ) && $_POST['msm_sharing_settings']['msm_do_delete_shared_media'] === 'yes' ) {
			    $new_value['msm_do_delete_shared_media'] = 'yes';
		    } else {
			    $new_value['msm_do_delete_shared_media'] = 'no';
		    }

		    update_site_option( 'msm_sharing_settings', $new_value );
	    }

		if( 'relationships' === $_POST['tab'] ) {
			$relationship_map = $this->parse_relationship_array();
			update_network_option( null, 'msm_relationships', $relationship_map );
		}

		// redirect to settings page in network
		wp_redirect(
			add_query_arg(
				array( 'page' => 'msm-setting-admin', 'updated' => 'true', 'tab' => $_POST['tab'] ),
				( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
			)
		);
		exit;
	}

	/**
	 * Sanitize each setting field as needed. Possibly deprecated in the future.
     * @see msm_save_network_options
     *
     * @param array $input Contains all settings fields as array keys
	 * @return array
	 */

	public function sanitize( $input ) {
		$new_input = array();

		if ( isset( $input['msm_do_share_media'] ) && $input['msm_do_share_media'] === 'yes' ) {
			$new_input['msm_do_share_media'] = 'yes';
		} else {
			$new_input['msm_do_share_media'] = 'no';
		}

		if ( isset( $input['msm_do_delete_shared_media'] ) && $input['msm_do_delete_shared_media'] === 'yes' ) {
			$new_input['msm_do_delete_shared_media'] = 'yes';
		} else {
			$new_input['msm_do_delete_shared_media'] = 'no';
		}

		return $new_input;
	}


	/**
	 * Construct array which describe the relationships between sites.
	 * @return array
	 */
	private function parse_relationship_array(){
	    $ret = array();

	    /* A to B */
	    foreach( (array) $this->network->get_sites() as $site_a ){
	        $key = 'site_' . (string) $site_a->id;
	        $ret[ $key ] = isset( $_POST[ $key ] ) ? $this->sanitize_site_ids( $_POST[ $key ] ) : array();
        }

        /* B to A */
        foreach( $ret as $site_a => $targets ){
            foreach( (array) $targets as $site_b ){
                $site_a_id = (int) substr( $site_a, strrpos( $site_a, '_' ) +1 );

                $ret[ 'site_' . $site_b ][] = $site_a_id;
            }
        }

        return $ret;
    }

	/**
	 * Sanitize site ids in an array. (site id = integer)
	 *
	 * @param $array
	 *
	 * @return array
	 */
    private function sanitize_site_ids( $array ){
	    $ret = array();
	    foreach( (array) $array as $id ){
            $ret[] = (int) $id;
        }
        return $ret;
    }


	/**
	 * The navigation tabs.
	 *
	 * @param string $current
	 */
    private function output_page_tabs( $current = 'general' ) {
        $tabs = array(
            'general'   => __( 'General settings', 'multisite-shared-media' ),
        );

	    if ( 'yes' === $this->options['msm_do_share_media'] ) {
		    $tabs['relationships']  = __( 'Site Relationships', 'multisite-shared-media' );
		    $tabs['replication']  = __( 'Media replication', 'multisite-shared-media' );
        }

        $html = '<h2 class="nav-tab-wrapper">';
        foreach( $tabs as $tab => $name ){
            $class = ( $tab === $current ) ? 'nav-tab-active' : '';
            $url = add_query_arg(
	            array( 'page' => 'msm-setting-admin', 'tab' => $tab ),
	            ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
            );

            $html .= '<a class="nav-tab ' . $class . '" href="' . $url . '">' . $name . '</a>';
        }
        $html .= '</h2>';
        echo $html;
    }
}