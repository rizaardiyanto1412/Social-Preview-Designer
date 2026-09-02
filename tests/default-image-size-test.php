<?php
// phpcs:ignoreFile
/**
 * Focused WP-CLI test for the configurable generated image dimensions.
 *
 * Run from the WordPress root:
 * wp eval-file wp-content/plugins/wp-remote-og-plugins/tests/default-image-size-test.php
 *
 * @package WPRemoteOG
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "This test file must be run through WordPress.\n";
	exit( 1 );
}

if ( ! class_exists( 'WP_Remote_OG_Plugin' ) ) {
	require_once dirname( __DIR__ ) . '/wp-remote-og-plugins.php';
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$passed = 0;
$failed = 0;

$assert = static function ( $condition, $message ) use ( &$passed, &$failed ) {
	if ( $condition ) {
		$passed++;
		echo "PASS: {$message}\n";
		return;
	}

	$failed++;
	echo "FAIL: {$message}\n";
};

$admin_id = username_exists( 'admin' );
if ( $admin_id ) {
	wp_set_current_user( $admin_id );
}

WP_Remote_OG_Plugin::activate();
$previous_settings = get_option( WP_Remote_OG_Plugin::OPTION_SETTINGS, false );
$post_id           = wp_insert_post(
	array(
		'post_title'  => 'Default image size test post',
		'post_status' => 'draft',
		'post_type'   => 'post',
	),
	true
);

if ( ! method_exists( 'WP_Remote_OG_Plugin', 'save_settings' ) ) {
	$assert( false, 'Saving the default image size setting changes generated dimensions.' );
} else {
	WP_Remote_OG_Plugin::save_settings( array( 'default_image_size' => '720x378' ) );
	$result = WP_Remote_OG_Generator::generate_for_post( $post_id, array( 'engine' => 'gd' ) );
	$size   = ! is_wp_error( $result ) && file_exists( $result['path'] ) ? getimagesize( $result['path'] ) : false;
	$assert( $size && 720 === $size[0] && 378 === $size[1], 'Saving the default image size setting changes generated dimensions.' );

	WP_Remote_OG_Plugin::save_settings( array( 'default_image_size' => 'not-a-size' ) );
	$dimensions = WP_Remote_OG_Plugin::get_image_dimensions();
	$assert( 1200 === $dimensions['width'] && 630 === $dimensions['height'], 'Invalid image size settings preserve the legacy 1200x630 default.' );
}

if ( ! method_exists( 'WP_Remote_OG_Admin', 'render_settings_page' ) ) {
	$assert( false, 'Administrator settings expose the current and 720x378 image size presets.' );
} else {
	ob_start();
	WP_Remote_OG_Admin::render_settings_page();
	$settings_page_html = ob_get_clean();
	$assert(
		false !== strpos( $settings_page_html, 'value="1200x630"' ) && false !== strpos( $settings_page_html, 'value="720x378"' ),
		'Administrator settings expose the current and 720x378 image size presets.'
	);
}
$assert( false !== has_action( 'admin_post_wp_remote_og_save_settings' ), 'Administrator settings register a nonce-protected save action.' );

WP_Remote_OG_Plugin::save_settings( array( 'default_image_size' => '720x378' ) );
$oembed = WP_Remote_OG_SEO::filter_oembed_thumbnail( array(), get_post( $post_id ) );
$assert( 720 === $oembed['thumbnail_width'] && 378 === $oembed['thumbnail_height'], 'oEmbed image dimensions follow the selected generated image size.' );

if ( $post_id && get_post( $post_id ) ) {
	wp_delete_post( $post_id, true );
}

if ( false === $previous_settings ) {
	delete_option( WP_Remote_OG_Plugin::OPTION_SETTINGS );
} else {
	update_option( WP_Remote_OG_Plugin::OPTION_SETTINGS, $previous_settings, false );
}

echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ( $failed > 0 ) {
	exit( 1 );
}
