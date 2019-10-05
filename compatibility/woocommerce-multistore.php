<?php
/**
 * Created by PhpStorm.
 * User: johannes
 * Date: 4.4.2017
 * Time: 15:38
 */


/**
 * @param $attachment_id
 * @return mixed
 * Woocommerce Multistore handles product image synchronization. Skip all media files which have product as parent post.
 */
function msm_ignore_product_images( $attachment_id ){

    /* Ignore all media files submitted in product edit screen */
    if( 'editpost' === $_POST['action'] && 'product' === $_POST['post_type'] ){
        return false;
    }

    /* Ignore also if attachment parent is of type product */
    $parent = wp_get_post_parent_id( $attachment_id );
    $parent_type = ($parent > 0) ? get_post_type( $parent ) : 'undefined';

    $skipped_types = array( 'product', 'product_variation' );

    if( true === in_array( $parent_type, $skipped_types, true ) ) {
        return false;
    }

    return $attachment_id;
}


function msm_compat_wc_multistore() {
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-multistore/woocommerce-multistore.php' ) ) {
		add_filter( 'msm_filter_replicable_attachments', 'msm_ignore_product_images' );
	}
}
add_action( 'wp_loaded', 'msm_compat_wc_multistore' );

