<?php
// phpcs:ignoreFile
/**
 * WP-CLI integration tests for WP Remote OG Images.
 *
 * Run from the WordPress root:
 * wp eval-file wp-content/plugins/wp-remote-og-plugins/tests/run-tests.php
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

$GLOBALS['wp_remote_og_test_results'] = array(
	'passed' => 0,
	'failed' => 0,
	'notes'  => array(),
);

function wp_remote_og_assert( $condition, $message ) {
	if ( $condition ) {
		$GLOBALS['wp_remote_og_test_results']['passed']++;
		echo "PASS: {$message}\n";
		return;
	}

	$GLOBALS['wp_remote_og_test_results']['failed']++;
	echo "FAIL: {$message}\n";
}

function wp_remote_og_note( $message ) {
	$GLOBALS['wp_remote_og_test_results']['notes'][] = $message;
	echo "NOTE: {$message}\n";
}

function wp_remote_og_pixel_hex( $path, $x, $y ) {
	if ( ! function_exists( 'imagecreatefrompng' ) ) {
		return '';
	}

	$image = imagecreatefrompng( $path );
	if ( ! $image ) {
		return '';
	}

	$index = imagecolorat( $image, $x, $y );
	$color = imagecolorsforindex( $image, $index );
	imagedestroy( $image );

	return sprintf( '#%02x%02x%02x', $color['red'], $color['green'], $color['blue'] );
}

function wp_remote_og_make_post( $title, $status = 'publish' ) {
	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'WP Remote OG test content.',
			'post_status'  => $status,
			'post_type'    => 'post',
		),
		true
	);

	wp_remote_og_assert( ! is_wp_error( $post_id ) && $post_id > 0, "Created test post: {$title}" );
	return is_wp_error( $post_id ) ? 0 : (int) $post_id;
}

$admin_id = username_exists( 'admin' );
if ( $admin_id ) {
	wp_set_current_user( $admin_id );
}

WP_Remote_OG_Plugin::activate();

echo "WP Remote OG integration test run\n";
echo "=================================\n";

wp_remote_og_assert( class_exists( 'WP_Remote_OG_Plugin' ), 'Plugin bootstrap class is loaded.' );

$directory = WP_Remote_OG_Uploads::ensure_directory();
wp_remote_og_assert( ! is_wp_error( $directory ) && file_exists( $directory['path'] ), 'Upload directory is created.' );
wp_remote_og_assert( ! is_wp_error( $directory ) && wp_is_writable( $directory['path'] ), 'Upload directory is writable.' );

$diagnostics = WP_Remote_OG_Diagnostics::get();
wp_remote_og_assert( array_key_exists( 'imagick', $diagnostics ), 'Diagnostics include Imagick availability.' );
wp_remote_og_assert( array_key_exists( 'gd', $diagnostics ), 'Diagnostics include GD availability.' );
wp_remote_og_assert( array_key_exists( 'rank_math', $diagnostics ), 'Diagnostics include Rank Math availability.' );
wp_remote_og_assert( array_key_exists( 'acf', $diagnostics ), 'Diagnostics include ACF availability.' );
wp_remote_og_assert( array_key_exists( 'upload_writable', $diagnostics ), 'Diagnostics include upload writability.' );

do_action( 'admin_menu' );
global $submenu;
$registered_pages = isset( $submenu['wp-remote-og-dashboard'] ) ? wp_list_pluck( $submenu['wp-remote-og-dashboard'], 2 ) : array();
wp_remote_og_assert( in_array( 'wp-remote-og-dashboard', $registered_pages, true ), 'Dashboard admin page is registered on its distinct top-level slug.' );
wp_remote_og_assert( ! in_array( 'wp-remote-og', $registered_pages, true ), 'The legacy bare "wp-remote-og" slug is no longer a live page (aliased to the editor).' );
wp_remote_og_assert( 'wp-remote-og-editor' === WP_Remote_OG_Admin::EDITOR_SLUG && 'wp-remote-og' === WP_Remote_OG_Admin::LEGACY_EDITOR_SLUG, 'Legacy editor bookmarks redirect from the old slug to the editor slug.' );
wp_remote_og_assert( in_array( 'wp-remote-og-editor', $registered_pages, true ), 'Template Editor admin page is registered on the new slug.' );
wp_remote_og_assert( in_array( 'wp-remote-og-templates', $registered_pages, true ), 'Templates gallery admin page is registered.' );
wp_remote_og_assert( in_array( 'wp-remote-og-fields', $registered_pages, true ), 'Dynamic Fields admin page is registered.' );
wp_remote_og_assert( in_array( 'wp-remote-og-fonts', $registered_pages, true ), 'Fonts admin page is registered.' );
wp_remote_og_assert( in_array( 'wp-remote-og-tools', $registered_pages, true ), 'Generation Tools admin page is registered.' );
wp_remote_og_assert( in_array( 'wp-remote-og-diagnostics', $registered_pages, true ), 'Diagnostics admin page is registered.' );

ob_start();
WP_Remote_OG_Admin::render_template_page();
$template_page_html = ob_get_clean();
wp_remote_og_assert( false !== strpos( $template_page_html, 'wp-remote-og-add-horizontal-line' ), 'Template editor exposes an Add Horizontal Line button.' );
wp_remote_og_assert( false !== strpos( $template_page_html, 'wp-remote-og-add-vertical-line' ), 'Template editor exposes an Add Vertical Line button.' );
wp_remote_og_assert( false !== strpos( $template_page_html, 'wp-remote-og-layer-line-orientation' ), 'Template editor exposes line orientation controls.' );
wp_remote_og_assert( false !== strpos( $template_page_html, 'wp-remote-og-layer-image-fit' ), 'Template editor exposes image fit controls.' );
wp_remote_og_assert( false !== strpos( $template_page_html, 'wpog-inspector' ), 'Template editor renders the redesigned inspector.' );

ob_start();
WP_Remote_OG_Admin::render_dashboard_page();
$dashboard_html = ob_get_clean();
wp_remote_og_assert( false !== strpos( $dashboard_html, 'wpog-checklist' ), 'Dashboard renders the readiness checklist.' );
wp_remote_og_assert( false !== strpos( $dashboard_html, 'Generation health' ), 'Dashboard renders the generation health section.' );
wp_remote_og_assert( false !== strpos( $dashboard_html, 'wpog-check-item' ), 'Dashboard renders individual readiness markers.' );

ob_start();
WP_Remote_OG_Admin::render_templates_page();
$templates_html = ob_get_clean();
wp_remote_og_assert( false !== strpos( $templates_html, 'wp-remote-og-gallery' ), 'Templates page renders the gallery container.' );
wp_remote_og_assert( false !== strpos( $templates_html, 'wpog-filter-pill' ), 'Templates page renders category filter pills.' );

$presets = WP_Remote_OG_Presets::all();
wp_remote_og_assert( is_array( $presets ) && count( $presets ) >= 8, 'Preset registry returns at least 8 presets.' );

$preset_dir = WP_Remote_OG_Uploads::ensure_directory();
$preset_all_ok = true;
$preset_render_ok = true;
foreach ( $presets as $preset ) {
	$resanitized = WP_Remote_OG_Plugin::sanitize_template( $preset['template'] );
	if ( wp_json_encode( $resanitized ) !== wp_json_encode( $preset['template'] ) ) {
		$preset_all_ok = false;
		wp_remote_og_note( 'Preset not idempotent under sanitize_template: ' . $preset['key'] );
	}

	if ( ! is_wp_error( $preset_dir ) ) {
		$preset_path = trailingslashit( $preset_dir['path'] ) . 'wp-remote-og-preset-' . $preset['key'] . '.png';
		$preset_rendered = WP_Remote_OG_Renderer::render_post( 0, $preset['template'], $preset_path, 'gd' );
		$preset_size = ( ! is_wp_error( $preset_rendered ) && file_exists( $preset_path ) ) ? getimagesize( $preset_path ) : false;
		if ( is_wp_error( $preset_rendered ) || ! $preset_size || 1200 !== $preset_size[0] || 630 !== $preset_size[1] ) {
			$preset_render_ok = false;
			wp_remote_og_note( 'Preset failed to render at 1200x630: ' . $preset['key'] );
		}
		if ( file_exists( $preset_path ) ) {
			WP_Remote_OG_Uploads::safe_delete( $preset_path );
		}
	}
}
wp_remote_og_assert( $preset_all_ok, 'Every preset passes sanitize_template() unchanged (deep compare).' );
wp_remote_og_assert( $preset_render_ok, 'Every preset renders via GD to exactly 1200x630.' );
wp_remote_og_assert( null === WP_Remote_OG_Presets::get( 'no-such-preset-key' ), 'Unknown preset key is rejected by the registry.' );
wp_remote_og_assert( is_array( WP_Remote_OG_Presets::get( $presets[0]['key'] ) ), 'Known preset key resolves from the registry.' );

// apply_preset requires an authorized user: a subscriber must fail the capability gate that verify_ajax() enforces.
$preset_subscriber_id = wp_insert_user(
	array(
		'user_login' => 'wp_remote_og_preset_sub_' . time(),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wp-remote-og-preset-sub-' . time() . '@example.test',
		'role'       => 'subscriber',
	)
);
if ( ! is_wp_error( $preset_subscriber_id ) ) {
	wp_set_current_user( $preset_subscriber_id );
	wp_remote_og_assert( ! WP_Remote_OG_Plugin::can_manage(), 'apply_preset capability gate rejects users lacking edit_others_posts.' );
	wp_delete_user( $preset_subscriber_id );
}
wp_set_current_user( $admin_id );
wp_remote_og_assert( (bool) wp_verify_nonce( wp_create_nonce( 'wp_remote_og_admin' ), 'wp_remote_og_admin' ), 'apply_preset shares the nonce action verified by verify_ajax().' );

// Exercise the actual, extracted handler core logic (WP_Remote_OG_Admin::apply_preset
// / ::restore_template_backup) instead of duplicating it. The AJAX wrappers only
// add the shared verify_ajax() security gate on top of these methods.
delete_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY );
delete_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );
$pre_apply_template = WP_Remote_OG_Plugin::get_template();

$apply_unknown = WP_Remote_OG_Admin::apply_preset( 'no-such-preset-key' );
wp_remote_og_assert( is_wp_error( $apply_unknown ), 'apply_preset core rejects an unknown preset key.' );

$apply_result = WP_Remote_OG_Admin::apply_preset( $presets[0]['key'] );
$applied_backup = get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );
wp_remote_og_assert( is_array( $apply_result ) && ! empty( $apply_result['template'] ), 'apply_preset core returns the applied template.' );
wp_remote_og_assert( get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ), 'Applying a preset marks the template dirty for regeneration.' );
wp_remote_og_assert( is_array( $applied_backup ) && ! empty( $applied_backup['template'] ), 'Applying a preset backs up the previous template.' );
wp_remote_og_assert( wp_json_encode( $applied_backup['template'] ) === wp_json_encode( $pre_apply_template ), 'The stored backup matches the pre-apply template.' );

// Preserve-first: applying a SECOND preset must NOT overwrite the original backup.
WP_Remote_OG_Admin::apply_preset( $presets[1]['key'] );
$second_backup = get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );
wp_remote_og_assert( wp_json_encode( $second_backup['template'] ) === wp_json_encode( $pre_apply_template ), 'A second preset apply preserves the ORIGINAL template backup (preserve-first).' );

// Restoring via the core handler returns the original template and clears the backup.
$restore_result = WP_Remote_OG_Admin::restore_template_backup();
wp_remote_og_assert( is_array( $restore_result ) && ! empty( $restore_result['template'] ), 'restore_template_backup core returns a template.' );
wp_remote_og_assert( wp_json_encode( WP_Remote_OG_Plugin::get_template() ) === wp_json_encode( $pre_apply_template ), 'Restoring the backup returns the original pre-preset template.' );
wp_remote_og_assert( false === get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP, false ), 'Restoring the backup clears the stored backup option.' );
wp_remote_og_assert( is_wp_error( WP_Remote_OG_Admin::restore_template_backup() ), 'restore_template_backup core errors when no backup exists.' );
$admin_css = file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' );
wp_remote_og_assert( (bool) preg_match( '/\.wp-remote-og-layer-text\s*\{[^}]*width:\s*100%;/s', $admin_css ), 'Editor preview text span fills the layer width for alignment.' );
$plugin_source = file_get_contents( dirname( __DIR__ ) . '/wp-remote-og-plugins.php' );
wp_remote_og_assert( (bool) preg_match( '/\$font_size\s*=\s*\(float\)\s*\$lines\[[\'"]font_size[\'"]\];\s*\$draw->setFontSize\(\s*\$font_size\s*\);/s', $plugin_source ), 'Imagick text drawing uses the fitted font size for alignment.' );

$subscriber_id = wp_insert_user(
	array(
		'user_login' => 'wp_remote_og_subscriber_' . time(),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wp-remote-og-subscriber-' . time() . '@example.test',
		'role'       => 'subscriber',
	)
);
if ( ! is_wp_error( $subscriber_id ) ) {
	wp_set_current_user( $subscriber_id );
	wp_remote_og_assert( ! WP_Remote_OG_Plugin::can_manage(), 'Non-admin/subscriber users cannot manage plugin screens.' );
	wp_delete_user( $subscriber_id );
}
wp_set_current_user( $admin_id );

register_taxonomy( 'job_location', 'post', array( 'public' => true ) );
$term = term_exists( 'Remote - Asia Pacific', 'job_location' );
if ( ! $term ) {
	$term = wp_insert_term( 'Remote - Asia Pacific', 'job_location' );
}
wp_remote_og_assert( ! is_wp_error( $term ) && ! empty( $term ), 'Registered and created custom taxonomy term.' );

$post_id = wp_remote_og_make_post( 'Senior WordPress Engineer With A Very Long Title For Social Preview Fitting' );
wp_set_object_terms( $post_id, array( 'Remote - Asia Pacific' ), 'job_location' );
update_post_meta( $post_id, 'company_name', 'WP Remote Work Co' );

$encoded_title_post_id = wp_remote_og_make_post( 'Senior Solutions Architect &#8211; AI &amp; Platforms SME' );

$fields = WP_Remote_OG_Plugin::save_dynamic_fields(
	array(
		array(
			'token'    => '{post_title}',
			'label'    => 'Post Title',
			'fallback' => '',
			'enabled'  => 1,
		),
		array(
			'token'    => '{taxonomy:job_location}',
			'label'    => 'Job Location',
			'fallback' => 'Remote',
			'enabled'  => 1,
		),
		array(
			'token'    => '{acf:company_name}',
			'label'    => 'Company',
			'fallback' => 'Company unavailable',
			'enabled'  => 1,
		),
		array(
			'token'    => '{acf:missing_salary}',
			'label'    => 'Salary',
			'fallback' => 'Salary unavailable',
			'enabled'  => 1,
		),
	)
);
wp_remote_og_assert( count( $fields ) >= 4, 'Dynamic field settings save and persist.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $post_id, '{post_title}' ) === get_the_title( $post_id ), '{post_title} resolves correctly.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $encoded_title_post_id, '{post_title}' ) === 'Senior Solutions Architect – AI & Platforms SME', '{post_title} decodes stored HTML entities.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $post_id, '{taxonomy:job_location}' ) === 'Remote - Asia Pacific', 'Taxonomy token resolves correctly.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $post_id, '{acf:company_name}' ) === 'WP Remote Work Co', 'ACF-style token resolves from post meta when ACF is inactive.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $post_id, '{acf:missing_salary}' ) === 'Salary unavailable', 'Missing ACF field returns fallback.' );
wp_remote_og_assert( WP_Remote_OG_Dynamic_Fields::resolve_token( $post_id, '{bad_token}' ) === '', 'Invalid token format is ignored safely.' );

$font_path = WP_Remote_OG_Fonts::get_renderable_font_path();
if ( $font_path ) {
	$font = WP_Remote_OG_Fonts::register_font_from_path( $font_path, 'WP Remote Test Font' );
	wp_remote_og_assert( ! is_wp_error( $font ) && ! empty( $font['id'] ), 'Valid font registration succeeds.' );
	wp_remote_og_assert( ! empty( WP_Remote_OG_Fonts::get_font_by_id( $font['id'] ) ), 'Uploaded font metadata persists.' );
} else {
	$font = array( 'id' => '' );
	wp_remote_og_note( 'No local TTF/OTF font found; custom font rendering test uses system fallback.' );
}
$bad_font = WP_Remote_OG_Fonts::register_font_from_path( __FILE__, 'Invalid PHP Font' );
wp_remote_og_assert( is_wp_error( $bad_font ), 'Invalid font file extension is rejected.' );
$google_font_css = "@font-face { font-family: 'Monda'; src: url(https://fonts.gstatic.com/s/monda/v19/example.ttf) format('truetype'); }";
wp_remote_og_assert( 'https://fonts.gstatic.com/s/monda/v19/example.ttf' === WP_Remote_OG_Fonts::google_font_ttf_url_from_css( $google_font_css ), 'Google font CSS parser finds a renderable TTF source.' );

$template = WP_Remote_OG_Plugin::save_template(
	array(
		'background' => array( 'id' => 0, 'url' => '' ),
		'layers'     => array(
			array(
				'id'            => 'layer-title',
				'type'          => 'text',
				'content'       => '{post_title}',
				'label'         => 'Title',
				'x'             => 90,
				'y'             => 90,
				'width'         => 1020,
				'height'        => 250,
				'font_id'       => isset( $font['id'] ) ? $font['id'] : '',
				'font_size'     => 72,
				'min_font_size' => 32,
				'color'         => '#111827',
				'align'         => 'left',
				'line_height'   => 1.08,
				'max_lines'     => 3,
			),
			array(
				'id'            => 'layer-meta',
				'type'          => 'text',
				'content'       => '{acf:company_name} — {taxonomy:job_location} — {acf:missing_salary}',
				'label'         => 'Meta',
				'x'             => 90,
				'y'             => 390,
				'width'         => 1020,
				'height'        => 120,
				'font_id'       => '',
				'font_size'     => 34,
				'min_font_size' => 22,
				'color'         => '#1f2937',
				'align'         => 'left',
				'line_height'   => 1.15,
				'max_lines'     => 2,
			),
			array(
				'id'               => 'layer-horizontal-line',
				'type'             => 'line',
				'label'            => 'Divider',
				'x'                => 90,
				'y'                => 540,
				'width'            => 1020,
				'height'           => 6,
				'color'            => '#ef4444',
				'line_orientation' => 'horizontal',
			),
			array(
				'id'               => 'layer-vertical-line',
				'type'             => 'line',
				'label'            => 'Side rule',
				'x'                => 60,
				'y'                => 90,
				'width'            => 5,
				'height'           => 420,
				'color'            => '#0f766e',
				'line_orientation' => 'vertical',
			),
		),
	)
);
wp_remote_og_assert( get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ), 'Template change marks regeneration notice as pending.' );

$sanitized = WP_Remote_OG_Plugin::sanitize_template(
	array(
		'layers' => array(
			array(
				'id'            => 'bad',
				'content'       => '{post_title}',
				'x'             => -999,
				'y'             => 9999,
				'width'         => 99999,
				'height'        => -1,
				'font_size'     => 999,
				'min_font_size' => -10,
				'color'         => 'bad',
				'align'         => 'sideways',
			),
			array(
				'id'               => 'line-bad',
				'type'             => 'line',
				'content'          => '{post_title}',
				'width'            => 0,
				'height'           => 0,
				'color'            => '#ef4444',
				'line_orientation' => 'sideways',
			),
		),
	)
);
$layer = $sanitized['layers'][0];
wp_remote_og_assert( 0.0 === $layer['x'] && 630.0 === $layer['y'], 'Template coordinates are clamped.' );
wp_remote_og_assert( 1200.0 === $layer['width'] && 20.0 === $layer['height'], 'Template dimensions are clamped.' );
wp_remote_og_assert( '#111827' === $layer['color'] && 'left' === $layer['align'], 'Invalid style values are sanitized.' );
$line_layer = $sanitized['layers'][1];
wp_remote_og_assert( 'line' === $line_layer['type'] && 'horizontal' === $line_layer['line_orientation'], 'Line layer type and orientation are sanitized.' );
wp_remote_og_assert( '' === $line_layer['content'] && 20.0 === $line_layer['width'] && 1.0 === $line_layer['height'], 'Line layer content and thin dimensions are sanitized.' );

$sanitized_image = WP_Remote_OG_Plugin::sanitize_template(
	array(
		'layers' => array(
			array(
				'id'                 => 'image-fit',
				'type'               => 'image',
				'content'            => 'https://example.test/logo.png',
				'image_fit'          => 'bad-fit',
				'image_aspect_ratio' => 99999,
			),
		),
	)
);
$image_layer = $sanitized_image['layers'][0];
wp_remote_og_assert( 'contain' === $image_layer['image_fit'], 'Invalid image fit falls back to contain.' );
wp_remote_og_assert( 100.0 === $image_layer['image_aspect_ratio'], 'Image aspect ratio is clamped.' );

$preview = WP_Remote_OG_Dynamic_Fields::preview_data( $post_id, $template );
wp_remote_og_assert( ! is_wp_error( $preview ) && false !== strpos( $preview['layers']['layer-title']['resolved'], 'Senior WordPress Engineer' ), 'Preview data resolves selected real post values.' );
$encoded_preview = WP_Remote_OG_Dynamic_Fields::preview_data( $encoded_title_post_id, $template );
wp_remote_og_assert( ! is_wp_error( $encoded_preview ) && 'Senior Solutions Architect – AI & Platforms SME' === $encoded_preview['layers']['layer-title']['resolved'], 'Preview data sends decoded post titles to the editor.' );
$invalid_preview = WP_Remote_OG_Dynamic_Fields::preview_data( 999999999, $template );
wp_remote_og_assert( is_wp_error( $invalid_preview ), 'Preview rejects nonexistent post IDs.' );

$render_dir  = WP_Remote_OG_Uploads::ensure_directory();
$render_path = trailingslashit( $render_dir['path'] ) . 'wp-remote-og-render-test.png';
$rendered    = WP_Remote_OG_Renderer::render_post( $post_id, $template, $render_path, 'gd' );
wp_remote_og_assert( ! is_wp_error( $rendered ) && file_exists( $render_path ), 'GD renderer creates a PNG file.' );
$size = file_exists( $render_path ) ? getimagesize( $render_path ) : false;
wp_remote_og_assert( $size && 1200 === $size[0] && 630 === $size[1], 'Rendered PNG is exactly 1200x630.' );
wp_remote_og_assert( '#ef4444' === wp_remote_og_pixel_hex( $render_path, 100, 543 ), 'GD renderer paints horizontal line layers.' );
wp_remote_og_assert( '#0f766e' === wp_remote_og_pixel_hex( $render_path, 62, 100 ), 'GD renderer paints vertical line layers.' );
WP_Remote_OG_Uploads::safe_delete( $render_path );

$source_path = trailingslashit( $render_dir['path'] ) . 'wp-remote-og-wide-source.png';
$contain_path = trailingslashit( $render_dir['path'] ) . 'wp-remote-og-contain-test.png';
$source_image = imagecreatetruecolor( 100, 20 );
$red = imagecolorallocate( $source_image, 220, 0, 0 );
imagefilledrectangle( $source_image, 0, 0, 99, 19, $red );
imagepng( $source_image, $source_path );
imagedestroy( $source_image );

$source_url = trailingslashit( $render_dir['url'] ) . basename( $source_path );
$contain_rendered = WP_Remote_OG_Renderer::render_post(
	$post_id,
	array(
		'layers' => array(
			array(
				'id'        => 'wide-logo',
				'type'      => 'image',
				'content'   => $source_url,
				'x'         => 0,
				'y'         => 0,
				'width'     => 100,
				'height'    => 100,
				'image_fit' => 'contain',
			),
		),
	),
	$contain_path,
	'gd'
);
wp_remote_og_assert( ! is_wp_error( $contain_rendered ) && '#dc0000' !== wp_remote_og_pixel_hex( $contain_path, 50, 5 ) && '#dc0000' === wp_remote_og_pixel_hex( $contain_path, 50, 50 ), 'Contain image fit preserves a wide image without stretching to the full box.' );
WP_Remote_OG_Uploads::safe_delete( $source_path );
WP_Remote_OG_Uploads::safe_delete( $contain_path );

$first = WP_Remote_OG_Generator::generate_for_post( $post_id, array( 'engine' => 'gd' ) );
wp_remote_og_assert( ! is_wp_error( $first ) && file_exists( $first['path'] ), 'Generated image file exists.' );
wp_remote_og_assert( get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true ) === $first['url'], 'Post meta stores active image URL.' );
wp_remote_og_assert( preg_match( '/post-' . $post_id . '-og-[a-f0-9]{8}\.png$/', basename( $first['path'] ) ), 'Generated filename is versioned.' );
$second = WP_Remote_OG_Generator::generate_for_post( $post_id, array( 'engine' => 'gd' ) );
wp_remote_og_assert( ! is_wp_error( $second ) && $first['path'] !== $second['path'], 'Regeneration creates a new filename.' );
wp_remote_og_assert( ! file_exists( $first['path'] ) && file_exists( $second['path'] ), 'Previous generated image is deleted after regeneration.' );
wp_remote_og_assert( ! WP_Remote_OG_Uploads::safe_delete( ABSPATH . 'wp-config.php' ), 'Safe deletion refuses files outside plugin upload directory.' );

$auto_post_id = wp_remote_og_make_post( 'Auto Generation Test Post' );
$auto_url     = get_post_meta( $auto_post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true );
wp_remote_og_assert( ! empty( $auto_url ), 'Publishing a post automatically generates an image.' );
wp_update_post(
	array(
		'ID'         => $auto_post_id,
		'post_title' => 'Auto Generation Test Post Updated',
	)
);
$updated_url = get_post_meta( $auto_post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true );
wp_remote_og_assert( ! empty( $updated_url ) && $updated_url !== $auto_url, 'Updating a published post regenerates its image.' );
$page_id = wp_insert_post(
	array(
		'post_title'  => 'Page Should Not Generate',
		'post_type'   => 'page',
		'post_status' => 'publish',
	)
);
wp_remote_og_assert( '' === get_post_meta( $page_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true ), 'Auto-generation does not run for non-post post types.' );

$all_ids     = WP_Remote_OG_Generator::bulk_post_ids( 'all' );
$missing_ids = WP_Remote_OG_Generator::bulk_post_ids( 'missing' );
wp_remote_og_assert( in_array( $post_id, $all_ids, true ), 'Bulk regenerate all includes published posts.' );
wp_remote_og_assert( is_array( $missing_ids ), 'Bulk missing query returns an ID list.' );

$rank_math_filtered = WP_Remote_OG_SEO::filter_rank_math_image_for_post( 'https://example.test/old.png', $post_id );
wp_remote_og_assert( $rank_math_filtered === $second['url'], 'Rank Math image filter returns generated URL for posts with an image.' );

$orphan_path = trailingslashit( $render_dir['path'] ) . 'post-999999-og-deadbeef.png';
file_put_contents( $orphan_path, 'orphan' );
$cleanup = WP_Remote_OG_Uploads::cleanup_orphans();
wp_remote_og_assert( ! is_wp_error( $cleanup ) && $cleanup['deleted'] >= 1 && ! file_exists( $orphan_path ), 'Orphan cleanup deletes generated files not linked to post meta.' );

$trash_path = get_post_meta( $auto_post_id, WP_Remote_OG_Plugin::META_IMAGE_PATH, true );
wp_trash_post( $auto_post_id );
wp_remote_og_assert( file_exists( $trash_path ), 'Moving post to trash does not delete generated image.' );
wp_delete_post( $auto_post_id, true );
wp_remote_og_assert( ! file_exists( $trash_path ), 'Permanently deleting post deletes active generated image.' );

$fallback_post = wp_remote_og_make_post( 'Fallback OG Meta Test' );
$fallback = WP_Remote_OG_Generator::generate_for_post( $fallback_post, array( 'engine' => 'gd' ) );
wp_remote_og_assert( ! is_wp_error( $fallback ), 'Fallback meta test post has a generated image.' );
wp_remote_og_assert( ! WP_Remote_OG_SEO::is_rank_math_active(), 'Rank Math inactive scenario is detectable in this test site.' );
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', 'wp-remote-og-test' );
}
wp_remote_og_assert( WP_Remote_OG_SEO::is_rank_math_active(), 'Rank Math active detection works when the Rank Math version constant is present.' );
wp_remote_og_assert( WP_Remote_OG_SEO::filter_rank_math_image_for_post( array( 'url' => 'https://example.test/old.png' ), $post_id )['url'] === $second['url'], 'Rank Math array image filter receives the generated image URL.' );

delete_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY );
wp_remote_og_assert( ! get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ), 'Template regeneration notice can be cleared after confirmation workflow.' );

if ( isset( $font['id'] ) && $font['id'] ) {
	$fonts = array_filter(
		WP_Remote_OG_Plugin::get_fonts(),
		function ( $candidate ) use ( $font ) {
			if ( isset( $candidate['id'] ) && $candidate['id'] === $font['id'] ) {
				if ( ! empty( $candidate['path'] ) ) {
					WP_Remote_OG_Uploads::safe_delete( $candidate['path'] );
				}
				return false;
			}
			return true;
		}
	);
	WP_Remote_OG_Plugin::save_fonts( $fonts );
}

foreach ( array( $post_id, $fallback_post, $page_id ) as $wp_remote_og_cleanup_post_id ) {
	if ( $wp_remote_og_cleanup_post_id && get_post( $wp_remote_og_cleanup_post_id ) ) {
		wp_delete_post( $wp_remote_og_cleanup_post_id, true );
	}
}

$result = $GLOBALS['wp_remote_og_test_results'];
echo "=================================\n";
echo 'Passed: ' . $result['passed'] . "\n";
echo 'Failed: ' . $result['failed'] . "\n";

if ( $result['failed'] > 0 ) {
	exit( 1 );
}
