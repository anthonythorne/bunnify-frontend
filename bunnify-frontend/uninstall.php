<?php
/**
 * Uninstall handler for Bunnify Frontend.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes every
 * plugin option and the debug log file. Multisite-aware.
 *
 * @package BunnifyFrontend
 */

declare( strict_types=1 );

// Exit if WordPress is not performing an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all Bunnify Frontend options and the debug log for the current site.
 *
 * @return void
 */
function bunnify_frontend_uninstall_site(): void {
	$options = array(
		'bunnify_enabled',
		'bunnify_hostname',
		'bunnify_default_quality',
		'bunnify_emit_dimensions',
		'bunnify_lcp_optimize',
		'bunnify_local_dev_mode',
		'bunnify_debug_enabled',
		'bunnify_debug_refreshes',
		'bunnify_debug_url_transformation',
		'bunnify_debug_image_processing',
		'bunnify_debug_srcset_generation',
		'bunnify_debug_content_filtering',
		'bunnify_debug_local_dev_mode',
		'bunnify_debug_performance',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove the debug log directory (uploads/bunnify-logs/) and its contents.
	$upload_dir = wp_get_upload_dir();
	if ( ! empty( $upload_dir['basedir'] ) ) {
		$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'bunnify-logs';
		if ( is_dir( $log_dir ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			if ( $wp_filesystem ) {
				// Recursive delete: log file, hardening files, then the directory.
				$wp_filesystem->delete( $log_dir, true );
			}
		}
	}
}

if ( is_multisite() ) {
	$bunnify_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $bunnify_site_ids as $bunnify_site_id ) {
		switch_to_blog( (int) $bunnify_site_id );
		bunnify_frontend_uninstall_site();
		restore_current_blog();
	}
} else {
	bunnify_frontend_uninstall_site();
}
