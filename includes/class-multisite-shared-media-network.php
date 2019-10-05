<?php
/**
 * Author:  Aikadesign Oy (JG) <tuki@aikadesign.fi>
 * Since:   2018/02
 */


/**
 * Class MSMNetwork. Provides information about network sites and their relationships.
 *
 */
final class MSMNetwork
{

	/**
	 * @var array
	 */
	private $sites;

	/**
	 * @var array
	 */
	private $map;

    /**
     * Call this method to get singleton
     *
     * @return MSMNetwork
     */
    public static function instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new MSMNetwork();
        }
        return $inst;
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    private function __construct()
    {
    	$this->initialize();
    }


	/**
	 * Load sites and relationships. Can be used to refresh network specs.
	 */
    public function initialize(){
	    $this->sites = get_sites();
	    $this->map   = get_network_option( null, 'msm_relationships', array() );
    }


	/**
	 * Get array of ids of all sites which are not main site.
	 * @return array of ids
	 */
    public function get_subsites(){
    	return wp_list_pluck( wp_list_filter( $this->sites, array( 'blog_id' => BLOG_ID_CURRENT_SITE ), 'NOT' ), 'blog_id' );
    }

	/**
	 * Get array of sites which share media with the given site.
	 *
	 * @param $site_id
	 *
	 * @return array
	 */
    public function get_sites_synced_with( $site_id ){
		return (array) $this->map[ 'site_' . $site_id ];
    }

	/**
	 * Count of all network sites together
	 * @return int
	 */
    public function count_sites(){
    	return count( $this->sites );
    }

	/**
	 * Get list of sites
	 * @return array
	 */
    public function get_sites(){
    	return $this->sites;
    }

	/**
	 * Check if given sites share media with each other.
	 *
	 * @param $site1
	 * @param $site2
	 *
	 * @return bool
	 */
    public function is_paired( $site1, $site2 ){
    	$site1 = 'site_' . $site1;
    	return isset( $this->map[ $site1 ] ) && in_array( $site2, $this->map[ $site1 ], true );
    }


	/**
	 * Get list of site ids
	 * @return array
	 */
	public function get_site_ids() {
		$arr = wp_list_pluck( $this->sites, 'blog_id' );
		$c   = count( $arr );
		for ( $i = 0; $i < $c; $i ++ ) {
			$arr[ $i ] = (int) $arr[ $i ];
		}

		return $arr;
	}

}