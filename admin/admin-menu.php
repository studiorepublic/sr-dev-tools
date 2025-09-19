<?php
/**
 * Get the sync path for exports
 *
 * @package   SR Dev Tools
 * @author    Chris Todhunter
 * @since     1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Register the admin menu for SR Dev Tools
 * 
 * @since  1.0.0
 * @return void
 */
function srdt_register_admin_menu() {
	add_menu_page(
		esc_html__( 'SR Dev Tools', 'srdt' ),
		esc_html__( 'SR Dev Tools', 'srdt' ),
		'SR',
		'sr-dev-tools',
		'srdt_render_export_page',
		'dashicons-download',
		80
	);
}
add_action( 'admin_menu', 'srdt_register_admin_menu' );
