<?php
/**
 * Uninstall cleanup for Social Preview Designer.
 *
 * Removes all plugin options, post meta, transients, and generated files
 * when the plugin is deleted from the Plugins screen.
 *
 * @package WPRemoteOG
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
$wp_remote_og_options = array(
	'wp_remote_og_template',
	'wp_remote_og_dynamic_fields',
	'wp_remote_og_fonts',
	'wp_remote_og_settings',
	'wp_remote_og_template_version',
	'wp_remote_og_template_needs_regeneration',
	'wp_remote_og_generation_log',
	'wp_remote_og_activation_error',
	'wp_remote_og_publishpress_avatar_token',
);

foreach ( $wp_remote_og_options as $wp_remote_og_option ) {
	delete_option( $wp_remote_og_option );
}

// Transients.
delete_transient( 'wp_remote_og_google_fonts_catalog' );

// Post meta.
$wp_remote_og_meta_keys = array(
	'_wp_remote_og_image_url',
	'_wp_remote_og_image_path',
	'_wp_remote_og_image_hash',
	'_wp_remote_og_generated_at',
	'_wp_remote_og_template_version',
);

foreach ( $wp_remote_og_meta_keys as $wp_remote_og_meta_key ) {
	delete_post_meta_by_key( $wp_remote_og_meta_key );
}

// Generated images and fonts in wp-content/uploads/wp-remote-og/.
$wp_remote_og_uploads = wp_upload_dir();
$wp_remote_og_dir     = trailingslashit( $wp_remote_og_uploads['basedir'] ) . 'wp-remote-og';
$wp_remote_og_base    = realpath( $wp_remote_og_dir );

if ( $wp_remote_og_base && is_dir( $wp_remote_og_base ) ) {
	$wp_remote_og_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $wp_remote_og_base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $wp_remote_og_iterator as $wp_remote_og_item ) {
		$wp_remote_og_path = $wp_remote_og_item->getRealPath();

		// Never touch anything outside the plugin's own upload directory.
		if ( ! $wp_remote_og_path || 0 !== strpos( $wp_remote_og_path, $wp_remote_og_base . DIRECTORY_SEPARATOR ) ) {
			continue;
		}

		if ( $wp_remote_og_item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty upload subdirectories on uninstall.
			rmdir( $wp_remote_og_path );
		} else {
			wp_delete_file( $wp_remote_og_path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own upload directory on uninstall.
	rmdir( $wp_remote_og_base );
}
