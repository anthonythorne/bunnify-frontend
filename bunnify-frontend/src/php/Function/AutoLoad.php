<?php
/**
 * Autoload function files.
 *
 * File Path: src/php/Function/AutoLoad.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 */

// phpcs:disable WordPress.Files.FileName

// File names in specific load order.
$specific_file_load_order = [];

// Cache the function files to avoid repeated glob() calls.
static $function_files = null;
if ( null === $function_files ) {
	$function_files = glob( __DIR__ . DIRECTORY_SEPARATOR . '*.php' );
}

// If no function files are found, return early.
if ( ! $function_files ) {
	return;
}

if ( $specific_file_load_order ) {
	// Load all files that have a order specified.
	foreach ( $specific_file_load_order as $function_file ) {

		$function_file = __DIR__ . DIRECTORY_SEPARATOR . $function_file . '.php';

		if ( file_exists( $function_file ) ) {
			require_once $function_file;
		}
	}
}


// Load all other files that do not have a specific order.
foreach ( $function_files as $function_file ) {

	$base_name = basename( $function_file );

	if ( ! $specific_file_load_order || ! in_array( $base_name, $specific_file_load_order, true ) ) {
		require_once $function_file;
	}
}
