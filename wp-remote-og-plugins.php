<?php
/**
 * Plugin Name: Social Preview Designer
 * Description: Design one Open Graph image template and automatically generate a branded social preview image for every post. Integrates with Rank Math.
 * Version: 1.0.0
 * Author: WP Remote Work
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-remote-og-plugins
 *
 * @package WPRemoteOG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_REMOTE_OG_VERSION', '1.0.0' );
define( 'WP_REMOTE_OG_FILE', __FILE__ );
define( 'WP_REMOTE_OG_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_REMOTE_OG_URL', plugin_dir_url( __FILE__ ) );

final class WP_Remote_OG_Plugin {
	const CAPABILITY                 = 'edit_others_posts';
	const OPTION_TEMPLATE           = 'wp_remote_og_template';
	const OPTION_DYNAMIC_FIELDS     = 'wp_remote_og_dynamic_fields';
	const OPTION_FONTS              = 'wp_remote_og_fonts';
	const OPTION_SETTINGS           = 'wp_remote_og_settings';
	const OPTION_TEMPLATE_VERSION   = 'wp_remote_og_template_version';
	const OPTION_TEMPLATE_DIRTY     = 'wp_remote_og_template_needs_regeneration';
	const OPTION_TEMPLATE_BACKUP    = 'wp_remote_og_template_backup';
	const OPTION_GENERATION_LOG     = 'wp_remote_og_generation_log';
	const OPTION_ACTIVATION_ERROR   = 'wp_remote_og_activation_error';
	const OPTION_PUBLISHPRESS_AVATAR_TOKEN = 'wp_remote_og_publishpress_avatar_token';
	const PUBLISHPRESS_AVATAR_TOKEN_META_KEY = 'avatar_token';
	const META_IMAGE_URL            = '_wp_remote_og_image_url';
	const META_IMAGE_PATH           = '_wp_remote_og_image_path';
	const META_IMAGE_HASH           = '_wp_remote_og_image_hash';
	const META_GENERATED_AT         = '_wp_remote_og_generated_at';
	const META_TEMPLATE_VERSION     = '_wp_remote_og_template_version';
	const CANVAS_WIDTH              = 1200;
	const CANVAS_HEIGHT             = 630;
	private static $publishpress_avatar_token_cache = array();

	public static function init() {
		register_activation_hook( WP_REMOTE_OG_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( WP_REMOTE_OG_FILE, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'load' ) );
	}

	public static function load() {
		WP_Remote_OG_Admin::init();
		WP_Remote_OG_Generator::init();
		WP_Remote_OG_SEO::init();
		self::load_publishpress_avatar_support();
	}

	public static function activate() {
		$directory = WP_Remote_OG_Uploads::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			update_option( self::OPTION_ACTIVATION_ERROR, $directory->get_error_message(), false );
		} else {
			delete_option( self::OPTION_ACTIVATION_ERROR );
		}

		if ( false === get_option( self::OPTION_TEMPLATE, false ) ) {
			add_option( self::OPTION_TEMPLATE, self::default_template() );
		}

		if ( false === get_option( self::OPTION_DYNAMIC_FIELDS, false ) ) {
			add_option( self::OPTION_DYNAMIC_FIELDS, self::default_dynamic_fields() );
		}

		if ( false === get_option( self::OPTION_FONTS, false ) ) {
			add_option( self::OPTION_FONTS, array() );
		}

		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option(
				self::OPTION_SETTINGS,
				array(
					'last_bulk_result' => '',
				)
			);
		}

		if ( false === get_option( self::OPTION_TEMPLATE_VERSION, false ) ) {
			add_option( self::OPTION_TEMPLATE_VERSION, self::template_hash( self::get_template() ) );
		}

		if ( false === get_option( self::OPTION_PUBLISHPRESS_AVATAR_TOKEN, false ) ) {
			add_option( self::OPTION_PUBLISHPRESS_AVATAR_TOKEN, '' );
		}
	}

	public static function deactivate() {
		// Keep generated images and settings intact on deactivation.
	}

	public static function load_publishpress_avatar_support() {
		if ( ! defined( 'PUBLISHPRESS_AUTHORS_VERSION' ) && ! class_exists( 'MultipleAuthors\\Classes\\Author' ) ) {
			return;
		}

		add_filter( 'get_avatar_data', array( __CLASS__, 'filter_publishpress_avatar_data' ), 20, 3 );
		add_filter( 'multiple_authors_get_avatar', array( __CLASS__, 'filter_multiple_authors_get_avatar' ), 20, 3 );
	}

	public static function filter_publishpress_avatar_data( $avatar_data, $id_or_email, $args ) {
		if ( ! is_array( $avatar_data ) || empty( $avatar_data['url'] ) ) {
			return $avatar_data;
		}

		$token = self::get_publishpress_avatar_token( $id_or_email );
		if ( '' === $token ) {
			return $avatar_data;
		}

		$avatar_data['url'] = self::add_token_to_url( $avatar_data['url'], $token );
		if ( ! empty( $avatar_data['srcset'] ) ) {
			$avatar_data['srcset'] = self::add_token_to_srcset( $avatar_data['srcset'], $token );
		}

		return $avatar_data;
	}

	public static function filter_multiple_authors_get_avatar( $avatar, $author, $size ) {
		$token = self::get_publishpress_avatar_token( $author );
		if ( '' === $token || ! is_string( $avatar ) ) {
			return $avatar;
		}

		return self::add_token_to_avatar_html( $avatar, $token );
	}

	public static function get_publishpress_avatar_token( $identity ) {
		$term_id = self::resolve_publishpress_author_term_id( $identity );
		if ( ! $term_id ) {
			return '';
		}

		if ( array_key_exists( $term_id, self::$publishpress_avatar_token_cache ) ) {
			return self::$publishpress_avatar_token_cache[ $term_id ];
		}

		$avatar_attachment_id = get_term_meta( $term_id, 'avatar', true );
		if ( empty( $avatar_attachment_id ) || 0 === absint( $avatar_attachment_id ) ) {
			self::$publishpress_avatar_token_cache[ $term_id ] = '';
			return '';
		}

		$token = get_term_meta( $term_id, self::PUBLISHPRESS_AVATAR_TOKEN_META_KEY, true );
		if ( '' === trim( (string) $token ) ) {
			$token = get_option( self::OPTION_PUBLISHPRESS_AVATAR_TOKEN, '' );
		}

		$token = sanitize_text_field( wp_unslash( $token ) );
		self::$publishpress_avatar_token_cache[ $term_id ] = $token;

		return $token;
	}

	private static function resolve_publishpress_author_term_id( $identity ) {
		if ( is_object( $identity ) ) {
			if ( $identity instanceof \MultipleAuthors\Classes\Author && ! empty( $identity->term_id ) ) {
				return absint( $identity->term_id );
			}

			if ( $identity instanceof WP_User && ! empty( $identity->ID ) ) {
				return self::resolve_publishpress_author_term_id( absint( $identity->ID ) );
			}

			if ( $identity instanceof WP_Comment ) {
				if ( ! empty( $identity->user_id ) ) {
					return self::resolve_publishpress_author_term_id( absint( $identity->user_id ) );
				}

				if ( ! empty( $identity->comment_author_email ) ) {
					return self::resolve_publishpress_author_term_id( $identity->comment_author_email );
				}
			}
		}

		if ( is_numeric( $identity ) ) {
			$identity = absint( $identity );
			if ( 0 === $identity ) {
				return 0;
			}

			if ( class_exists( 'MultipleAuthors\\Classes\\Author' ) ) {
				$author = \MultipleAuthors\Classes\Author::get_by_user_id( $identity );
				if ( $author && ! empty( $author->term_id ) ) {
					return absint( $author->term_id );
				}
			}

			return self::resolve_publishpress_author_term_id_by_user_meta( $identity );
		}

		if ( is_string( $identity ) && is_email( $identity ) ) {
			$identity = sanitize_email( $identity );
			if ( class_exists( 'MultipleAuthors\\Classes\\Author' ) ) {
				$author = \MultipleAuthors\Classes\Author::get_by_email( $identity );
				if ( $author && ! empty( $author->term_id ) ) {
					return absint( $author->term_id );
				}
			}

			return self::resolve_publishpress_author_term_id_by_email( $identity );
		}

		return 0;
	}

	private static function resolve_publishpress_author_term_id_by_user_meta( $user_id ) {
		global $wpdb;

		if ( ! taxonomy_exists( 'author' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- termmeta join has no WP API equivalent; result is memoized per request in $publishpress_avatar_token_cache.
		$term_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT te.term_id
				FROM {$wpdb->termmeta} AS te
				LEFT JOIN {$wpdb->term_taxonomy} AS ta ON (te.term_id = ta.term_id)
				WHERE ta.taxonomy = %s
					AND (te.meta_key = %s OR te.meta_key = %s)
				LIMIT 1",
				'author',
				'user_id_' . absint( $user_id ),
				'user_id'
			)
		);

		return $term_id ? absint( $term_id ) : 0;
	}

	private static function resolve_publishpress_author_term_id_by_email( $email ) {
		global $wpdb;

		if ( ! taxonomy_exists( 'author' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- termmeta join has no WP API equivalent; result is memoized per request in $publishpress_avatar_token_cache.
		$term_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tm.term_id
				FROM {$wpdb->termmeta} AS tm
				LEFT JOIN {$wpdb->term_taxonomy} AS ta ON (tm.term_id = ta.term_id)
				WHERE ta.taxonomy = %s
					AND tm.meta_value = %s
				LIMIT 1",
				'author',
				sanitize_email( $email )
			)
		);

		return $term_id ? absint( $term_id ) : 0;
	}

	private static function add_token_to_avatar_html( $avatar_html, $token ) {
		$avatar_html = preg_replace_callback(
			'/\bsrc\s*=\s*([\'"])(.*?)\1/i',
			function( $matches ) use ( $token ) {
				return "src={$matches[1]}" . self::add_token_to_url( $matches[2], $token ) . "{$matches[1]}";
			},
			$avatar_html
		);

		$avatar_html = preg_replace_callback(
			'/\bsrcset\s*=\s*([\'"])(.*?)\1/i',
			function( $matches ) use ( $token ) {
				return "srcset={$matches[1]}" . self::add_token_to_srcset( $matches[2], $token ) . "{$matches[1]}";
			},
			$avatar_html
		);

		return $avatar_html;
	}

	private static function add_token_to_srcset( $srcset, $token ) {
		$sets = explode( ',', (string) $srcset );
		if ( ! is_array( $sets ) || empty( $sets ) ) {
			return $srcset;
		}

		$updated = array();
		foreach ( $sets as $set ) {
			$set = trim( $set );
			if ( '' === $set ) {
				continue;
			}

			$parts    = preg_split( '/\s+/', $set );
			$url      = isset( $parts[0] ) ? $parts[0] : '';
			$extra    = array_slice( $parts, 1 );
			$updated[] = trim( self::add_token_to_url( $url, $token ) . ' ' . implode( ' ', $extra ) );
		}

		return implode( ', ', $updated );
	}

	private static function add_token_to_url( $url, $token ) {
		if ( '' === $url || '' === $token ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
			if ( isset( $query_args['token'] ) ) {
				return $url;
			}
		}

		return add_query_arg( 'token', $token, $url );
	}

	public static function capability() {
		return apply_filters( 'wp_remote_og_capability', self::CAPABILITY );
	}

	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	public static function default_template() {
		return array(
			'canvas'     => array(
				'width'  => self::CANVAS_WIDTH,
				'height' => self::CANVAS_HEIGHT,
			),
			'background' => array(
				'id'  => 0,
				'url' => '',
			),
			'layers'     => array(
				array(
					'id'           => 'layer-title',
					'type'         => 'text',
					'content'      => '{post_title}',
					'label'        => 'Post Title',
					'x'            => 90,
					'y'            => 105,
					'width'        => 1020,
					'height'       => 270,
					'font_family'  => 'system',
					'font_id'      => '',
					'font_size'    => 72,
					'min_font_size'=> 38,
					'color'        => '#111827',
					'align'        => 'left',
					'line_height'  => 1.08,
					'max_lines'    => 3,
				),
			),
		);
	}

	public static function default_dynamic_fields() {
		return array(
			array(
				'token'    => '{post_title}',
				'label'    => 'Post Title',
				'fallback' => '',
				'enabled'  => true,
			),
		);
	}

	public static function get_template() {
		$template = get_option( self::OPTION_TEMPLATE, self::default_template() );
		return self::sanitize_template( $template );
	}

	public static function save_template( $template ) {
		$old_hash = get_option( self::OPTION_TEMPLATE_VERSION, '' );
		$template = self::sanitize_template( $template );
		$new_hash = self::template_hash( $template );

		update_option( self::OPTION_TEMPLATE, $template, false );
		update_option( self::OPTION_TEMPLATE_VERSION, $new_hash, false );

		if ( $old_hash && $old_hash !== $new_hash ) {
			update_option( self::OPTION_TEMPLATE_DIRTY, 1, false );
		}

		return $template;
	}

	public static function get_dynamic_fields() {
		$fields = get_option( self::OPTION_DYNAMIC_FIELDS, self::default_dynamic_fields() );
		return self::sanitize_dynamic_fields( $fields );
	}

	public static function save_dynamic_fields( $fields ) {
		$fields = self::sanitize_dynamic_fields( $fields );
		update_option( self::OPTION_DYNAMIC_FIELDS, $fields, false );
		return $fields;
	}

	public static function get_fonts() {
		$fonts = get_option( self::OPTION_FONTS, array() );
		return is_array( $fonts ) ? array_values( $fonts ) : array();
	}

	public static function save_fonts( $fonts ) {
		update_option( self::OPTION_FONTS, array_values( $fonts ), false );
	}

	public static function template_hash( $template ) {
		return substr( hash( 'sha256', wp_json_encode( $template ) ), 0, 12 );
	}

	public static function sanitize_dynamic_fields( $fields ) {
		$sanitized = array();

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$token = isset( $field['token'] ) ? sanitize_text_field( wp_unslash( $field['token'] ) ) : '';
			if ( ! WP_Remote_OG_Dynamic_Fields::is_valid_token( $token ) ) {
				continue;
			}

			$sanitized[] = array(
				'token'    => $token,
				'label'    => isset( $field['label'] ) ? sanitize_text_field( wp_unslash( $field['label'] ) ) : $token,
				'fallback' => isset( $field['fallback'] ) ? sanitize_text_field( wp_unslash( $field['fallback'] ) ) : '',
				'enabled'  => ! empty( $field['enabled'] ),
			);
		}

		$has_title = false;
		foreach ( $sanitized as $field ) {
			if ( '{post_title}' === $field['token'] ) {
				$has_title = true;
				break;
			}
		}

		if ( ! $has_title ) {
			array_unshift(
				$sanitized,
				array(
					'token'    => '{post_title}',
					'label'    => 'Post Title',
					'fallback' => '',
					'enabled'  => true,
				)
			);
		}

		return $sanitized;
	}

	public static function sanitize_template( $template ) {
		if ( is_string( $template ) ) {
			$decoded = json_decode( wp_unslash( $template ), true );
			$template = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $template ) ) {
			$template = array();
		}

		$background = isset( $template['background'] ) && is_array( $template['background'] ) ? $template['background'] : array();
		$layers     = isset( $template['layers'] ) && is_array( $template['layers'] ) ? $template['layers'] : array();
		$clean      = array(
			'canvas'     => array(
				'width'  => self::CANVAS_WIDTH,
				'height' => self::CANVAS_HEIGHT,
			),
			'background' => array(
				'id'  => isset( $background['id'] ) ? absint( $background['id'] ) : 0,
				'url' => isset( $background['url'] ) ? esc_url_raw( wp_unslash( $background['url'] ) ) : '',
			),
			'layers'     => array(),
		);

		foreach ( array_slice( $layers, 0, 50 ) as $index => $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}

			$type    = isset( $layer['type'] ) ? sanitize_key( $layer['type'] ) : 'text';
			$type    = in_array( $type, array( 'text', 'image', 'line' ), true ) ? $type : 'text';
			$content = isset( $layer['content'] ) ? wp_unslash( $layer['content'] ) : '';
			if ( 'line' === $type ) {
				$content = '';
			} else {
				$content = 'image' === $type && ! WP_Remote_OG_Dynamic_Fields::contains_token( $content ) ? esc_url_raw( $content ) : sanitize_text_field( $content );
			}
			if ( 'text' === $type && '' === $content ) {
				$content = '{post_title}';
			}

			$font_size          = self::clamp_number( isset( $layer['font_size'] ) ? $layer['font_size'] : 48, 8, 180 );
			$min_font_size      = self::clamp_number( isset( $layer['min_font_size'] ) ? $layer['min_font_size'] : 24, 6, $font_size );
			$image_shape        = isset( $layer['image_shape'] ) && in_array( $layer['image_shape'], array( 'square', 'rounded', 'circle' ), true ) ? $layer['image_shape'] : 'square';
			$image_fit          = isset( $layer['image_fit'] ) && in_array( $layer['image_fit'], array( 'contain', 'cover', 'stretch' ), true ) ? $layer['image_fit'] : 'contain';
			$image_aspect_ratio = self::clamp_number( isset( $layer['image_aspect_ratio'] ) ? $layer['image_aspect_ratio'] : 1, 0.01, 100 );
			$line_orientation   = isset( $layer['line_orientation'] ) && 'vertical' === $layer['line_orientation'] ? 'vertical' : 'horizontal';
			$requires_token     = isset( $layer['requires_token'] ) ? sanitize_text_field( wp_unslash( $layer['requires_token'] ) ) : '';
			$requires_token     = WP_Remote_OG_Dynamic_Fields::is_valid_token( $requires_token ) ? $requires_token : '';
			$label_default      = 'Text Layer';
			if ( 'image' === $type ) {
				$label_default = 'Image Layer';
			} elseif ( 'line' === $type ) {
				$label_default = 'vertical' === $line_orientation ? 'Vertical Line' : 'Horizontal Line';
			}
			$min_width  = 'line' === $type && 'vertical' === $line_orientation ? 1 : 20;
			$min_height = 'line' === $type && 'horizontal' === $line_orientation ? 1 : 20;
			$align      = isset( $layer['align'] ) ? sanitize_key( $layer['align'] ) : 'left';
			$align      = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';

			$clean['layers'][] = array(
				'id'               => isset( $layer['id'] ) ? sanitize_key( $layer['id'] ) : 'layer-' . ( $index + 1 ),
				'type'             => $type,
				'content'          => $content,
				'label'            => isset( $layer['label'] ) ? sanitize_text_field( wp_unslash( $layer['label'] ) ) : $label_default,
				'image_shape'        => $image_shape,
				'image_fit'          => $image_fit,
				'image_aspect_ratio' => $image_aspect_ratio,
				'line_orientation'   => $line_orientation,
				'requires_token'     => $requires_token,
				'x'                => self::clamp_number( isset( $layer['x'] ) ? $layer['x'] : 0, 0, self::CANVAS_WIDTH ),
				'y'                => self::clamp_number( isset( $layer['y'] ) ? $layer['y'] : 0, 0, self::CANVAS_HEIGHT ),
				'width'            => self::clamp_number( isset( $layer['width'] ) ? $layer['width'] : 600, $min_width, self::CANVAS_WIDTH ),
				'height'           => self::clamp_number( isset( $layer['height'] ) ? $layer['height'] : 120, $min_height, self::CANVAS_HEIGHT ),
				'font_family'      => isset( $layer['font_family'] ) ? sanitize_text_field( wp_unslash( $layer['font_family'] ) ) : 'system',
				'font_id'          => isset( $layer['font_id'] ) ? sanitize_key( $layer['font_id'] ) : '',
				'font_size'        => $font_size,
				'min_font_size'    => $min_font_size,
				'color'            => self::sanitize_hex_color( isset( $layer['color'] ) ? $layer['color'] : '#111827' ),
				'align'            => $align,
				'line_height'      => self::clamp_number( isset( $layer['line_height'] ) ? $layer['line_height'] : 1.1, 0.8, 2.5 ),
				'max_lines'        => (int) self::clamp_number( isset( $layer['max_lines'] ) ? $layer['max_lines'] : 3, 1, 12 ),
			);
		}

		if ( empty( $clean['layers'] ) ) {
			$clean['layers'] = self::default_template()['layers'];
		}

		return $clean;
	}

	public static function clamp_number( $value, $min, $max ) {
		$value = is_numeric( $value ) ? (float) $value : (float) $min;
		return max( (float) $min, min( (float) $max, $value ) );
	}

	public static function sanitize_hex_color( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '#111827';
	}
}

final class WP_Remote_OG_Uploads {
	public static function get_directory() {
		$uploads = wp_upload_dir();
		return array(
			'path' => trailingslashit( $uploads['basedir'] ) . 'wp-remote-og',
			'url'  => trailingslashit( $uploads['baseurl'] ) . 'wp-remote-og',
		);
	}

	public static function get_fonts_directory() {
		$directory = self::get_directory();
		return array(
			'path' => trailingslashit( $directory['path'] ) . 'fonts',
			'url'  => trailingslashit( $directory['url'] ) . 'fonts',
		);
	}

	public static function ensure_directory() {
		$directory = self::get_directory();
		if ( ! wp_mkdir_p( $directory['path'] ) ) {
			return new WP_Error( 'wp_remote_og_upload_dir', __( 'Unable to create WP Remote OG upload directory.', 'wp-remote-og-plugins' ) );
		}

		$index = trailingslashit( $directory['path'] ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		return $directory;
	}

	public static function ensure_fonts_directory() {
		self::ensure_directory();
		$directory = self::get_fonts_directory();
		if ( ! wp_mkdir_p( $directory['path'] ) ) {
			return new WP_Error( 'wp_remote_og_font_dir', __( 'Unable to create WP Remote OG font directory.', 'wp-remote-og-plugins' ) );
		}

		$index = trailingslashit( $directory['path'] ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		return $directory;
	}

	public static function is_safe_path( $path ) {
		$directory = self::get_directory();
		$base      = realpath( $directory['path'] );
		$file      = realpath( $path );

		if ( ! $base || ! $file ) {
			return false;
		}

		$base = rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $file, $base );
	}

	public static function safe_delete( $path ) {
		if ( ! $path || ! file_exists( $path ) || ! self::is_safe_path( $path ) || is_dir( $path ) ) {
			return false;
		}

		return (bool) wp_delete_file( $path );
	}

	public static function attachment_path_from_template_background( $template ) {
		$background = isset( $template['background'] ) ? $template['background'] : array();
		$id         = isset( $background['id'] ) ? absint( $background['id'] ) : 0;

		if ( $id ) {
			$path = get_attached_file( $id );
			if ( $path && file_exists( $path ) ) {
				return $path;
			}
		}

		$url = isset( $background['url'] ) ? esc_url_raw( $background['url'] ) : '';
		if ( ! $url ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$relative = ltrim( substr( $url, strlen( $uploads['baseurl'] ) ), '/' );
			$path     = trailingslashit( $uploads['basedir'] ) . $relative;
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	public static function cleanup_orphans() {
		global $wpdb;

		$directory = self::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$files = glob( trailingslashit( $directory['path'] ) . 'post-*-og-*.png' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin maintenance action; must read live meta values across all posts.
		$linked_paths = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				WP_Remote_OG_Plugin::META_IMAGE_PATH
			)
		);
		$linked_paths = array_filter( array_map( 'strval', $linked_paths ) );

		$deleted = 0;
		$kept    = 0;
		$errors  = array();

		foreach ( $files as $file ) {
			$filename = basename( $file );
			if ( ! preg_match( '/^post-(\d+)-og-[a-f0-9]{8,16}\.png$/', $filename, $matches ) ) {
				$kept++;
				continue;
			}

			$post_id   = absint( $matches[1] );
			$is_linked = in_array( $file, $linked_paths, true ) && 'post' === get_post_type( $post_id );

			if ( $is_linked ) {
				$kept++;
				continue;
			}

			if ( self::safe_delete( $file ) ) {
				$deleted++;
			} else {
				$errors[] = $filename;
			}
		}

		return array(
			'deleted' => $deleted,
			'kept'    => $kept,
			'errors'  => $errors,
		);
	}
}

final class WP_Remote_OG_Diagnostics {
	public static function get() {
		$directory = WP_Remote_OG_Uploads::ensure_directory();
		$path      = is_wp_error( $directory ) ? '' : $directory['path'];

		return array(
			'imagick'          => class_exists( 'Imagick' ),
			'gd'               => function_exists( 'imagecreatetruecolor' ),
			'rank_math'        => WP_Remote_OG_SEO::is_rank_math_active(),
			'acf'              => function_exists( 'get_field' ),
			'upload_dir'       => $path,
			'upload_writable'  => $path ? wp_is_writable( $path ) : false,
			'generated_count'  => self::generated_count(),
			'missing_count'    => self::missing_count(),
			'orphaned_count'   => self::orphaned_count(),
			'last_bulk_result' => self::last_bulk_result(),
		);
	}

	public static function generated_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostics-page count; must reflect live data.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				WP_Remote_OG_Plugin::META_IMAGE_URL
			)
		);
	}

	public static function missing_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostics-page count; must reflect live data.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = 'post' AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '')",
				WP_Remote_OG_Plugin::META_IMAGE_URL
			)
		);
	}

	public static function orphaned_count() {
		$directory = WP_Remote_OG_Uploads::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			return 0;
		}

		$files = glob( trailingslashit( $directory['path'] ) . 'post-*-og-*.png' );
		if ( ! is_array( $files ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $files as $file ) {
			$linked = WP_Remote_OG_Storage::is_active_generated_path( $file );
			if ( ! $linked ) {
				$count++;
			}
		}

		return $count;
	}

	public static function last_bulk_result() {
		$settings = get_option( WP_Remote_OG_Plugin::OPTION_SETTINGS, array() );
		return isset( $settings['last_bulk_result'] ) ? $settings['last_bulk_result'] : '';
	}
}

final class WP_Remote_OG_Dynamic_Fields {
	public static function get_available_tokens() {
		$tokens  = array();
		$seen    = array();

		self::add_discovered_token( $tokens, $seen, '{post_title}', __( 'Post Title', 'wp-remote-og-plugins' ) );
		self::add_discovered_token( $tokens, $seen, '{publishpress_author_avatar}', __( 'PublishPress Author Avatar', 'wp-remote-og-plugins' ) );
		$taxonomies = self::discovered_taxonomy_tokens();
		foreach ( $taxonomies as $token ) {
			self::add_discovered_token( $tokens, $seen, $token['token'], $token['label'] );
		}

		foreach ( self::discovered_acf_tokens() as $token ) {
			self::add_discovered_token( $tokens, $seen, $token['token'], $token['label'] );
		}

		foreach ( self::discovered_meta_tokens() as $token ) {
			self::add_discovered_token( $tokens, $seen, $token['token'], $token['label'] );
		}

		return $tokens;
	}

	private static function add_discovered_token( &$tokens, &$seen, $token, $label = '' ) {
		$token = sanitize_text_field( wp_unslash( $token ) );
		if ( ! $token || isset( $seen[ $token ] ) || ! self::is_valid_token( $token ) ) {
			return;
		}
		$seen[ $token ] = true;
		$tokens[] = array(
			'token' => $token,
			'label' => $label ? sanitize_text_field( $label ) : $token,
		);
	}

	private static function discovered_taxonomy_tokens() {
		$tokens = array();
		$taxonomies = get_object_taxonomies( 'post', 'objects' );
		if ( ! is_array( $taxonomies ) ) {
			return array();
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->name ) ) {
				continue;
			}
			$taxonomy_label = $taxonomy->labels && isset( $taxonomy->labels->singular_name ) && '' !== $taxonomy->labels->singular_name ? $taxonomy->labels->singular_name : $taxonomy->name;
			$tokens[] = array(
				'token' => '{taxonomy:' . sanitize_key( $taxonomy->name ) . '}',
				'label' => sprintf(
					/* translators: %s: taxonomy label */
					__( 'Taxonomy: %s', 'wp-remote-og-plugins' ),
					$taxonomy_label
				),
			);
		}
		return $tokens;
	}

	private static function discovered_acf_tokens() {
		$tokens = array();

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$field_groups = acf_get_field_groups();
		if ( ! is_array( $field_groups ) ) {
			return array();
		}

		foreach ( $field_groups as $group ) {
			if ( ! isset( $group['ID'] ) ) {
				continue;
			}
			$fields = acf_get_fields( $group['ID'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$name = sanitize_key( $field['name'] );
				$tokens[] = array(
					'token' => '{acf:' . $name . '}',
					'label' => sprintf(
						/* translators: %s: ACF field name */
						__( 'ACF: %s', 'wp-remote-og-plugins' ),
						sanitize_text_field( $field['name'] )
					),
				);
			}
		}
		return $tokens;
	}

	private static function discovered_meta_tokens() {
		$tokens = array();
		$posts  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => 50,
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post_id ) {
			$meta = get_post_meta( (int) $post_id );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			foreach ( array_keys( $meta ) as $key ) {
				if ( ! is_string( $key ) || '' === $key || '_' === $key[0] ) {
					continue;
				}
				$tokens[] = array(
					'token' => '{meta:' . sanitize_key( $key ) . '}',
					'label' => sprintf(
						/* translators: %s: meta key name */
						__( 'Meta: %s', 'wp-remote-og-plugins' ),
						$key
					),
				);
			}
		}
		return $tokens;
	}

	public static function is_valid_token( $token ) {
		if ( in_array( $token, array( '{post_title}', '{publishpress_author_avatar}' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/^\{(taxonomy|acf|meta):[A-Za-z0-9_\-]+\}$/', $token );
	}

	public static function contains_token( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\{(?:post_title|publishpress_author_avatar|taxonomy:[A-Za-z0-9_\-]+|acf:[A-Za-z0-9_\-]+|meta:[A-Za-z0-9_\-]+)\}/', $value );
	}

	public static function token_label_map() {
		$map = array();
		foreach ( WP_Remote_OG_Plugin::get_dynamic_fields() as $field ) {
			if ( ! empty( $field['enabled'] ) ) {
				$map[ $field['token'] ] = $field;
			}
		}
		return $map;
	}

	public static function resolve_token( $post_id, $token ) {
		$fields   = WP_Remote_OG_Plugin::get_dynamic_fields();
		$fallback = '';

		foreach ( $fields as $field ) {
			if ( isset( $field['token'] ) && $field['token'] === $token ) {
				$fallback = isset( $field['fallback'] ) ? $field['fallback'] : '';
				break;
			}
		}

		if ( ! self::is_valid_token( $token ) ) {
			return $fallback;
		}

		if ( '{post_title}' === $token ) {
			$title = self::decode_text_entities( get_the_title( $post_id ) );
			return '' !== $title ? $title : $fallback;
		}

		if ( '{publishpress_author_avatar}' === $token ) {
			$url = self::publishpress_author_avatar_url( $post_id );
			return '' !== $url ? $url : $fallback;
		}

		if ( preg_match( '/^\{taxonomy:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$taxonomy = sanitize_key( $matches[1] );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return $fallback;
			}

			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return $fallback;
			}

			return self::decode_text_entities( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
		}

		if ( preg_match( '/^\{acf:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$field_name = sanitize_key( $matches[1] );
			$value      = null;

			if ( function_exists( 'get_field' ) ) {
				$value = get_field( $field_name, $post_id );
			}

			if ( null === $value || false === $value || '' === $value ) {
				$value = get_post_meta( $post_id, $field_name, true );
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_filter( array_map( 'strval', $value ) ) ) );
			}

			$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			return '' !== $value ? self::decode_text_entities( $value ) : $fallback;
		}

		if ( preg_match( '/^\{meta:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$meta_key = sanitize_key( $matches[1] );
			$value    = get_post_meta( $post_id, $meta_key, true );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_filter( array_map( 'strval', $value ) ) ) );
			}
			$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			return '' !== $value ? self::decode_text_entities( $value ) : $fallback;
		}

		return $fallback;
	}

	private static function decode_text_entities( $value ) {
		$value   = (string) $value;
		$charset = get_bloginfo( 'charset' );
		$charset = $charset ? $charset : 'UTF-8';

		return html_entity_decode( wp_specialchars_decode( $value, ENT_QUOTES ), ENT_QUOTES | ENT_HTML5, $charset );
	}

	public static function token_has_source_value( $post_id, $token ) {
		if ( ! self::is_valid_token( $token ) ) {
			return true;
		}

		if ( '{post_title}' === $token ) {
			return '' !== get_the_title( $post_id );
		}

		if ( '{publishpress_author_avatar}' === $token ) {
			return '' !== self::publishpress_author_avatar_url( $post_id );
		}

		if ( preg_match( '/^\{taxonomy:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$taxonomy = sanitize_key( $matches[1] );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return false;
			}

			$terms = get_the_terms( $post_id, $taxonomy );
			return ! is_wp_error( $terms ) && ! empty( $terms );
		}

		if ( preg_match( '/^\{acf:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$field_name = sanitize_key( $matches[1] );
			$value      = null;
			if ( function_exists( 'get_field' ) ) {
				$value = get_field( $field_name, $post_id );
			}
			if ( null === $value || false === $value || '' === $value ) {
				$value = get_post_meta( $post_id, $field_name, true );
			}
			return ! empty( $value );
		}

		if ( preg_match( '/^\{meta:([A-Za-z0-9_\-]+)\}$/', $token, $matches ) ) {
			$value = get_post_meta( $post_id, sanitize_key( $matches[1] ), true );
			return ! empty( $value );
		}

		return true;
	}

	public static function layer_is_visible( $post_id, $layer ) {
		$requires_token = isset( $layer['requires_token'] ) ? (string) $layer['requires_token'] : '';
		if ( '' === $requires_token ) {
			return true;
		}

		return self::token_has_source_value( $post_id, $requires_token );
	}

	private static function publishpress_author_avatar_url( $post_id ) {
		if ( ! taxonomy_exists( 'author' ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, 'author' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		usort(
			$terms,
			function ( $a, $b ) {
				$a_order = isset( $a->term_order ) ? (int) $a->term_order : 0;
				$b_order = isset( $b->term_order ) ? (int) $b->term_order : 0;

				if ( $a_order === $b_order ) {
					return (int) $a->term_id <=> (int) $b->term_id;
				}

				return $a_order <=> $b_order;
			}
		);

		foreach ( $terms as $term ) {
			if ( empty( $term->term_id ) ) {
				continue;
			}

			$avatar_attachment_id = absint( get_term_meta( $term->term_id, 'avatar', true ) );
			if ( ! $avatar_attachment_id ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $avatar_attachment_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}

	public static function resolve_text( $post_id, $text, &$warnings = array() ) {
		$warnings = array();
		$text     = (string) $text;

		return preg_replace_callback(
			'/\{(?:post_title|publishpress_author_avatar|taxonomy:[A-Za-z0-9_\-]+|acf:[A-Za-z0-9_\-]+|meta:[A-Za-z0-9_\-]+)\}/',
			function ( $matches ) use ( $post_id, &$warnings ) {
				$value = self::resolve_token( $post_id, $matches[0] );
				if ( '' === $value ) {
					$warnings[] = sprintf( 'Missing value for %s', $matches[0] );
				}
				return $value;
			},
			$text
		);
	}

	public static function resolve_layer_image( $post_id, $content, &$warnings = array() ) {
		$warnings = is_array( $warnings ) ? $warnings : array();
		$content  = trim( (string) $content );

		if ( '' === $content ) {
			$warnings[] = __( 'Image layer has no source.', 'wp-remote-og-plugins' );
			return '';
		}

		$resolved = self::resolve_text( $post_id, $content, $warnings );
		$resolved = trim( (string) $resolved );
		if ( '' === $resolved ) {
			$warnings[] = __( 'Unable to resolve image layer source.', 'wp-remote-og-plugins' );
		}

		return $resolved;
	}

	public static function preview_data( $post_id, $template = null ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wp_remote_og_invalid_post', __( 'Invalid preview post.', 'wp-remote-og-plugins' ) );
		}

		$template = $template ? WP_Remote_OG_Plugin::sanitize_template( $template ) : WP_Remote_OG_Plugin::get_template();
		$layers   = array();
		$warnings = array();

		foreach ( $template['layers'] as $layer ) {
			$layer_warnings      = array();
			$layer['hidden']     = ! self::layer_is_visible( $post_id, $layer );
			if ( $layer['hidden'] ) {
				$layer['resolved'] = '';
				$layers[ $layer['id'] ] = $layer;
				continue;
			}

			if ( 'line' === ( $layer['type'] ?? 'text' ) ) {
				$layer['resolved'] = '';
			} elseif ( 'image' === ( $layer['type'] ?? 'text' ) ) {
				$layer['resolved'] = self::resolve_layer_image( $post_id, $layer['content'], $layer_warnings );
			} else {
				$layer['resolved'] = self::resolve_text( $post_id, $layer['content'], $layer_warnings );
			}
			$warnings            = array_merge( $warnings, $layer_warnings );
			$layers[ $layer['id'] ] = $layer;
		}

		return array(
			'post_id'  => $post_id,
			'title'    => get_the_title( $post_id ),
			'layers'   => $layers,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}
}

final class WP_Remote_OG_Fonts {
	const GOOGLE_FONTS_CATALOG_TRANSIENT = 'wp_remote_og_google_fonts_catalog';

	public static function google_font_catalog_url() {
		return 'https://fonts.google.com/metadata/fonts';
	}

	public static function sanitize_google_font_family( $family ) {
		$family = trim( preg_replace( '/\s+/', ' ', wp_unslash( $family ) ) );
		$family = sanitize_text_field( $family );

		if ( '' === $family ) {
			return new WP_Error( 'wp_remote_og_google_font_missing', __( 'Google font family is required.', 'wp-remote-og-plugins' ) );
		}

		return $family;
	}

	public static function get_google_font_catalog( $force_refresh = false ) {
		$cached = get_transient( self::GOOGLE_FONTS_CATALOG_TRANSIENT );
		if ( ! $force_refresh && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::google_font_catalog_url(),
			array(
				'timeout'            => 15,
				'user-agent'         => 'WP Remote OG/' . WP_REMOTE_OG_VERSION . '; WordPress',
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return is_array( $cached ) ? $cached : array();
		}

		if ( 200 !== absint( wp_remote_retrieve_response_code( $response ) ) ) {
			return is_array( $cached ) ? $cached : array();
		}

		$raw = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $raw ) || empty( $raw['familyMetadataList'] ) || ! is_array( $raw['familyMetadataList'] ) ) {
			return is_array( $cached ) ? $cached : array();
		}

		$fonts = array();
		foreach ( $raw['familyMetadataList'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['family'] ) || ! is_string( $entry['family'] ) ) {
				continue;
			}
			$family = trim( $entry['family'] );
			if ( '' !== $family ) {
				$fonts[] = $family;
			}
		}

		$fonts = array_values( array_unique( $fonts ) );
		sort( $fonts, SORT_NATURAL | SORT_FLAG_CASE );
		set_transient( self::GOOGLE_FONTS_CATALOG_TRANSIENT, $fonts, DAY_IN_SECONDS * 7 );
		return $fonts;
	}

	public static function register_google_font( $family ) {
		$family = self::sanitize_google_font_family( $family );
		if ( is_wp_error( $family ) ) {
			return $family;
		}

		$css_url = add_query_arg(
			array(
				'family'  => $family,
				'display' => 'swap',
			),
			'https://fonts.googleapis.com/css2'
		);

		if ( ! $css_url ) {
			return new WP_Error( 'wp_remote_og_google_font_bad_family', __( 'The selected Google font family is not valid.', 'wp-remote-og-plugins' ) );
		}

		$family_slug = sanitize_key( str_replace( array( ' ', ',', ':', ';', '@' ), '-', $family ) );
		$id          = 'google-' . $family_slug . '-' . substr( hash( 'sha256', microtime( true ) . wp_rand() ), 0, 8 );

		$font = array(
			'id'         => $id,
			'label'      => $family,
			'source'     => 'google',
			'css_url'    => $css_url,
			'url'        => '',
			'filename'   => '',
			'extension'  => 'google-font',
			'path'       => '',
			'uploaded_at' => current_time( 'mysql' ),
			'renderable' => false,
		);

		$fonts   = WP_Remote_OG_Plugin::get_fonts();
		$fonts[] = $font;
		WP_Remote_OG_Plugin::save_fonts( $fonts );

		$renderable_font = self::ensure_google_font_renderable( $font );
		if ( ! is_wp_error( $renderable_font ) ) {
			return $renderable_font;
		}

		return $font;
	}

	public static function google_font_ttf_url_from_css( $css ) {
		$css = (string) $css;
		if ( '' === $css ) {
			return '';
		}

		if ( ! preg_match_all( '/url\(\s*[\'"]?(https:\/\/fonts\.gstatic\.com\/[^\'")\s]+\.ttf)[\'"]?\s*\)\s*format\(\s*[\'"]?truetype[\'"]?\s*\)/i', $css, $matches ) ) {
			return '';
		}

		return esc_url_raw( $matches[1][0] );
	}

	public static function ensure_google_font_renderable( $font ) {
		if ( ! is_array( $font ) || empty( $font['id'] ) || empty( $font['label'] ) || 'google' !== ( $font['source'] ?? '' ) ) {
			return new WP_Error( 'wp_remote_og_google_font_invalid', __( 'The Google font record is invalid.', 'wp-remote-og-plugins' ) );
		}

		if ( ! empty( $font['renderable'] ) && ! empty( $font['path'] ) && file_exists( $font['path'] ) ) {
			return $font;
		}

		$css_url = ! empty( $font['css_url'] ) ? $font['css_url'] : add_query_arg(
			array(
				'family'  => $font['label'],
				'display' => 'swap',
			),
			'https://fonts.googleapis.com/css2'
		);
		$css_url = esc_url_raw( $css_url );

		if ( ! wp_http_validate_url( $css_url ) ) {
			return new WP_Error( 'wp_remote_og_google_font_css_url', __( 'The Google font stylesheet URL is not valid.', 'wp-remote-og-plugins' ) );
		}

		$response = wp_remote_get(
			$css_url,
			array(
				'timeout'            => 15,
				'user-agent'         => 'Mozilla/5.0 AppleWebKit/537.36 WP Remote OG/' . WP_REMOTE_OG_VERSION,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== absint( wp_remote_retrieve_response_code( $response ) ) ) {
			return new WP_Error( 'wp_remote_og_google_font_css', __( 'Unable to load the Google font stylesheet.', 'wp-remote-og-plugins' ) );
		}

		$font_url = self::google_font_ttf_url_from_css( wp_remote_retrieve_body( $response ) );
		if ( '' === $font_url || ! wp_http_validate_url( $font_url ) ) {
			return new WP_Error( 'wp_remote_og_google_font_ttf_missing', __( 'The Google font stylesheet did not include a TTF source.', 'wp-remote-og-plugins' ) );
		}

		$font_response = wp_remote_get(
			$font_url,
			array(
				'timeout'            => 20,
				'user-agent'         => 'WP Remote OG/' . WP_REMOTE_OG_VERSION . '; WordPress',
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $font_response ) || 200 !== absint( wp_remote_retrieve_response_code( $font_response ) ) ) {
			return new WP_Error( 'wp_remote_og_google_font_download', __( 'Unable to download the Google font file.', 'wp-remote-og-plugins' ) );
		}

		$font_body = wp_remote_retrieve_body( $font_response );
		if ( '' === $font_body ) {
			return new WP_Error( 'wp_remote_og_google_font_empty', __( 'The Google font file download was empty.', 'wp-remote-og-plugins' ) );
		}

		// Verify the payload is a real font before writing it to disk (TTF/TTC/OTF magic bytes).
		$magic = substr( $font_body, 0, 4 );
		if ( ! in_array( $magic, array( "\x00\x01\x00\x00", 'true', 'ttcf', 'OTTO' ), true ) ) {
			return new WP_Error( 'wp_remote_og_google_font_bad_file', __( 'The downloaded file is not a valid font file.', 'wp-remote-og-plugins' ) );
		}

		$directory = WP_Remote_OG_Uploads::ensure_fonts_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$filename  = sanitize_file_name( $font['id'] . '.ttf' );
		$dest_path = trailingslashit( $directory['path'] ) . $filename;

		if ( ! file_put_contents( $dest_path, $font_body ) ) {
			return new WP_Error( 'wp_remote_og_google_font_store', __( 'Unable to store the Google font file.', 'wp-remote-og-plugins' ) );
		}

		$font['css_url']    = esc_url_raw( $css_url );
		$font['url']        = trailingslashit( $directory['url'] ) . rawurlencode( $filename );
		$font['filename']   = $filename;
		$font['extension']  = 'ttf';
		$font['path']       = $dest_path;
		$font['renderable'] = true;
		self::replace_font( $font );

		return $font;
	}

	private static function replace_font( $font ) {
		if ( empty( $font['id'] ) ) {
			return;
		}

		$fonts    = WP_Remote_OG_Plugin::get_fonts();
		$replaced = false;
		foreach ( $fonts as $index => $candidate ) {
			if ( isset( $candidate['id'] ) && $candidate['id'] === $font['id'] ) {
				$fonts[ $index ] = $font;
				$replaced        = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$fonts[] = $font;
		}

		WP_Remote_OG_Plugin::save_fonts( $fonts );
	}

	public static function allowed_mimes() {
		return array(
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
		);
	}

	public static function is_supported_font_path( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'ttf', 'otf', 'woff', 'woff2' ), true );
	}

	/**
	 * Content-based font validation via magic bytes (TTF/TTC/OTF/WOFF/WOFF2).
	 *
	 * More reliable than finfo MIME detection, which reports inconsistent
	 * types for fonts (font/sfnt, application/x-font-ttf, ...) across systems.
	 */
	public static function is_valid_font_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$magic = file_get_contents( $path, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading 4 bytes from a local file to verify its signature.
		return is_string( $magic ) && in_array( $magic, array( "\x00\x01\x00\x00", 'true', 'ttcf', 'OTTO', 'wOFF', 'wOF2' ), true );
	}

	public static function register_font_from_path( $path, $label = '' ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) || ! self::is_supported_font_path( $path ) ) {
			return new WP_Error( 'wp_remote_og_font_type', __( 'Only TTF, OTF, WOFF, and WOFF2 font files are allowed.', 'wp-remote-og-plugins' ) );
		}

		if ( ! self::is_valid_font_file( $path ) ) {
			return new WP_Error( 'wp_remote_og_font_mime', __( 'The file is not a valid font file.', 'wp-remote-og-plugins' ) );
		}

		$directory = WP_Remote_OG_Uploads::ensure_fonts_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$name      = $label ? sanitize_file_name( $label ) : sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) );
		$id        = sanitize_key( $name . '-' . substr( hash( 'sha256', microtime( true ) . wp_rand() ), 0, 8 ) );
		$filename  = $id . '.' . $extension;
		$dest_path = trailingslashit( $directory['path'] ) . $filename;

		if ( ! copy( $path, $dest_path ) ) {
			return new WP_Error( 'wp_remote_og_font_copy', __( 'Unable to store font file.', 'wp-remote-og-plugins' ) );
		}

		$font = array(
			'source'      => 'upload',
			'id'          => $id,
			'label'       => sanitize_text_field( $name ),
			'filename'    => $filename,
			'extension'   => $extension,
			'path'        => $dest_path,
			'url'         => trailingslashit( $directory['url'] ) . rawurlencode( $filename ),
			'uploaded_at' => current_time( 'mysql' ),
			'renderable'  => in_array( $extension, array( 'ttf', 'otf' ), true ),
		);

		$fonts   = WP_Remote_OG_Plugin::get_fonts();
		$fonts[] = $font;
		WP_Remote_OG_Plugin::save_fonts( $fonts );

		return $font;
	}

	public static function upload_from_request( $file ) {
		if ( empty( $file ) || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'wp_remote_og_font_missing', __( 'No font file was uploaded.', 'wp-remote-og-plugins' ) );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! self::is_supported_font_path( $file['name'] ) ) {
			return new WP_Error( 'wp_remote_og_font_type', __( 'Only TTF, OTF, WOFF, and WOFF2 font files are allowed.', 'wp-remote-og-plugins' ) );
		}

		if ( ! self::is_valid_font_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'wp_remote_og_font_mime', __( 'The uploaded file is not a valid font file.', 'wp-remote-og-plugins' ) );
		}

		$directory = WP_Remote_OG_Uploads::ensure_fonts_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$name      = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		$id        = sanitize_key( $name . '-' . substr( hash( 'sha256', microtime( true ) . wp_rand() ), 0, 8 ) );
		$filename  = $id . '.' . $extension;
		$dest_path = trailingslashit( $directory['path'] ) . $filename;

		if ( ! copy( $file['tmp_name'], $dest_path ) ) {
			return new WP_Error( 'wp_remote_og_font_move', __( 'Unable to store uploaded font.', 'wp-remote-og-plugins' ) );
		}

		$font = array(
			'source'      => 'upload',
			'id'         => $id,
			'label'      => sanitize_text_field( $name ),
			'filename'   => $filename,
			'extension'  => $extension,
			'path'       => $dest_path,
			'url'        => trailingslashit( $directory['url'] ) . rawurlencode( $filename ),
			'uploaded_at'=> current_time( 'mysql' ),
			'renderable' => in_array( $extension, array( 'ttf', 'otf' ), true ),
		);

		$fonts   = WP_Remote_OG_Plugin::get_fonts();
		$fonts[] = $font;
		WP_Remote_OG_Plugin::save_fonts( $fonts );

		return $font;
	}

	public static function get_font_by_id( $id ) {
		$id = sanitize_key( $id );
		foreach ( WP_Remote_OG_Plugin::get_fonts() as $font ) {
			if ( isset( $font['id'] ) && $font['id'] === $id ) {
				return $font;
			}
		}
		return null;
	}

	public static function get_renderable_font_path( $font_id = '' ) {
		if ( $font_id ) {
			$font = self::get_font_by_id( $font_id );
			if ( $font && ! empty( $font['renderable'] ) && ! empty( $font['path'] ) && file_exists( $font['path'] ) ) {
				return $font['path'];
			}

			if ( $font && 'google' === ( $font['source'] ?? '' ) ) {
				$font = self::ensure_google_font_renderable( $font );
				if ( ! is_wp_error( $font ) && ! empty( $font['path'] ) && file_exists( $font['path'] ) ) {
					return $font['path'];
				}
			}
		}

		$candidates = array(
			'/System/Library/Fonts/Supplemental/Arial.ttf',
			'/Library/Fonts/Arial.ttf',
			'/System/Library/Fonts/Supplemental/Helvetica.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/TTF/DejaVuSans.ttf',
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return '';
	}
}

final class WP_Remote_OG_Renderer {
	public static function render_post( $post_id, $template, $destination, $force_engine = '' ) {
		$template = WP_Remote_OG_Plugin::sanitize_template( $template );
		$engine   = self::engine( $force_engine );

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		if ( 'imagick' === $engine ) {
			return self::render_with_imagick( $post_id, $template, $destination );
		}

		return self::render_with_gd( $post_id, $template, $destination );
	}

	public static function engine( $force_engine = '' ) {
		if ( 'gd' === $force_engine ) {
			return function_exists( 'imagecreatetruecolor' ) ? 'gd' : new WP_Error( 'wp_remote_og_no_gd', __( 'GD is not available.', 'wp-remote-og-plugins' ) );
		}

		if ( 'imagick' === $force_engine ) {
			return class_exists( 'Imagick' ) ? 'imagick' : new WP_Error( 'wp_remote_og_no_imagick', __( 'Imagick is not available.', 'wp-remote-og-plugins' ) );
		}

		if ( class_exists( 'Imagick' ) ) {
			return 'imagick';
		}

		if ( function_exists( 'imagecreatetruecolor' ) ) {
			return 'gd';
		}

		return new WP_Error( 'wp_remote_og_no_renderer', __( 'Neither Imagick nor GD is available.', 'wp-remote-og-plugins' ) );
	}

	private static function render_with_imagick( $post_id, $template, $destination ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return new WP_Error( 'wp_remote_og_no_imagick', __( 'Imagick is not available.', 'wp-remote-og-plugins' ) );
		}

		try {
			$image = new Imagick();
			$image->newImage( WP_Remote_OG_Plugin::CANVAS_WIDTH, WP_Remote_OG_Plugin::CANVAS_HEIGHT, new ImagickPixel( '#f8fafc' ) );
			$image->setImageFormat( 'png' );

			$background_path = WP_Remote_OG_Uploads::attachment_path_from_template_background( $template );
			if ( $background_path && file_exists( $background_path ) ) {
				$background = new Imagick( $background_path );
				$background->cropThumbnailImage( WP_Remote_OG_Plugin::CANVAS_WIDTH, WP_Remote_OG_Plugin::CANVAS_HEIGHT );
				$image->compositeImage( $background, Imagick::COMPOSITE_OVER, 0, 0 );
				$background->clear();
			}

			foreach ( $template['layers'] as $layer ) {
				if ( ! WP_Remote_OG_Dynamic_Fields::layer_is_visible( $post_id, $layer ) ) {
					continue;
				}

				if ( 'line' === ( $layer['type'] ?? 'text' ) ) {
					self::draw_line_imagick( $image, $layer );
					continue;
				}

				if ( 'image' === ( $layer['type'] ?? 'text' ) ) {
					$source = WP_Remote_OG_Dynamic_Fields::resolve_layer_image( $post_id, $layer['content'] );
					self::draw_image_imagick( $image, $layer, $source );
					continue;
				}

				$warnings = array();
				$text     = WP_Remote_OG_Dynamic_Fields::resolve_text( $post_id, $layer['content'], $warnings );
				self::draw_text_imagick( $image, $layer, $text );
			}

			$image->writeImage( $destination );
			$image->clear();
		} catch ( Exception $exception ) {
			return new WP_Error( 'wp_remote_og_imagick_error', sanitize_text_field( $exception->getMessage() ) );
		}

		return file_exists( $destination ) ? true : new WP_Error( 'wp_remote_og_render_failed', __( 'Image file was not created.', 'wp-remote-og-plugins' ) );
	}

	private static function draw_text_imagick( $image, $layer, $text ) {
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( $layer['color'] ) );

		$font_path = WP_Remote_OG_Fonts::get_renderable_font_path( $layer['font_id'] );
		if ( $font_path ) {
			$draw->setFont( $font_path );
		}

		$lines       = self::fit_lines( $text, $layer, $font_path );
		$font_size   = (float) $lines['font_size'];
		$draw->setFontSize( $font_size );
		$line_height = max( 1, (float) $layer['line_height'] ) * $font_size;
		$y           = (float) $layer['y'] + $font_size;

		foreach ( $lines['lines'] as $line ) {
			$x = self::aligned_x( $line, $layer, $font_path, $font_size );
			$image->annotateImage( $draw, $x, $y, 0, $line );
			$y += $line_height;
		}
	}

	private static function draw_line_imagick( $image, $layer ) {
		$width  = max( 1, (float) $layer['width'] );
		$height = max( 1, (float) $layer['height'] );
		$x      = (float) $layer['x'];
		$y      = (float) $layer['y'];

		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( $layer['color'] ) );
		$draw->rectangle( $x, $y, $x + $width, $y + $height );
		$image->drawImage( $draw );
	}

	private static function draw_image_imagick( $image, $layer, $source ) {
		$path = self::resolve_image_layer_path( $source );
		if ( ! $path ) {
			return;
		}

		try {
			$dst_w = max( 1, (int) round( $layer['width'] ) );
			$dst_h = max( 1, (int) round( $layer['height'] ) );
			$fit   = self::normalize_image_fit( isset( $layer['image_fit'] ) ? $layer['image_fit'] : 'contain' );

			$overlay     = new Imagick( $path );
			$destination = new Imagick();
			$destination->newImage( $dst_w, $dst_h, new ImagickPixel( 'transparent' ) );
			$destination->setImageFormat( 'png' );

			if ( 'cover' === $fit ) {
				$overlay->cropThumbnailImage( $dst_w, $dst_h );
				$destination->compositeImage( $overlay, Imagick::COMPOSITE_OVER, 0, 0 );
			} elseif ( 'stretch' === $fit ) {
				$overlay->resizeImage( $dst_w, $dst_h, Imagick::FILTER_LANCZOS, 1, false );
				$destination->compositeImage( $overlay, Imagick::COMPOSITE_OVER, 0, 0 );
			} else {
				$overlay->resizeImage( $dst_w, $dst_h, Imagick::FILTER_LANCZOS, 1, true );
				$offset_x = (int) floor( ( $dst_w - $overlay->getImageWidth() ) / 2 );
				$offset_y = (int) floor( ( $dst_h - $overlay->getImageHeight() ) / 2 );
				$destination->compositeImage( $overlay, Imagick::COMPOSITE_OVER, $offset_x, $offset_y );
			}

			self::apply_image_shape_imagick( $destination, isset( $layer['image_shape'] ) ? $layer['image_shape'] : 'square' );
			$image->compositeImage( $destination, Imagick::COMPOSITE_OVER, (int) $layer['x'], (int) $layer['y'] );
			$overlay->clear();
			$overlay->destroy();
			$destination->clear();
			$destination->destroy();
		} catch ( Exception $exception ) {
			return;
		}
	}

	private static function apply_image_shape_imagick( $image, $shape ) {
		$shape = self::normalize_image_shape( $shape );
		if ( 'square' === $shape ) {
			return;
		}

		$width  = (int) $image->getImageWidth();
		$height = (int) $image->getImageHeight();
		if ( $width < 1 || $height < 1 ) {
			return;
		}

		$mask = new Imagick();
		$mask->newImage( $width, $height, new ImagickPixel( 'transparent' ) );
		$mask->setImageFormat( 'png' );

		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( 'white' ) );
		if ( 'circle' === $shape ) {
			$draw->ellipse( $width / 2, $height / 2, $width / 2, $height / 2, 0, 360 );
		} else {
			$radius = self::image_shape_radius( $width, $height );
			$draw->roundRectangle( 0, 0, $width, $height, $radius, $radius );
		}

		$mask->drawImage( $draw );
		$composite = defined( 'Imagick::COMPOSITE_DSTIN' ) ? constant( 'Imagick::COMPOSITE_DSTIN' ) : null;
		if ( null === $composite ) {
			$mask->clear();
			$mask->destroy();
			return;
		}

		$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );
		$image->compositeImage( $mask, $composite, 0, 0 );
		$mask->clear();
		$mask->destroy();
	}

	private static function render_with_gd( $post_id, $template, $destination ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'wp_remote_og_no_gd', __( 'GD is not available.', 'wp-remote-og-plugins' ) );
		}

		$image = imagecreatetruecolor( WP_Remote_OG_Plugin::CANVAS_WIDTH, WP_Remote_OG_Plugin::CANVAS_HEIGHT );
		if ( ! $image ) {
			return new WP_Error( 'wp_remote_og_gd_create', __( 'Unable to create GD image canvas.', 'wp-remote-og-plugins' ) );
		}

		$background = self::allocate_color( $image, '#f8fafc' );
		imagefilledrectangle( $image, 0, 0, WP_Remote_OG_Plugin::CANVAS_WIDTH, WP_Remote_OG_Plugin::CANVAS_HEIGHT, $background );
		self::draw_background_gd( $image, $template );

		foreach ( $template['layers'] as $layer ) {
			if ( ! WP_Remote_OG_Dynamic_Fields::layer_is_visible( $post_id, $layer ) ) {
				continue;
			}

			if ( 'line' === ( $layer['type'] ?? 'text' ) ) {
				self::draw_line_gd( $image, $layer );
				continue;
			}

			if ( 'image' === ( $layer['type'] ?? 'text' ) ) {
				$source = WP_Remote_OG_Dynamic_Fields::resolve_layer_image( $post_id, $layer['content'] );
				self::draw_image_gd( $image, $layer, $source );
				continue;
			}

			$warnings = array();
			$text     = WP_Remote_OG_Dynamic_Fields::resolve_text( $post_id, $layer['content'], $warnings );
			self::draw_text_gd( $image, $layer, $text );
		}

		$result = imagepng( $image, $destination );
		imagedestroy( $image );

		if ( ! $result || ! file_exists( $destination ) ) {
			return new WP_Error( 'wp_remote_og_render_failed', __( 'Unable to write generated PNG.', 'wp-remote-og-plugins' ) );
		}

		return true;
	}

	private static function draw_background_gd( $canvas, $template ) {
		$path = WP_Remote_OG_Uploads::attachment_path_from_template_background( $template );
		if ( ! $path || ! file_exists( $path ) ) {
			self::draw_default_background( $canvas );
			return;
		}

		$info = getimagesize( $path );
		if ( ! $info ) {
			self::draw_default_background( $canvas );
			return;
		}

		$source = null;
		if ( IMAGETYPE_JPEG === $info[2] && function_exists( 'imagecreatefromjpeg' ) ) {
			$source = imagecreatefromjpeg( $path );
		} elseif ( IMAGETYPE_PNG === $info[2] && function_exists( 'imagecreatefrompng' ) ) {
			$source = imagecreatefrompng( $path );
		} elseif ( IMAGETYPE_WEBP === $info[2] && function_exists( 'imagecreatefromwebp' ) ) {
			$source = imagecreatefromwebp( $path );
		}

		if ( ! $source ) {
			self::draw_default_background( $canvas );
			return;
		}

		$src_w = imagesx( $source );
		$src_h = imagesy( $source );
		$scale = max( WP_Remote_OG_Plugin::CANVAS_WIDTH / $src_w, WP_Remote_OG_Plugin::CANVAS_HEIGHT / $src_h );
		$dst_w = (int) ceil( $src_w * $scale );
		$dst_h = (int) ceil( $src_h * $scale );
		$dst_x = (int) floor( ( WP_Remote_OG_Plugin::CANVAS_WIDTH - $dst_w ) / 2 );
		$dst_y = (int) floor( ( WP_Remote_OG_Plugin::CANVAS_HEIGHT - $dst_h ) / 2 );

		imagecopyresampled( $canvas, $source, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $src_w, $src_h );
		imagedestroy( $source );
	}

	private static function draw_default_background( $canvas ) {
		$accent = self::allocate_color( $canvas, '#dbeafe' );
		$muted  = self::allocate_color( $canvas, '#e5e7eb' );
		imagefilledrectangle( $canvas, 0, 0, WP_Remote_OG_Plugin::CANVAS_WIDTH, 110, $accent );
		imagefilledrectangle( $canvas, 0, 555, WP_Remote_OG_Plugin::CANVAS_WIDTH, WP_Remote_OG_Plugin::CANVAS_HEIGHT, $muted );
	}

	private static function draw_text_gd( $image, $layer, $text ) {
		$font_path = WP_Remote_OG_Fonts::get_renderable_font_path( $layer['font_id'] );
		$color     = self::allocate_color( $image, $layer['color'] );
		$fit       = self::fit_lines( $text, $layer, $font_path );

		if ( $font_path && function_exists( 'imagettftext' ) ) {
			$font_size   = (float) $fit['font_size'];
			$line_height = max( 1, (float) $layer['line_height'] ) * $font_size;
			$y           = (float) $layer['y'] + $font_size;

			foreach ( $fit['lines'] as $line ) {
				$x = self::aligned_x( $line, $layer, $font_path, $font_size );
				imagettftext( $image, $font_size, 0, (int) $x, (int) $y, $color, $font_path, $line );
				$y += $line_height;
			}
			return;
		}

		$font        = 5;
		$line_height = imagefontheight( $font ) + 4;
		$y           = (int) $layer['y'];
		foreach ( $fit['lines'] as $line ) {
			$x = (int) $layer['x'];
			if ( 'center' === $layer['align'] ) {
				$x = (int) ( $layer['x'] + ( $layer['width'] - imagefontwidth( $font ) * strlen( $line ) ) / 2 );
			} elseif ( 'right' === $layer['align'] ) {
				$x = (int) ( $layer['x'] + $layer['width'] - imagefontwidth( $font ) * strlen( $line ) );
			}
			imagestring( $image, $font, max( (int) $layer['x'], $x ), $y, $line, $color );
			$y += $line_height;
		}
	}

	private static function draw_line_gd( $image, $layer ) {
		$color  = self::allocate_color( $image, $layer['color'] );
		$x1     = (int) round( $layer['x'] );
		$y1     = (int) round( $layer['y'] );
		$width  = max( 1, (int) round( $layer['width'] ) );
		$height = max( 1, (int) round( $layer['height'] ) );
		$x2     = min( WP_Remote_OG_Plugin::CANVAS_WIDTH - 1, $x1 + $width - 1 );
		$y2     = min( WP_Remote_OG_Plugin::CANVAS_HEIGHT - 1, $y1 + $height - 1 );

		if ( $x1 > $x2 || $y1 > $y2 ) {
			return;
		}

		imagefilledrectangle( $image, $x1, $y1, $x2, $y2, $color );
	}

	private static function draw_image_gd( $image, $layer, $source ) {
		$path = self::resolve_image_layer_path( $source );
		if ( ! $path ) {
			return;
		}

		$info = getimagesize( $path );
		if ( ! is_array( $info ) ) {
			return;
		}

		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
					return;
				}
				$source_image = imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_PNG:
				if ( ! function_exists( 'imagecreatefrompng' ) ) {
					return;
				}
				$source_image = imagecreatefrompng( $path );
				break;
			case IMAGETYPE_WEBP:
				if ( ! function_exists( 'imagecreatefromwebp' ) ) {
					return;
				}
				$source_image = imagecreatefromwebp( $path );
				break;
			case IMAGETYPE_GIF:
				if ( ! function_exists( 'imagecreatefromgif' ) ) {
					return;
				}
				$source_image = imagecreatefromgif( $path );
				break;
			default:
				return;
		}

		if ( ! is_resource( $source_image ) && ! ( is_object( $source_image ) && $source_image instanceof GdImage ) ) {
			return;
		}

		$src_w = imagesx( $source_image );
		$src_h = imagesy( $source_image );
		$dst_w = max( 1, (int) round( $layer['width'] ) );
		$dst_h = max( 1, (int) round( $layer['height'] ) );
		$fit   = self::normalize_image_fit( isset( $layer['image_fit'] ) ? $layer['image_fit'] : 'contain' );

		$destination = imagecreatetruecolor( $dst_w, $dst_h );
		imagealphablending( $destination, false );
		imagesavealpha( $destination, true );
		$transparent = imagecolorallocatealpha( $destination, 0, 0, 0, 127 );
		imagefill( $destination, 0, 0, $transparent );

		if ( 'cover' === $fit ) {
			$dst_ratio = $dst_w / $dst_h;
			$src_ratio = $src_w / $src_h;
			if ( $src_ratio > $dst_ratio ) {
				$crop_w = (int) round( $src_h * $dst_ratio );
				$crop_h = $src_h;
				$src_x  = (int) floor( ( $src_w - $crop_w ) / 2 );
				$src_y  = 0;
			} else {
				$crop_w = $src_w;
				$crop_h = (int) round( $src_w / $dst_ratio );
				$src_x  = 0;
				$src_y  = (int) floor( ( $src_h - $crop_h ) / 2 );
			}
			imagecopyresampled( $destination, $source_image, 0, 0, $src_x, $src_y, $dst_w, $dst_h, $crop_w, $crop_h );
		} elseif ( 'stretch' === $fit ) {
			imagecopyresampled( $destination, $source_image, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h );
		} else {
			$scale  = min( $dst_w / $src_w, $dst_h / $src_h );
			$draw_w = max( 1, (int) round( $src_w * $scale ) );
			$draw_h = max( 1, (int) round( $src_h * $scale ) );
			$dst_x  = (int) floor( ( $dst_w - $draw_w ) / 2 );
			$dst_y  = (int) floor( ( $dst_h - $draw_h ) / 2 );
			imagecopyresampled( $destination, $source_image, $dst_x, $dst_y, 0, 0, $draw_w, $draw_h, $src_w, $src_h );
		}

		self::apply_image_shape_gd( $destination, isset( $layer['image_shape'] ) ? $layer['image_shape'] : 'square' );

		imagecopy( $image, $destination, (int) $layer['x'], (int) $layer['y'], 0, 0, $dst_w, $dst_h );
		imagedestroy( $destination );
		imagedestroy( $source_image );
	}

	private static function apply_image_shape_gd( $image, $shape ) {
		$shape = self::normalize_image_shape( $shape );
		if ( 'square' === $shape ) {
			return;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		if ( $width < 1 || $height < 1 ) {
			return;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
		$radius      = self::image_shape_radius( $width, $height );

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				if ( self::is_point_inside_image_shape( $x + 0.5, $y + 0.5, $width, $height, $shape, $radius ) ) {
					continue;
				}

				imagesetpixel( $image, $x, $y, $transparent );
			}
		}
	}

	private static function normalize_image_shape( $shape ) {
		return in_array( $shape, array( 'rounded', 'circle' ), true ) ? $shape : 'square';
	}

	private static function normalize_image_fit( $fit ) {
		return in_array( $fit, array( 'cover', 'stretch' ), true ) ? $fit : 'contain';
	}

	private static function image_shape_radius( $width, $height ) {
		return max( 1, (int) round( min( $width, $height ) * 0.18 ) );
	}

	private static function is_point_inside_image_shape( $x, $y, $width, $height, $shape, $radius ) {
		if ( 'circle' === $shape ) {
			$center_x = $width / 2;
			$center_y = $height / 2;
			$rx       = max( 1, $width / 2 );
			$ry       = max( 1, $height / 2 );

			return ( pow( ( $x - $center_x ) / $rx, 2 ) + pow( ( $y - $center_y ) / $ry, 2 ) ) <= 1;
		}

		if ( $x >= $radius && $x <= $width - $radius ) {
			return true;
		}

		if ( $y >= $radius && $y <= $height - $radius ) {
			return true;
		}

		$corner_x = $x < $radius ? $radius : $width - $radius;
		$corner_y = $y < $radius ? $radius : $height - $radius;

		return pow( $x - $corner_x, 2 ) + pow( $y - $corner_y, 2 ) <= pow( $radius, 2 );
	}

	private static function fit_lines( $text, $layer, $font_path ) {
		$text          = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		$font_size     = (float) $layer['font_size'];
		$min_font_size = (float) $layer['min_font_size'];
		$max_width     = max( 20, (float) $layer['width'] );
		$max_height    = max( 20, (float) $layer['height'] );
		$max_lines     = max( 1, (int) $layer['max_lines'] );

		for ( $size = $font_size; $size >= $min_font_size; $size-- ) {
			$lines       = self::wrap_text( $text, $size, $font_path, $max_width );
			$line_height = max( 1, (float) $layer['line_height'] ) * $size;

			if ( count( $lines ) <= $max_lines && count( $lines ) * $line_height <= $max_height ) {
				return array(
					'font_size' => $size,
					'lines'     => $lines,
				);
			}
		}

		$lines = self::wrap_text( $text, $min_font_size, $font_path, $max_width );
		$lines = array_slice( $lines, 0, $max_lines );
		if ( empty( $lines ) ) {
			$lines = array( '' );
		}

		$last_index           = count( $lines ) - 1;
		$lines[ $last_index ] = self::ellipsis_to_width( $lines[ $last_index ], $min_font_size, $font_path, $max_width );

		return array(
			'font_size' => $min_font_size,
			'lines'     => $lines,
		);
	}

	private static function wrap_text( $text, $font_size, $font_path, $max_width ) {
		if ( '' === $text ) {
			return array( '' );
		}

		$words = preg_split( '/\s+/', $text );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			$test = '' === $line ? $word : $line . ' ' . $word;
			if ( self::text_width( $test, $font_size, $font_path ) <= $max_width ) {
				$line = $test;
				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
			}

			if ( self::text_width( $word, $font_size, $font_path ) > $max_width ) {
				$lines[] = self::ellipsis_to_width( $word, $font_size, $font_path, $max_width );
				$line    = '';
			} else {
				$line = $word;
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	private static function ellipsis_to_width( $text, $font_size, $font_path, $max_width ) {
		$ellipsis = '...';
		$text     = rtrim( $text );

		while ( '' !== $text && self::text_width( $text . $ellipsis, $font_size, $font_path ) > $max_width ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, -1 ) : substr( $text, 0, -1 );
		}

		return rtrim( $text ) . $ellipsis;
	}

	private static function text_width( $text, $font_size, $font_path ) {
		if ( $font_path && function_exists( 'imagettfbbox' ) ) {
			$box = imagettfbbox( $font_size, 0, $font_path, $text );
			if ( is_array( $box ) ) {
				return abs( $box[2] - $box[0] );
			}
		}

		return strlen( $text ) * max( 7, $font_size * 0.55 );
	}

	private static function aligned_x( $line, $layer, $font_path, $font_size ) {
		$x = (float) $layer['x'];
		if ( 'left' === $layer['align'] ) {
			return $x;
		}

		$width = self::text_width( $line, $font_size, $font_path );
		if ( 'center' === $layer['align'] ) {
			return $x + ( (float) $layer['width'] - $width ) / 2;
		}

		return $x + (float) $layer['width'] - $width;
	}

	private static function allocate_color( $image, $hex ) {
		$hex = ltrim( WP_Remote_OG_Plugin::sanitize_hex_color( $hex ), '#' );
		return imagecolorallocate( $image, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	private static function resolve_image_layer_path( $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return '';
		}

		if ( ctype_digit( $source ) ) {
			$source_path = get_attached_file( absint( $source ) );
			if ( $source_path && file_exists( $source_path ) ) {
				return $source_path;
			}
		}

		$source = esc_url_raw( $source );
		if ( '' === $source ) {
			return '';
		}

		if ( file_exists( $source ) && is_file( $source ) ) {
			return $source;
		}

		$uploads = wp_upload_dir();
		if ( isset( $uploads['baseurl'] ) && 0 === strpos( $source, $uploads['baseurl'] ) ) {
			$relative = ltrim( substr( $source, strlen( $uploads['baseurl'] ) ), '/' );
			$path     = trailingslashit( $uploads['basedir'] ) . $relative;
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		$attachment_id = attachment_url_to_postid( $source );
		if ( $attachment_id ) {
			$source_path = get_attached_file( absint( $attachment_id ) );
			if ( $source_path && file_exists( $source_path ) ) {
				return $source_path;
			}
		}

		return '';
	}
}

final class WP_Remote_OG_Storage {
	public static function generated_filename( $post_id ) {
		$hash = substr( hash( 'sha256', $post_id . '|' . microtime( true ) . '|' . wp_rand() ), 0, 8 );
		return sprintf( 'post-%d-og-%s.png', absint( $post_id ), $hash );
	}

	public static function metadata( $post_id ) {
		return array(
			'url'              => get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true ),
			'path'             => get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_PATH, true ),
			'hash'             => get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_HASH, true ),
			'generated_at'     => get_post_meta( $post_id, WP_Remote_OG_Plugin::META_GENERATED_AT, true ),
			'template_version' => get_post_meta( $post_id, WP_Remote_OG_Plugin::META_TEMPLATE_VERSION, true ),
		);
	}

	public static function save_metadata( $post_id, $path, $url, $hash ) {
		update_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, esc_url_raw( $url ) );
		update_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_PATH, $path );
		update_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_HASH, $hash );
		update_post_meta( $post_id, WP_Remote_OG_Plugin::META_GENERATED_AT, current_time( 'mysql' ) );
		update_post_meta( $post_id, WP_Remote_OG_Plugin::META_TEMPLATE_VERSION, get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_VERSION, '' ) );
	}

	public static function is_active_generated_path( $path ) {
		global $wpdb;

		if ( ! WP_Remote_OG_Uploads::is_safe_path( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reverse meta_value lookup has no WP API equivalent; used only during admin cleanup.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				WP_Remote_OG_Plugin::META_IMAGE_PATH,
				$path
			)
		);

		return $post_id > 0 && 'post' === get_post_type( $post_id );
	}

	public static function delete_active_image( $post_id ) {
		$path = get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_PATH, true );
		if ( $path ) {
			WP_Remote_OG_Uploads::safe_delete( $path );
		}

		delete_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_URL );
		delete_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_PATH );
		delete_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_HASH );
		delete_post_meta( $post_id, WP_Remote_OG_Plugin::META_GENERATED_AT );
		delete_post_meta( $post_id, WP_Remote_OG_Plugin::META_TEMPLATE_VERSION );
	}
}

final class WP_Remote_OG_Generator {
	private static $generating = array();

	public static function init() {
		add_action( 'save_post_post', array( __CLASS__, 'auto_generate_on_save' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_on_permanent_delete' ), 10, 2 );
	}

	public static function generate_for_post( $post_id, $args = array() ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wp_remote_og_invalid_post', __( 'OG images can only be generated for standard posts.', 'wp-remote-og-plugins' ) );
		}

		$directory = WP_Remote_OG_Uploads::ensure_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$template = isset( $args['template'] ) ? $args['template'] : WP_Remote_OG_Plugin::get_template();
		$filename = WP_Remote_OG_Storage::generated_filename( $post_id );
		$path     = trailingslashit( $directory['path'] ) . $filename;
		$url      = trailingslashit( $directory['url'] ) . $filename;
		$previous = WP_Remote_OG_Storage::metadata( $post_id );

		$result = WP_Remote_OG_Renderer::render_post( $post_id, $template, $path, isset( $args['engine'] ) ? $args['engine'] : '' );
		if ( is_wp_error( $result ) ) {
			if ( file_exists( $path ) ) {
				WP_Remote_OG_Uploads::safe_delete( $path );
			}
			self::log( $post_id, 'error', $result->get_error_message() );
			return $result;
		}

		$hash = hash_file( 'sha256', $path );
		WP_Remote_OG_Storage::save_metadata( $post_id, $path, $url, $hash );

		if ( ! empty( $previous['path'] ) && $previous['path'] !== $path ) {
			WP_Remote_OG_Uploads::safe_delete( $previous['path'] );
		}

		self::log( $post_id, 'success', sprintf( 'Generated %s', basename( $path ) ) );

		return array(
			'post_id' => $post_id,
			'path'    => $path,
			'url'     => $url,
			'hash'    => $hash,
		);
	}

	public static function auto_generate_on_save( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( isset( self::$generating[ $post_id ] ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		self::$generating[ $post_id ] = true;
		self::generate_for_post( $post_id );
		unset( self::$generating[ $post_id ] );
	}

	public static function delete_on_permanent_delete( $post_id, $post = null ) {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}

		WP_Remote_OG_Storage::delete_active_image( $post_id );
	}

	public static function bulk_post_ids( $mode = 'all' ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( 'missing' === $mode ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional one-off query used by the admin bulk-generation tool.
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => WP_Remote_OG_Plugin::META_IMAGE_URL,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => WP_Remote_OG_Plugin::META_IMAGE_URL,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		return array_map( 'absint', get_posts( $args ) );
	}

	public static function log( $post_id, $status, $message ) {
		$log   = get_option( WP_Remote_OG_Plugin::OPTION_GENERATION_LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'post_id' => absint( $post_id ),
			'status'  => sanitize_key( $status ),
			'message' => sanitize_text_field( $message ),
		);
		$log = array_slice( $log, -50 );
		update_option( WP_Remote_OG_Plugin::OPTION_GENERATION_LOG, $log, false );
	}
}

final class WP_Remote_OG_SEO {
	public static function init() {
		add_filter( 'rank_math/opengraph/facebook/image', array( __CLASS__, 'filter_rank_math_image' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/image', array( __CLASS__, 'filter_rank_math_image' ), 20 );
		add_filter( 'rank_math/frontend/twitter/image', array( __CLASS__, 'filter_rank_math_image' ), 20 );
		add_filter( 'rank_math/opengraph/image', array( __CLASS__, 'filter_rank_math_image' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'fallback_meta_tags' ), 20 );
	}

	public static function is_rank_math_active() {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || class_exists( '\RankMath\RankMath' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'seo-by-rank-math/rank-math.php' );
	}

	public static function image_url_for_post( $post_id ) {
		$url = get_post_meta( $post_id, WP_Remote_OG_Plugin::META_IMAGE_URL, true );
		return $url ? esc_url_raw( $url ) : '';
	}

	public static function filter_rank_math_image( $image ) {
		$post_id = get_queried_object_id();
		return self::filter_rank_math_image_for_post( $image, $post_id );
	}

	public static function filter_rank_math_image_for_post( $image, $post_id ) {
		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			return $image;
		}

		$url = self::image_url_for_post( $post_id );
		if ( ! $url ) {
			return $image;
		}

		if ( is_array( $image ) ) {
			$image['url'] = $url;
			return $image;
		}

		return $url;
	}

	public static function fallback_meta_tags() {
		if ( is_admin() || is_feed() || wp_is_json_request() || self::is_rank_math_active() || ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		$url     = self::image_url_for_post( $post_id );
		if ( ! $url ) {
			return;
		}

		printf( "\n<meta property=\"og:image\" content=\"%s\" />\n", esc_url( $url ) );
		printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url( $url ) );
	}
}

final class WP_Remote_OG_Presets {
	/**
	 * Loosely-defined built-in presets. Layers are normalized through
	 * WP_Remote_OG_Plugin::sanitize_template() so the returned templates are
	 * canonical (and idempotent under a second sanitize pass).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions() {
		return array(
			array(
				'key'         => 'bold-left',
				'name'        => __( 'Bold Left', 'wp-remote-og-plugins' ),
				'category'    => 'Bold',
				'description' => __( 'Dark canvas with a vibrant accent bar and a large left-aligned title.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#0f172a' ),
					self::rect( 'accent', 90, 96, 10, 438, '#6366f1', 'vertical' ),
					self::text( 'title', '{post_title}', 140, 150, 940, 300, '#ffffff', 78, 40, 'left', 3 ),
					self::text( 'meta', '{taxonomy:job_type} • {taxonomy:job_location}', 140, 470, 940, 80, '#a5b4fc', 30, 20, 'left', 1 ),
				),
			),
			array(
				'key'         => 'centered-minimal',
				'name'        => __( 'Centered Minimal', 'wp-remote-og-plugins' ),
				'category'    => 'Minimal',
				'description' => __( 'Clean white background with a centered title and a subtle accent divider.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#ffffff' ),
					self::text( 'title', '{post_title}', 120, 200, 960, 180, '#0f172a', 66, 38, 'center', 3 ),
					self::rect( 'divider', 510, 408, 180, 4, '#6366f1' ),
					self::text( 'meta', '{acf:company_name}', 120, 440, 960, 60, '#64748b', 28, 18, 'center', 1 ),
				),
			),
			array(
				'key'         => 'split-accent',
				'name'        => __( 'Split Accent', 'wp-remote-og-plugins' ),
				'category'    => 'Job Board',
				'description' => __( 'A coloured company panel on the left with the job title on the right.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#ffffff' ),
					self::rect( 'panel', 0, 0, 460, 630, '#4f46e5' ),
					self::text( 'company', '{acf:company_name}', 60, 70, 340, 140, '#ffffff', 34, 22, 'left', 3 ),
					self::text( 'location', '{taxonomy:job_location}', 60, 470, 340, 100, '#c7d2fe', 24, 16, 'left', 2 ),
					self::text( 'title', '{post_title}', 520, 150, 620, 340, '#0f172a', 60, 34, 'left', 4 ),
				),
			),
			array(
				'key'         => 'top-badge',
				'name'        => __( 'Top Badge', 'wp-remote-og-plugins' ),
				'category'    => 'Job Board',
				'description' => __( 'A pill-style badge over a big title, ideal for job type or category.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#f8fafc' ),
					self::rect( 'strip', 0, 0, 1200, 16, '#4f46e5' ),
					self::rect( 'badge', 90, 84, 240, 58, '#eef2ff' ),
					self::text( 'badge-label', '{taxonomy:job_type}', 110, 96, 210, 40, '#4338ca', 26, 18, 'left', 1 ),
					self::text( 'title', '{post_title}', 90, 190, 1020, 320, '#0f172a', 72, 40, 'left', 3 ),
				),
			),
			array(
				'key'         => 'footer-meta',
				'name'        => __( 'Footer Meta', 'wp-remote-og-plugins' ),
				'category'    => 'Editorial',
				'description' => __( 'Editorial title up top with a dark footer strip carrying the metadata.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#ffffff' ),
					self::text( 'title', '{post_title}', 90, 110, 1020, 300, '#111827', 70, 40, 'left', 3 ),
					self::rect( 'footer', 0, 540, 1200, 90, '#111827' ),
					self::text( 'meta', '{acf:company_name} — {taxonomy:job_location}', 90, 562, 1020, 56, '#ffffff', 28, 18, 'left', 1 ),
				),
			),
			array(
				'key'         => 'dark-panel',
				'name'        => __( 'Dark Panel', 'wp-remote-og-plugins' ),
				'category'    => 'Editorial',
				'description' => __( 'Moody dark background with a warm accent rule and a salary highlight.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#111827' ),
					self::rect( 'rule', 90, 150, 120, 6, '#f59e0b' ),
					self::text( 'title', '{post_title}', 90, 190, 1020, 280, '#f8fafc', 68, 38, 'left', 3 ),
					self::text( 'salary', '{acf:salary_range}', 90, 480, 1020, 70, '#fbbf24', 30, 20, 'left', 1 ),
				),
			),
			array(
				'key'         => 'duotone-blocks',
				'name'        => __( 'Duotone Blocks', 'wp-remote-og-plugins' ),
				'category'    => 'Bold',
				'description' => __( 'Two solid colour blocks with a centred title spanning the seam.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'block-top', 0, 0, 1200, 315, '#4f46e5' ),
					self::rect( 'block-bottom', 0, 315, 1200, 315, '#0f172a' ),
					self::text( 'title', '{post_title}', 120, 225, 960, 200, '#ffffff', 64, 36, 'center', 3 ),
					self::text( 'meta', '{taxonomy:job_location}', 120, 430, 960, 60, '#c7d2fe', 26, 18, 'center', 1 ),
				),
			),
			array(
				'key'         => 'corner-brand',
				'name'        => __( 'Corner Brand', 'wp-remote-og-plugins' ),
				'category'    => 'Minimal',
				'description' => __( 'Minimal light layout with a small corner brand mark and a strong title.', 'wp-remote-og-plugins' ),
				'layers'      => array(
					self::rect( 'bg', 0, 0, 1200, 630, '#ffffff' ),
					self::rect( 'mark', 90, 80, 64, 64, '#4f46e5' ),
					self::text( 'title', '{post_title}', 90, 200, 1020, 280, '#0f172a', 66, 38, 'left', 3 ),
					self::text( 'location', '{taxonomy:job_location}', 90, 500, 1020, 60, '#64748b', 26, 18, 'left', 1 ),
				),
			),
		);
	}

	private static function rect( $id, $x, $y, $w, $h, $color, $orientation = 'horizontal' ) {
		return array(
			'id'               => 'preset-' . $id,
			'type'             => 'line',
			'label'            => ucfirst( str_replace( '-', ' ', $id ) ),
			'line_orientation' => 'vertical' === $orientation ? 'vertical' : 'horizontal',
			'x'                => $x,
			'y'                => $y,
			'width'            => $w,
			'height'           => $h,
			'color'            => $color,
		);
	}

	private static function text( $id, $content, $x, $y, $w, $h, $color, $size, $min, $align, $max_lines ) {
		return array(
			'id'            => 'preset-' . $id,
			'type'          => 'text',
			'content'       => $content,
			'label'         => ucfirst( str_replace( '-', ' ', $id ) ),
			'x'             => $x,
			'y'             => $y,
			'width'         => $w,
			'height'        => $h,
			'font_family'   => 'system',
			'font_id'       => '',
			'font_size'     => $size,
			'min_font_size' => $min,
			'color'         => $color,
			'align'         => $align,
			'line_height'   => 1.1,
			'max_lines'     => $max_lines,
		);
	}

	/**
	 * All presets with canonical (sanitized) templates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$presets = array();
		foreach ( self::definitions() as $definition ) {
			$presets[] = array(
				'key'         => $definition['key'],
				'name'        => $definition['name'],
				'category'    => $definition['category'],
				'description' => $definition['description'],
				'template'    => WP_Remote_OG_Plugin::sanitize_template(
					array(
						'background' => array( 'id' => 0, 'url' => '' ),
						'layers'     => $definition['layers'],
					)
				),
			);
		}

		return $presets;
	}

	/**
	 * Fetch a single preset by key.
	 *
	 * @param string $key Preset key.
	 * @return array<string,mixed>|null
	 */
	public static function get( $key ) {
		foreach ( self::all() as $preset ) {
			if ( $preset['key'] === $key ) {
				return $preset;
			}
		}

		return null;
	}

	/**
	 * Distinct categories in definition order.
	 *
	 * @return array<int,string>
	 */
	public static function categories() {
		$categories = array();
		foreach ( self::definitions() as $definition ) {
			if ( ! in_array( $definition['category'], $categories, true ) ) {
				$categories[] = $definition['category'];
			}
		}

		return $categories;
	}
}

final class WP_Remote_OG_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_wp_remote_og_export_template', array( __CLASS__, 'export_template' ) );
		add_action( 'admin_post_wp_remote_og_import_template', array( __CLASS__, 'import_template' ) );

		add_action( 'wp_ajax_wp_remote_og_save_template', array( __CLASS__, 'ajax_save_template' ) );
		add_action( 'wp_ajax_wp_remote_og_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_wp_remote_og_generate_post', array( __CLASS__, 'ajax_generate_post' ) );
		add_action( 'wp_ajax_wp_remote_og_bulk_ids', array( __CLASS__, 'ajax_bulk_ids' ) );
		add_action( 'wp_ajax_wp_remote_og_bulk_process', array( __CLASS__, 'ajax_bulk_process' ) );
		add_action( 'wp_ajax_wp_remote_og_clear_template_notice', array( __CLASS__, 'ajax_clear_template_notice' ) );
		add_action( 'wp_ajax_wp_remote_og_cleanup_orphans', array( __CLASS__, 'ajax_cleanup_orphans' ) );
		add_action( 'wp_ajax_wp_remote_og_google_fonts', array( __CLASS__, 'ajax_google_fonts' ) );
		add_action( 'wp_ajax_wp_remote_og_apply_preset', array( __CLASS__, 'ajax_apply_preset' ) );
		add_action( 'wp_ajax_wp_remote_og_restore_template_backup', array( __CLASS__, 'ajax_restore_template_backup' ) );
	}

	public static function admin_menu() {
		add_menu_page(
			__( 'Social Preview Designer', 'wp-remote-og-plugins' ),
			__( 'Social Preview Designer', 'wp-remote-og-plugins' ),
			WP_Remote_OG_Plugin::capability(),
			'wp-remote-og',
			array( __CLASS__, 'render_dashboard_page' ),
			'dashicons-format-image',
			58
		);

		add_submenu_page( 'wp-remote-og', __( 'Dashboard', 'wp-remote-og-plugins' ), __( 'Dashboard', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og', array( __CLASS__, 'render_dashboard_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Template Editor', 'wp-remote-og-plugins' ), __( 'Template Editor', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-editor', array( __CLASS__, 'render_template_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Templates', 'wp-remote-og-plugins' ), __( 'Templates', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-templates', array( __CLASS__, 'render_templates_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Dynamic Fields', 'wp-remote-og-plugins' ), __( 'Dynamic Fields', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-fields', array( __CLASS__, 'render_fields_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Fonts', 'wp-remote-og-plugins' ), __( 'Fonts', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-fonts', array( __CLASS__, 'render_fonts_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Generation Tools', 'wp-remote-og-plugins' ), __( 'Generation Tools', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-tools', array( __CLASS__, 'render_tools_page' ) );
		add_submenu_page( 'wp-remote-og', __( 'Diagnostics', 'wp-remote-og-plugins' ), __( 'Diagnostics', 'wp-remote-og-plugins' ), WP_Remote_OG_Plugin::capability(), 'wp-remote-og-diagnostics', array( __CLASS__, 'render_diagnostics_page' ) );
	}

	/**
	 * Version string for a bundled asset.
	 *
	 * Uses the file modification time so browsers and edge caches (e.g. a CDN in
	 * front of the site) fetch the current asset whenever it changes, instead of
	 * serving a stale copy keyed on a static plugin version.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$file = WP_REMOTE_OG_DIR . ltrim( $relative_path, '/' );
		$mtime = file_exists( $file ) ? filemtime( $file ) : 0;
		return $mtime ? WP_REMOTE_OG_VERSION . '.' . $mtime : WP_REMOTE_OG_VERSION;
	}

	public static function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin_screen = false !== strpos( (string) $hook, 'wp-remote-og' );
		$is_post_screen   = $screen && isset( $screen->post_type ) && 'post' === $screen->post_type;

		if ( ! $is_plugin_screen && ! $is_post_screen ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wp-remote-og-admin', WP_REMOTE_OG_URL . 'assets/admin.css', array( 'wp-color-picker' ), self::asset_version( 'assets/admin.css' ) );
		wp_enqueue_script( 'wp-remote-og-editor-state', WP_REMOTE_OG_URL . 'assets/editor-state.js', array(), self::asset_version( 'assets/editor-state.js' ), true );
		wp_enqueue_script( 'wp-remote-og-admin', WP_REMOTE_OG_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-resizable', 'wp-color-picker', 'wp-remote-og-editor-state' ), self::asset_version( 'assets/admin.js' ), true );

		wp_localize_script(
			'wp-remote-og-admin',
			'WPRemoteOG',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wp_remote_og_admin' ),
				'template'       => WP_Remote_OG_Plugin::get_template(),
				'fields'         => WP_Remote_OG_Plugin::get_dynamic_fields(),
				'availableTokens' => WP_Remote_OG_Dynamic_Fields::get_available_tokens(),
				'fonts'          => WP_Remote_OG_Plugin::get_fonts(),
				'posts'          => self::post_choices(),
				'canvas'         => array( 'width' => WP_Remote_OG_Plugin::CANVAS_WIDTH, 'height' => WP_Remote_OG_Plugin::CANVAS_HEIGHT ),
				'presets'        => WP_Remote_OG_Presets::all(),
				'presetCategories' => WP_Remote_OG_Presets::categories(),
				'hasBackup'      => (bool) get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP ),
				'editorUrl'      => admin_url( 'admin.php?page=wp-remote-og-editor' ),
				'strings'        => array(
					'saved'      => __( 'Template saved.', 'wp-remote-og-plugins' ),
					'savedShort' => __( 'Saved', 'wp-remote-og-plugins' ),
					'saveError'  => __( 'Save failed', 'wp-remote-og-plugins' ),
					'generating' => __( 'Generating...', 'wp-remote-og-plugins' ),
					'googleFontListFallback' => __( 'Unable to load Google font directory. You can still type the font name manually.', 'wp-remote-og-plugins' ),
					'applyConfirm' => __( 'Apply this template? This replaces your current template design. Your current template will be backed up so you can restore it.', 'wp-remote-og-plugins' ),
					'restoreConfirm' => __( 'Restore your previous template? This replaces the current design.', 'wp-remote-og-plugins' ),
					'applied'    => __( 'Template applied. Open the Template Editor to fine-tune it.', 'wp-remote-og-plugins' ),
					'restored'   => __( 'Previous template restored.', 'wp-remote-og-plugins' ),
					'applyFailed' => __( 'Unable to apply the template.', 'wp-remote-og-plugins' ),
					'previewNote' => __( 'Preview uses sample placeholder content.', 'wp-remote-og-plugins' ),
					'close'      => __( 'Close', 'wp-remote-og-plugins' ),
					'apply'      => __( 'Apply template', 'wp-remote-og-plugins' ),
				),
			)
		);
	}

	public static function admin_notices() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			return;
		}

		$activation_error = get_option( WP_Remote_OG_Plugin::OPTION_ACTIVATION_ERROR );
		if ( $activation_error ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Social Preview Designer:', 'wp-remote-og-plugins' ) . '</strong> ' . esc_html( $activation_error ) . ' ' . esc_html__( 'Image generation will fail until the uploads directory is writable.', 'wp-remote-og-plugins' ) . '</p></div>';
			if ( ! is_wp_error( WP_Remote_OG_Uploads::ensure_directory() ) ) {
				delete_option( WP_Remote_OG_Plugin::OPTION_ACTIVATION_ERROR );
			}
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'wp-remote-og' ) ) {
			return;
		}

		$diagnostics = WP_Remote_OG_Diagnostics::get();
		if ( ! $diagnostics['imagick'] && $diagnostics['gd'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Social Preview Designer is using GD fallback because Imagick is unavailable. Some rendering quality/features may be limited.', 'wp-remote-og-plugins' ) . '</p></div>';
		}

		if ( ! $diagnostics['gd'] && ! $diagnostics['imagick'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Social Preview Designer cannot generate images because neither Imagick nor GD is available.', 'wp-remote-og-plugins' ) . '</p></div>';
		}

		if ( get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ) ) {
			echo '<div class="notice notice-info wp-remote-og-template-dirty"><p><strong>' . esc_html__( 'Template changed. Regenerate all existing OG images now?', 'wp-remote-og-plugins' ) . '</strong> ';
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wp-remote-og-tools&wp_remote_og_prompt=1' ) ) . '">' . esc_html__( 'Open Generation Tools', 'wp-remote-og-plugins' ) . '</a></p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag set by a nonce-verified redirect; no state change.
		if ( isset( $_GET['wp_remote_og_imported'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wp_remote_og_imported'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Template imported successfully.', 'wp-remote-og-plugins' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag set by a nonce-verified redirect; no state change.
		if ( isset( $_GET['wp_remote_og_import_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag; sanitized and mapped to a fixed message list below.
			$error = sanitize_text_field( wp_unslash( $_GET['wp_remote_og_import_error'] ) );
			$messages = array(
				'missing_file' => __( 'No template file was uploaded.', 'wp-remote-og-plugins' ),
				'upload_error' => __( 'The template file could not be uploaded.', 'wp-remote-og-plugins' ),
				'read_error'   => __( 'The template file could not be read.', 'wp-remote-og-plugins' ),
				'json_error'   => __( 'The template file is not valid JSON.', 'wp-remote-og-plugins' ),
				'empty_layers'  => __( 'The template file does not contain any layers.', 'wp-remote-og-plugins' ),
			);
			$message = isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Template import failed.', 'wp-remote-og-plugins' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public static function post_choices() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$choices = array();
		foreach ( $posts as $post ) {
			$choices[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
			);
		}

		return $choices;
	}

	public static function export_template() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export templates.', 'wp-remote-og-plugins' ) );
		}

		check_admin_referer( 'wp_remote_og_export_template' );

		$payload = array(
			'type'        => 'wp-remote-og-template',
			'version'     => WP_REMOTE_OG_VERSION,
			'exported_at' => gmdate( 'c' ),
			'template'    => WP_Remote_OG_Plugin::get_template(),
		);

		$filename = 'wp-remote-og-template-' . gmdate( 'Y-m-d-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function import_template() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to import templates.', 'wp-remote-og-plugins' ) );
		}

		check_admin_referer( 'wp_remote_og_import_template', 'wp_remote_og_import_nonce' );

		$redirect = admin_url( 'admin.php?page=wp-remote-og-editor' );
		if ( empty( $_FILES['wp_remote_og_template_file'] ) || ! is_array( $_FILES['wp_remote_og_template_file'] ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'missing_file', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is verified with is_uploaded_file() below; file contents are JSON-decoded and run through sanitize_template().
		$file = $_FILES['wp_remote_og_template_file'];
		if ( ! empty( $file['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'upload_error', $redirect ) );
			exit;
		}

		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'read_error', $redirect ) );
			exit;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( $contents ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'read_error', $redirect ) );
			exit;
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'json_error', $redirect ) );
			exit;
		}

		$template = isset( $decoded['template'] ) && is_array( $decoded['template'] ) ? $decoded['template'] : $decoded;
		$template = WP_Remote_OG_Plugin::sanitize_template( $template );
		if ( empty( $template['layers'] ) ) {
			wp_safe_redirect( add_query_arg( 'wp_remote_og_import_error', 'empty_layers', $redirect ) );
			exit;
		}

		WP_Remote_OG_Plugin::save_template( $template );
		wp_safe_redirect( add_query_arg( 'wp_remote_og_imported', '1', $redirect ) );
		exit;
	}

	/**
	 * Ordered navigation definition shared by every plugin screen.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	private static function nav_items() {
		return array(
			array(
				'slug'  => 'wp-remote-og',
				'label' => __( 'Dashboard', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-editor',
				'label' => __( 'Template Editor', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-templates',
				'label' => __( 'Templates', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-fields',
				'label' => __( 'Dynamic Fields', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-fonts',
				'label' => __( 'Fonts', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-tools',
				'label' => __( 'Generation Tools', 'wp-remote-og-plugins' ),
			),
			array(
				'slug'  => 'wp-remote-og-diagnostics',
				'label' => __( 'Diagnostics', 'wp-remote-og-plugins' ),
			),
		);
	}

	/**
	 * Inline SVG brand mark. No external assets.
	 */
	private static function brand_mark() {
		return '<svg class="wpog-logo" width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><rect x="1.5" y="1.5" width="21" height="21" rx="5" fill="currentColor"/><rect x="5" y="12.5" width="14" height="6" rx="1.4" fill="#ffffff" opacity="0.92"/><circle cx="8.5" cy="8" r="2.4" fill="#ffffff"/><path d="M13 11l3-3 3 3" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	/**
	 * Render the shared application shell header (brand + nav pills).
	 *
	 * @param string $active Active page slug.
	 */
	private static function render_shell( $active ) {
		echo '<div class="wpog-shell">';
		echo '<div class="wpog-shell-brand"><span class="wpog-logo-wrap" aria-hidden="true">' . self::brand_mark() . '</span><span class="wpog-shell-name">' . esc_html__( 'Social Preview Designer', 'wp-remote-og-plugins' ) . '</span></div>';
		echo '<nav class="wpog-nav" aria-label="' . esc_attr__( 'Social Preview Designer sections', 'wp-remote-og-plugins' ) . '">';
		foreach ( self::nav_items() as $item ) {
			$is_active = $active === $item['slug'];
			echo '<a class="wpog-nav-pill' . ( $is_active ? ' is-active' : '' ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=' . $item['slug'] ) ) . '">' . esc_html( $item['label'] ) . '</a>';
		}
		echo '</nav>';
		echo '</div>';
	}

	/**
	 * Open a standard plugin page: shell + page header with optional actions.
	 *
	 * @param string $active   Active nav slug.
	 * @param string $title    Page title.
	 * @param string $subtitle Optional descriptive subtitle.
	 * @param string $actions  Optional pre-escaped HTML for the primary action area.
	 */
	private static function page_open( $active, $title, $subtitle = '', $actions = '' ) {
		echo '<div class="wrap wp-remote-og-app wp-remote-og-admin">';
		self::render_shell( $active );
		echo '<div class="wpog-page-head">';
		echo '<div class="wpog-page-head-text">';
		echo '<h1 class="wpog-page-title">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="wpog-page-sub">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		echo '<div class="wpog-page-actions" id="wpog-page-actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller supplies pre-escaped markup.
		echo '</div>';
		echo '<div class="wpog-page-body">';
	}

	private static function page_close() {
		echo '</div></div>';
	}

	/**
	 * Inline SVG icon set (no external icon fonts/assets).
	 *
	 * @param string $name Icon key.
	 * @return string
	 */
	public static function icon( $name ) {
		$open  = '<svg class="wpog-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
		$paths = array(
			'save'    => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
			'undo'    => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
			'redo'    => '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 15-6.7L21 13"/>',
			'plus'    => '<path d="M12 5v14M5 12h14"/>',
			'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
			'refresh' => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
			'link'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'more'    => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
			'text'    => '<path d="M4 7V5h16v2M9 5v14M7 19h4"/>',
			'image'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
			'line'    => '<path d="M4 12h16"/>',
			'chevron' => '<path d="M6 9l6 6 6-6"/>',
			'copy'    => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			'trash'   => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		);

		$path = isset( $paths[ $name ] ) ? $paths[ $name ] : '';
		return $open . $path . '</svg>';
	}

	/**
	 * Small status badge helper.
	 *
	 * @param bool   $ok   Positive state.
	 * @param string $good Text when positive.
	 * @param string $bad  Text when negative.
	 * @param string $tone Optional tone override for the negative state (warn|bad).
	 */
	private static function badge( $ok, $good, $bad, $tone = 'bad' ) {
		$class = $ok ? 'is-good' : ( 'warn' === $tone ? 'is-warn' : 'is-bad' );
		return '<span class="wpog-badge ' . esc_attr( $class ) . '">' . esc_html( $ok ? $good : $bad ) . '</span>';
	}

	/**
	 * Build the readiness checklist rows from live environment data.
	 *
	 * @param array $diagnostics Diagnostics::get() output.
	 * @return array<int,array<string,mixed>>
	 */
	private static function dashboard_readiness( $diagnostics ) {
		$editor_url = admin_url( 'admin.php?page=wp-remote-og-editor' );
		$fields_url = admin_url( 'admin.php?page=wp-remote-og-fields' );
		$fonts_url  = admin_url( 'admin.php?page=wp-remote-og-fonts' );
		$tools_url  = admin_url( 'admin.php?page=wp-remote-og-tools' );

		$engine_ok   = $diagnostics['imagick'] || $diagnostics['gd'];
		$engine_text = $diagnostics['imagick'] ? __( 'Imagick ready', 'wp-remote-og-plugins' ) : ( $diagnostics['gd'] ? __( 'GD fallback', 'wp-remote-og-plugins' ) : __( 'No engine', 'wp-remote-og-plugins' ) );

		$template     = WP_Remote_OG_Plugin::get_template();
		$is_default   = WP_Remote_OG_Plugin::template_hash( $template ) === WP_Remote_OG_Plugin::template_hash( WP_Remote_OG_Plugin::default_template() );
		$layer_count  = isset( $template['layers'] ) ? count( $template['layers'] ) : 0;
		$template_ok  = ! $is_default || $layer_count > 1;

		$fields       = WP_Remote_OG_Plugin::get_dynamic_fields();
		$mapped       = 0;
		foreach ( $fields as $field ) {
			if ( ! empty( $field['enabled'] ) && '{post_title}' !== $field['token'] ) {
				$mapped++;
			}
		}

		$fonts        = WP_Remote_OG_Plugin::get_fonts();

		return array(
			array(
				'label' => __( 'Rendering engine', 'wp-remote-og-plugins' ),
				'ok'    => $engine_ok,
				'tone'  => $diagnostics['imagick'] ? 'good' : ( $diagnostics['gd'] ? 'warn' : 'bad' ),
				'good'  => $engine_text,
				'bad'   => __( 'Not available', 'wp-remote-og-plugins' ),
				'hint'  => $engine_ok ? '' : __( 'Neither Imagick nor GD is available. Ask your host to enable one.', 'wp-remote-og-plugins' ),
				'link'  => $tools_url,
				'action'=> __( 'View diagnostics', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'Uploads directory writable', 'wp-remote-og-plugins' ),
				'ok'    => (bool) $diagnostics['upload_writable'],
				'good'  => __( 'Writable', 'wp-remote-og-plugins' ),
				'bad'   => __( 'Not writable', 'wp-remote-og-plugins' ),
				'hint'  => $diagnostics['upload_writable'] ? '' : __( 'Generated images cannot be saved until the uploads directory is writable.', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'Template configured', 'wp-remote-og-plugins' ),
				'ok'    => $template_ok,
				'tone'  => 'warn',
				'good'  => __( 'Customized', 'wp-remote-og-plugins' ),
				'bad'   => __( 'Using default', 'wp-remote-og-plugins' ),
				'hint'  => $template_ok ? '' : __( 'You are still using the starter template. Customize it or apply a preset.', 'wp-remote-og-plugins' ),
				'link'  => $editor_url,
				'action'=> __( 'Edit template', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'Dynamic fields mapped', 'wp-remote-og-plugins' ),
				'ok'    => $mapped > 0,
				'tone'  => 'warn',
				/* translators: %d: number of mapped dynamic fields. */
				'good'  => sprintf( _n( '%d field mapped', '%d fields mapped', $mapped, 'wp-remote-og-plugins' ), $mapped ),
				'bad'   => __( 'Title only', 'wp-remote-og-plugins' ),
				'hint'  => $mapped > 0 ? '' : __( 'Add taxonomy, ACF, or meta tokens to enrich your images.', 'wp-remote-og-plugins' ),
				'link'  => $fields_url,
				'action'=> __( 'Configure fields', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'Rank Math integration', 'wp-remote-og-plugins' ),
				'ok'    => (bool) $diagnostics['rank_math'],
				'tone'  => 'warn',
				'good'  => __( 'Active', 'wp-remote-og-plugins' ),
				'bad'   => __( 'Inactive', 'wp-remote-og-plugins' ),
				'hint'  => $diagnostics['rank_math'] ? '' : __( 'Rank Math is optional. Generated images still work, but Rank Math lets them override the social image tags.', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'ACF integration', 'wp-remote-og-plugins' ),
				'ok'    => (bool) $diagnostics['acf'],
				'tone'  => 'warn',
				'good'  => __( 'Active', 'wp-remote-og-plugins' ),
				'bad'   => __( 'Inactive', 'wp-remote-og-plugins' ),
				'hint'  => $diagnostics['acf'] ? '' : __( 'ACF is optional. {acf:*} tokens fall back to matching post meta when ACF is inactive.', 'wp-remote-og-plugins' ),
			),
			array(
				'label' => __( 'Custom fonts', 'wp-remote-og-plugins' ),
				'ok'    => ! empty( $fonts ),
				'tone'  => 'warn',
				/* translators: %d: number of custom fonts. */
				'good'  => sprintf( _n( '%d custom font', '%d custom fonts', count( $fonts ), 'wp-remote-og-plugins' ), count( $fonts ) ),
				'bad'   => __( 'System font only', 'wp-remote-og-plugins' ),
				'hint'  => empty( $fonts ) ? __( 'Add a brand font to make your previews unmistakable.', 'wp-remote-og-plugins' ) : '',
				'link'  => $fonts_url,
				'action'=> __( 'Manage fonts', 'wp-remote-og-plugins' ),
			),
		);
	}

	/**
	 * Fetch the most recently generated OG images (single query, no per-post loops).
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function recent_generated_images( $limit = 6 ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'meta_key'       => WP_Remote_OG_Plugin::META_GENERATED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => WP_Remote_OG_Plugin::META_IMAGE_URL,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$url = get_post_meta( $post->ID, WP_Remote_OG_Plugin::META_IMAGE_URL, true );
			if ( ! $url ) {
				continue;
			}
			$items[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => $url,
				'generated' => get_post_meta( $post->ID, WP_Remote_OG_Plugin::META_GENERATED_AT, true ),
				'edit'      => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $items;
	}

	public static function render_dashboard_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$diagnostics = WP_Remote_OG_Diagnostics::get();
		$readiness   = self::dashboard_readiness( $diagnostics );
		$recent      = self::recent_generated_images( 6 );
		$generated   = (int) $diagnostics['generated_count'];
		$missing     = (int) $diagnostics['missing_count'];
		$total       = $generated + $missing;
		$dirty       = (bool) get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY );
		$last_bulk   = $diagnostics['last_bulk_result'];

		$editor_url    = admin_url( 'admin.php?page=wp-remote-og-editor' );
		$templates_url = admin_url( 'admin.php?page=wp-remote-og-templates' );
		$tools_url     = admin_url( 'admin.php?page=wp-remote-og-tools' );

		$actions = '<a class="button button-primary" href="' . esc_url( $editor_url ) . '">' . esc_html__( 'Edit Template', 'wp-remote-og-plugins' ) . '</a>';
		$actions .= '<a class="button" href="' . esc_url( $templates_url ) . '">' . esc_html__( 'Browse Templates', 'wp-remote-og-plugins' ) . '</a>';

		self::page_open( 'wp-remote-og', __( 'Dashboard', 'wp-remote-og-plugins' ), __( 'Your social preview image generation at a glance.', 'wp-remote-og-plugins' ), $actions );

		if ( $dirty ) {
			echo '<div class="wpog-notice is-info"><p><strong>' . esc_html__( 'Your template changed.', 'wp-remote-og-plugins' ) . '</strong> ' . esc_html__( 'Regenerate existing images so they match the new design.', 'wp-remote-og-plugins' ) . ' <a class="button button-small" href="' . esc_url( $tools_url . '&wp_remote_og_prompt=1' ) . '">' . esc_html__( 'Regenerate now', 'wp-remote-og-plugins' ) . '</a></p></div>';
		}
		?>
		<div class="wpog-grid wpog-grid-2">
			<div class="wpog-card">
				<h2 class="wpog-card-title"><?php esc_html_e( 'Setup checklist', 'wp-remote-og-plugins' ); ?></h2>
				<ul class="wpog-checklist">
					<?php foreach ( $readiness as $item ) : ?>
						<?php $tone = isset( $item['tone'] ) ? $item['tone'] : 'bad'; ?>
						<li class="wpog-check-item <?php echo esc_attr( $item['ok'] ? 'is-ok' : 'is-' . $tone ); ?>">
							<span class="wpog-check-icon" aria-hidden="true"><?php echo $item['ok'] ? '&#10003;' : ( 'good' === $tone ? '&#10005;' : '&#33;' ); ?></span>
							<span class="wpog-check-body">
								<span class="wpog-check-label"><?php echo esc_html( $item['label'] ); ?></span>
								<?php echo self::badge( (bool) $item['ok'], $item['good'], $item['bad'], isset( $item['tone'] ) && 'warn' === $item['tone'] ? 'warn' : 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( ! empty( $item['hint'] ) ) : ?>
									<span class="wpog-check-hint"><?php echo esc_html( $item['hint'] ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( ! $item['ok'] && ! empty( $item['link'] ) ) : ?>
								<a class="button button-small wpog-check-fix" href="<?php echo esc_url( $item['link'] ); ?>"><?php echo esc_html( $item['action'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="wpog-card">
				<h2 class="wpog-card-title"><?php esc_html_e( 'Generation health', 'wp-remote-og-plugins' ); ?></h2>
				<div class="wpog-stat-row">
					<div class="wpog-stat">
						<span class="wpog-stat-num"><?php echo esc_html( number_format_i18n( $generated ) ); ?></span>
						<span class="wpog-stat-label"><?php esc_html_e( 'Images generated', 'wp-remote-og-plugins' ); ?></span>
					</div>
					<div class="wpog-stat">
						<span class="wpog-stat-num"><?php echo esc_html( number_format_i18n( $missing ) ); ?></span>
						<span class="wpog-stat-label"><?php esc_html_e( 'Published posts missing an image', 'wp-remote-og-plugins' ); ?></span>
					</div>
				</div>
				<?php if ( $total > 0 ) : ?>
					<?php $pct = (int) round( ( $generated / $total ) * 100 ); ?>
					<div class="wpog-meter" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent covered. */ __( '%d%% of published posts have a generated image.', 'wp-remote-og-plugins' ), $pct ) ); ?>">
						<span class="wpog-meter-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span>
					</div>
					<p class="wpog-stat-caption"><?php echo esc_html( sprintf( /* translators: %d: percent covered. */ __( '%d%% coverage across published posts.', 'wp-remote-og-plugins' ), $pct ) ); ?></p>
				<?php endif; ?>
				<p class="wpog-stat-caption">
					<strong><?php esc_html_e( 'Last bulk run:', 'wp-remote-og-plugins' ); ?></strong>
					<?php echo esc_html( $last_bulk ? $last_bulk : __( 'No bulk run yet.', 'wp-remote-og-plugins' ) ); ?>
				</p>
				<p class="wpog-quick-actions">
					<a class="button button-primary" href="<?php echo esc_url( $editor_url ); ?>"><?php esc_html_e( 'Edit Template', 'wp-remote-og-plugins' ); ?></a>
					<a class="button" href="<?php echo esc_url( $templates_url ); ?>"><?php esc_html_e( 'Browse Templates', 'wp-remote-og-plugins' ); ?></a>
					<a class="button" href="<?php echo esc_url( $tools_url ); ?>"><?php esc_html_e( 'Generate Missing Images', 'wp-remote-og-plugins' ); ?></a>
				</p>
			</div>
		</div>

		<div class="wpog-card">
			<h2 class="wpog-card-title"><?php esc_html_e( 'Recently generated', 'wp-remote-og-plugins' ); ?></h2>
			<?php if ( empty( $recent ) ) : ?>
				<div class="wpog-empty">
					<p class="wpog-empty-title"><?php esc_html_e( 'No social preview images yet.', 'wp-remote-og-plugins' ); ?></p>
					<p><?php esc_html_e( 'Design your template, then generate images for your published posts to see them here.', 'wp-remote-og-plugins' ); ?></p>
					<p class="wpog-quick-actions">
						<a class="button button-primary" href="<?php echo esc_url( $editor_url ); ?>"><?php esc_html_e( 'Design your template', 'wp-remote-og-plugins' ); ?></a>
						<a class="button" href="<?php echo esc_url( $tools_url ); ?>"><?php esc_html_e( 'Generate images', 'wp-remote-og-plugins' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<div class="wpog-recent-grid">
					<?php foreach ( $recent as $item ) : ?>
						<figure class="wpog-recent-item">
							<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img src="<?php echo esc_url( $item['url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy">
							</a>
							<figcaption>
								<span class="wpog-recent-title"><?php echo esc_html( $item['title'] ); ?></span>
								<?php if ( $item['edit'] ) : ?>
									<a class="wpog-recent-link" href="<?php echo esc_url( $item['edit'] ); ?>"><?php esc_html_e( 'Open post to regenerate', 'wp-remote-og-plugins' ); ?></a>
								<?php endif; ?>
							</figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		self::page_close();
	}

	public static function render_templates_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$categories = WP_Remote_OG_Presets::categories();
		$backup     = get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );
		$has_backup = is_array( $backup ) && ! empty( $backup['template'] );
		$backup_at  = $has_backup && ! empty( $backup['created_at'] ) ? $backup['created_at'] : '';

		self::page_open( 'wp-remote-og-templates', __( 'Templates', 'wp-remote-og-plugins' ), __( 'Start from a polished preset, then customize it in the editor. Applying a preset replaces your current template.', 'wp-remote-og-plugins' ) );
		?>
		<div class="wpog-notice is-info wp-remote-og-restore-notice"<?php echo $has_backup ? '' : ' hidden'; ?>>
			<p>
				<strong><?php esc_html_e( 'A previous template is saved.', 'wp-remote-og-plugins' ); ?></strong>
				<span class="wp-remote-og-restore-meta"><?php echo $backup_at ? esc_html( sprintf( /* translators: %s: date/time. */ __( 'Backed up %s.', 'wp-remote-og-plugins' ), $backup_at ) ) : ''; ?></span>
				<button type="button" class="button button-small" id="wp-remote-og-restore-backup"><?php esc_html_e( 'Restore previous template', 'wp-remote-og-plugins' ); ?></button>
			</p>
		</div>
		<div class="wp-remote-og-gallery-status" id="wp-remote-og-gallery-status" aria-live="polite"></div>
		<div class="wp-remote-og-gallery-filters" role="group" aria-label="<?php esc_attr_e( 'Filter templates by category', 'wp-remote-og-plugins' ); ?>">
			<button type="button" class="wpog-filter-pill is-active" data-category="all" aria-pressed="true"><?php esc_html_e( 'All', 'wp-remote-og-plugins' ); ?></button>
			<?php foreach ( $categories as $category ) : ?>
				<button type="button" class="wpog-filter-pill" data-category="<?php echo esc_attr( $category ); ?>" aria-pressed="false"><?php echo esc_html( $category ); ?></button>
			<?php endforeach; ?>
		</div>
		<div class="wp-remote-og-gallery" id="wp-remote-og-gallery"></div>
		<div class="wp-remote-og-preset-modal" id="wp-remote-og-preset-modal" role="dialog" aria-modal="true" aria-labelledby="wp-remote-og-preset-modal-title" aria-describedby="wp-remote-og-preset-modal-desc" hidden>
			<div class="wp-remote-og-preset-modal-backdrop" data-modal-close="1"></div>
			<div class="wp-remote-og-preset-modal-panel">
				<button type="button" class="wp-remote-og-preset-modal-close" data-modal-close="1" aria-label="<?php esc_attr_e( 'Close', 'wp-remote-og-plugins' ); ?>">&times;</button>
				<h2 id="wp-remote-og-preset-modal-title" class="wp-remote-og-preset-modal-heading"></h2>
				<p id="wp-remote-og-preset-modal-desc" class="wp-remote-og-preset-modal-desc"></p>
				<div class="wp-remote-og-preset-modal-preview"></div>
				<p class="wp-remote-og-preset-modal-note"></p>
				<div class="wp-remote-og-preset-modal-actions">
					<button type="button" class="button button-primary" id="wp-remote-og-preset-apply"></button>
				</div>
			</div>
		</div>
		<?php
		self::page_close();
	}

	public static function render_template_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$template = WP_Remote_OG_Plugin::get_template();
		$fields   = WP_Remote_OG_Plugin::get_dynamic_fields();
		$fonts    = WP_Remote_OG_Plugin::get_fonts();
		$posts    = self::post_choices();
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=wp_remote_og_export_template' ), 'wp_remote_og_export_template' );
		?>
		<div class="wrap wp-remote-og-app wp-remote-og-admin wp-remote-og-editor-page">
			<?php self::render_shell( 'wp-remote-og-editor' ); ?>

			<div class="wpog-editor-bar">
				<div class="wpog-editor-bar-left">
					<h1 class="wpog-editor-title"><?php esc_html_e( 'Template Editor', 'wp-remote-og-plugins' ); ?></h1>
					<span class="wpog-dirty" id="wp-remote-og-dirty-indicator" data-saved-label="<?php esc_attr_e( 'All changes saved', 'wp-remote-og-plugins' ); ?>" data-unsaved-label="<?php esc_attr_e( 'Unsaved changes', 'wp-remote-og-plugins' ); ?>"><?php esc_html_e( 'All changes saved', 'wp-remote-og-plugins' ); ?></span>
				</div>
				<div class="wpog-editor-bar-right">
					<label class="screen-reader-text" for="wp-remote-og-preview-post"><?php esc_html_e( 'Preview post', 'wp-remote-og-plugins' ); ?></label>
					<select id="wp-remote-og-preview-post" class="wpog-preview-select">
						<option value=""><?php esc_html_e( 'Use a post to preview…', 'wp-remote-og-plugins' ); ?></option>
						<?php foreach ( $posts as $post ) : ?>
							<option value="<?php echo esc_attr( $post['id'] ); ?>"><?php echo esc_html( $post['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="wpog-icon-btn" id="wp-remote-og-refresh-preview" title="<?php esc_attr_e( 'Refresh preview', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'Refresh preview', 'wp-remote-og-plugins' ); ?>"><?php echo self::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<div class="wpog-overflow" data-overflow>
						<button type="button" class="wpog-icon-btn" data-overflow-toggle aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'More actions', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'More actions', 'wp-remote-og-plugins' ); ?>"><?php echo self::icon( 'more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<div class="wpog-overflow-menu" hidden>
							<button type="button" class="wpog-overflow-item" id="wp-remote-og-background"><?php esc_html_e( 'Select background image', 'wp-remote-og-plugins' ); ?></button>
							<a class="wpog-overflow-item" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export template (JSON)', 'wp-remote-og-plugins' ); ?></a>
							<form class="wp-remote-og-import-template wpog-overflow-import" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
								<input type="hidden" name="action" value="wp_remote_og_import_template">
								<?php wp_nonce_field( 'wp_remote_og_import_template', 'wp_remote_og_import_nonce' ); ?>
								<label class="wpog-overflow-item-label" for="wp-remote-og-import-file"><?php esc_html_e( 'Import template', 'wp-remote-og-plugins' ); ?></label>
								<input type="file" id="wp-remote-og-import-file" name="wp_remote_og_template_file" accept="application/json,.json" required>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Import', 'wp-remote-og-plugins' ); ?></button>
							</form>
						</div>
					</div>
					<button type="button" class="button button-primary wpog-save-btn" id="wp-remote-og-save-template"><?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Save', 'wp-remote-og-plugins' ); ?></span></button>
				</div>
			</div>

			<div class="wp-remote-og-editor" data-template="<?php echo esc_attr( wp_json_encode( $template ) ); ?>">
				<div class="wpog-workspace">
					<aside class="wpog-panel wpog-panel-left">
						<div class="wpog-panel-head">
							<span class="wpog-panel-title"><?php esc_html_e( 'Structure', 'wp-remote-og-plugins' ); ?></span>
							<div class="wpog-panel-tools">
								<div class="wpog-overflow wpog-add-wrap" data-overflow>
									<button type="button" class="wpog-icon-btn" data-overflow-toggle aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'Add layer', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'Add layer', 'wp-remote-og-plugins' ); ?>"><?php echo self::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
									<div class="wpog-overflow-menu" hidden>
										<button type="button" class="wpog-overflow-item" id="wp-remote-og-add-layer"><?php echo self::icon( 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Text layer', 'wp-remote-og-plugins' ); ?></button>
										<button type="button" class="wpog-overflow-item" id="wp-remote-og-add-image-layer"><?php echo self::icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Image layer', 'wp-remote-og-plugins' ); ?></button>
										<button type="button" class="wpog-overflow-item" id="wp-remote-og-add-horizontal-line"><?php echo self::icon( 'line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Horizontal line', 'wp-remote-og-plugins' ); ?></button>
										<button type="button" class="wpog-overflow-item" id="wp-remote-og-add-vertical-line"><?php echo self::icon( 'line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Vertical line', 'wp-remote-og-plugins' ); ?></button>
									</div>
								</div>
								<button type="button" class="wpog-icon-btn" id="wp-remote-og-undo" title="<?php esc_attr_e( 'Undo', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'Undo', 'wp-remote-og-plugins' ); ?>" disabled><?php echo self::icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
								<button type="button" class="wpog-icon-btn" id="wp-remote-og-redo" title="<?php esc_attr_e( 'Redo', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'Redo', 'wp-remote-og-plugins' ); ?>" disabled><?php echo self::icon( 'redo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
							</div>
						</div>
						<ul id="wp-remote-og-layer-list" class="wp-remote-og-layer-list" role="listbox" aria-label="<?php esc_attr_e( 'Layers', 'wp-remote-og-plugins' ); ?>"></ul>
					</aside>

					<section class="wpog-canvas-zone">
						<div class="wp-remote-og-canvas-frame">
							<div id="wp-remote-og-canvas" class="wp-remote-og-canvas" aria-label="<?php esc_attr_e( 'OG image canvas', 'wp-remote-og-plugins' ); ?>">
								<div class="wp-remote-og-safe-area"></div>
								<div id="wp-remote-og-layer-stage"></div>
							</div>
						</div>
						<div class="wp-remote-og-preview-warnings" id="wp-remote-og-preview-warnings"></div>
					</section>

					<aside class="wpog-panel wpog-panel-right">
						<div class="wp-remote-og-controls wpog-inspector">
							<div class="wpog-inspector-empty" id="wp-remote-og-inspector-empty"><?php esc_html_e( 'Select a layer to edit its properties.', 'wp-remote-og-plugins' ); ?></div>
							<div class="wpog-inspector-body" id="wp-remote-og-inspector-body">
								<div class="wpog-inspector-head">
									<span class="wpog-inspector-name" id="wp-remote-og-inspector-name"></span>
									<div class="wpog-overflow wpog-inspector-overflow" data-overflow>
										<button type="button" class="wpog-icon-btn" data-overflow-toggle aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e( 'Layer actions', 'wp-remote-og-plugins' ); ?>" aria-label="<?php esc_attr_e( 'Layer actions', 'wp-remote-og-plugins' ); ?>"><?php echo self::icon( 'more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
										<div class="wpog-overflow-menu" hidden>
											<button type="button" class="wpog-overflow-item" id="wp-remote-og-duplicate-layer"><?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Duplicate layer', 'wp-remote-og-plugins' ); ?></button>
											<button type="button" class="wpog-overflow-item is-danger" id="wp-remote-og-delete-layer"><?php echo self::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Delete layer', 'wp-remote-og-plugins' ); ?></button>
										</div>
									</div>
								</div>

								<details class="wpog-section" open>
									<summary class="wpog-section-summary"><?php esc_html_e( 'Content & Tokens', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<label><?php esc_html_e( 'Layer type', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-type">
												<option value="text"><?php esc_html_e( 'Text Layer', 'wp-remote-og-plugins' ); ?></option>
												<option value="image"><?php esc_html_e( 'Image Layer', 'wp-remote-og-plugins' ); ?></option>
												<option value="line"><?php esc_html_e( 'Line Layer', 'wp-remote-og-plugins' ); ?></option>
											</select>
										</label>
										<div class="wp-remote-og-content-controls">
											<label><?php esc_html_e( 'Content / token', 'wp-remote-og-plugins' ); ?><input type="text" id="wp-remote-og-layer-content"></label>
											<label><?php esc_html_e( 'Insert dynamic field', 'wp-remote-og-plugins' ); ?>
												<select id="wp-remote-og-token-picker">
													<option value=""><?php esc_html_e( 'Choose token', 'wp-remote-og-plugins' ); ?></option>
													<?php foreach ( $fields as $field ) : ?>
														<?php if ( empty( $field['enabled'] ) ) { continue; } ?>
													<option value="<?php echo esc_attr( $field['token'] ); ?>"><?php echo esc_html( $field['label'] . ' — ' . $field['token'] ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
										</div>
										<div class="wp-remote-og-visibility-controls">
											<label><?php esc_html_e( 'Show layer only when', 'wp-remote-og-plugins' ); ?>
												<select id="wp-remote-og-layer-requires-token">
													<option value=""><?php esc_html_e( 'Always show', 'wp-remote-og-plugins' ); ?></option>
													<?php foreach ( $fields as $field ) : ?>
														<?php if ( empty( $field['enabled'] ) ) { continue; } ?>
													<option value="<?php echo esc_attr( $field['token'] ); ?>"><?php echo esc_html( $field['label'] . ' — ' . $field['token'] ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
										</div>
									</div>
								</details>

								<details class="wpog-section wp-remote-og-text-controls" open>
									<summary class="wpog-section-summary"><?php esc_html_e( 'Typography', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<label><?php esc_html_e( 'Font', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-font">
												<option value=""><?php esc_html_e( 'System font', 'wp-remote-og-plugins' ); ?></option>
												<?php foreach ( $fonts as $font ) : ?>
													<option value="<?php echo esc_attr( $font['id'] ); ?>"><?php echo esc_html( $font['label'] ); ?><?php echo empty( $font['renderable'] ) ? esc_html__( ' (preview only)', 'wp-remote-og-plugins' ): ''; ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<div class="wp-remote-og-control-row">
											<label><?php esc_html_e( 'Font size', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-font-size" min="8" max="180"></label>
											<label><?php esc_html_e( 'Min size', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-min-font-size" min="6" max="180"></label>
										</div>
										<label><?php esc_html_e( 'Align', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-align">
												<option value="left"><?php esc_html_e( 'Left', 'wp-remote-og-plugins' ); ?></option>
												<option value="center"><?php esc_html_e( 'Center', 'wp-remote-og-plugins' ); ?></option>
												<option value="right"><?php esc_html_e( 'Right', 'wp-remote-og-plugins' ); ?></option>
											</select>
										</label>
										<div class="wp-remote-og-control-row">
											<label><?php esc_html_e( 'Line height', 'wp-remote-og-plugins' ); ?><input type="number" step="0.05" id="wp-remote-og-layer-line-height" min="0.8" max="2.5"></label>
											<label><?php esc_html_e( 'Max lines', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-max-lines" min="1" max="12"></label>
										</div>
									</div>
								</details>

								<details class="wpog-section wp-remote-og-image-controls">
									<summary class="wpog-section-summary"><?php esc_html_e( 'Image', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<button type="button" class="button button-secondary" id="wp-remote-og-layer-select-image"><?php esc_html_e( 'Select image', 'wp-remote-og-plugins' ); ?></button>
										<label><?php esc_html_e( 'Shape', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-image-shape">
												<option value="square"><?php esc_html_e( 'Square', 'wp-remote-og-plugins' ); ?></option>
												<option value="rounded"><?php esc_html_e( 'Rounded', 'wp-remote-og-plugins' ); ?></option>
												<option value="circle"><?php esc_html_e( 'Circle', 'wp-remote-og-plugins' ); ?></option>
											</select>
										</label>
										<label><?php esc_html_e( 'Fit', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-image-fit">
												<option value="contain"><?php esc_html_e( 'Contain', 'wp-remote-og-plugins' ); ?></option>
												<option value="cover"><?php esc_html_e( 'Cover', 'wp-remote-og-plugins' ); ?></option>
												<option value="stretch"><?php esc_html_e( 'Stretch', 'wp-remote-og-plugins' ); ?></option>
											</select>
										</label>
									</div>
								</details>

								<details class="wpog-section wp-remote-og-line-controls">
									<summary class="wpog-section-summary"><?php esc_html_e( 'Line', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<label><?php esc_html_e( 'Direction', 'wp-remote-og-plugins' ); ?>
											<select id="wp-remote-og-layer-line-orientation">
												<option value="horizontal"><?php esc_html_e( 'Horizontal', 'wp-remote-og-plugins' ); ?></option>
												<option value="vertical"><?php esc_html_e( 'Vertical', 'wp-remote-og-plugins' ); ?></option>
											</select>
										</label>
									</div>
								</details>

								<details class="wpog-section" open>
									<summary class="wpog-section-summary"><?php esc_html_e( 'Position & Size', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<div class="wp-remote-og-control-row">
											<label><?php esc_html_e( 'X', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-x" min="0" max="1200"></label>
											<label><?php esc_html_e( 'Y', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-y" min="0" max="630"></label>
										</div>
										<div class="wp-remote-og-control-row">
											<label><?php esc_html_e( 'Width', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-width" min="20" max="1200"></label>
											<label><?php esc_html_e( 'Height', 'wp-remote-og-plugins' ); ?><input type="number" id="wp-remote-og-layer-height" min="20" max="630"></label>
										</div>
									</div>
								</details>

								<details class="wpog-section wp-remote-og-color-controls" open>
									<summary class="wpog-section-summary"><?php esc_html_e( 'Appearance', 'wp-remote-og-plugins' ); ?><?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
									<div class="wpog-section-body">
										<label><?php esc_html_e( 'Color', 'wp-remote-og-plugins' ); ?><input type="text" id="wp-remote-og-layer-color" class="wp-remote-og-color" value="#111827"></label>
									</div>
								</details>
							</div>
						</div>
					</aside>
				</div>
				<div id="wp-remote-og-status" class="wp-remote-og-status" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}

	public static function render_fields_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$saved_notice = '';
		if ( isset( $_POST['wp_remote_og_fields_nonce'] ) && check_admin_referer( 'wp_remote_og_dynamic_fields', 'wp_remote_og_fields_nonce' ) ) {
			$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? map_deep( wp_unslash( $_POST['fields'] ), 'sanitize_text_field' ) : array();
			WP_Remote_OG_Plugin::save_dynamic_fields( $fields );
			$saved_notice = __( 'Dynamic fields saved.', 'wp-remote-og-plugins' );
		}

		$fields = WP_Remote_OG_Plugin::get_dynamic_fields();
		self::page_open( 'wp-remote-og-fields', __( 'Dynamic Fields', 'wp-remote-og-plugins' ), __( 'Map reusable tokens to post data so every generated image stays on-brand.', 'wp-remote-og-plugins' ) );
		if ( '' !== $saved_notice ) {
			echo '<div class="wpog-notice is-success"><p>' . esc_html( $saved_notice ) . '</p></div>';
		}
		?>
			<div class="wpog-card">
			<form method="post" id="wp-remote-og-fields-form">
				<?php wp_nonce_field( 'wp_remote_og_dynamic_fields', 'wp_remote_og_fields_nonce' ); ?>
				<table class="widefat striped wp-remote-og-fields-table">
					<thead><tr><th><?php esc_html_e( 'Enabled', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Token', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Label', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Fallback', 'wp-remote-og-plugins' ); ?></th><th></th></tr></thead>
					<tbody id="wp-remote-og-field-rows">
						<?php foreach ( $fields as $index => $field ) : ?>
							<tr>
								<td><input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $field['enabled'] ) ); ?>></td>
								<td><input type="text" name="fields[<?php echo esc_attr( $index ); ?>][token]" value="<?php echo esc_attr( $field['token'] ); ?>" list="wp-remote-og-field-token-list" class="regular-text"></td>
								<td><input type="text" name="fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="regular-text"></td>
								<td><input type="text" name="fields[<?php echo esc_attr( $index ); ?>][fallback]" value="<?php echo esc_attr( $field['fallback'] ); ?>" class="regular-text"></td>
								<td><button type="button" class="button wp-remote-og-remove-row"><?php esc_html_e( 'Remove', 'wp-remote-og-plugins' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<datalist id="wp-remote-og-field-token-list"></datalist>
				<p>
					<button type="button" class="button" id="wp-remote-og-add-field-row"><?php esc_html_e( 'Add Field', 'wp-remote-og-plugins' ); ?></button>
					<button type="button" class="button button-secondary" id="wp-remote-og-fill-available-fields"><?php esc_html_e( 'Load available fields', 'wp-remote-og-plugins' ); ?></button>
				</p>
				<?php submit_button( __( 'Save Dynamic Fields', 'wp-remote-og-plugins' ) ); ?>
			</form>
			</div>
		<?php
		self::page_close();
	}

	public static function render_fonts_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$notices = array();
		if ( isset( $_POST['wp_remote_og_font_nonce'] ) && check_admin_referer( 'wp_remote_og_upload_font', 'wp_remote_og_font_nonce' ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				$notices[] = array( 'error', __( 'You do not have permission to upload files.', 'wp-remote-og-plugins' ) );
			} else {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- upload is verified with is_uploaded_file() and wp_check_filetype_and_ext() in upload_from_request().
				$result = WP_Remote_OG_Fonts::upload_from_request( isset( $_FILES['wp_remote_og_font'] ) ? $_FILES['wp_remote_og_font'] : array() );
				if ( is_wp_error( $result ) ) {
					$notices[] = array( 'error', $result->get_error_message() );
				} else {
					$notices[] = array( 'success', __( 'Font uploaded.', 'wp-remote-og-plugins' ) );
				}
			}
		}

		if ( isset( $_POST['wp_remote_og_google_font_nonce'] ) && check_admin_referer( 'wp_remote_og_add_google_font', 'wp_remote_og_google_font_nonce' ) ) {
			$result = WP_Remote_OG_Fonts::register_google_font(
				isset( $_POST['wp_remote_og_google_font_family'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_remote_og_google_font_family'] ) ) : ''
			);
			if ( is_wp_error( $result ) ) {
				$notices[] = array( 'error', $result->get_error_message() );
			} else {
				$notices[] = array( 'success', __( 'Google Font added.', 'wp-remote-og-plugins' ) );
			}
		}

		$fonts = WP_Remote_OG_Plugin::get_fonts();
		self::page_open( 'wp-remote-og-fonts', __( 'Fonts', 'wp-remote-og-plugins' ), __( 'Upload custom fonts or add Google Fonts to use in your template text layers.', 'wp-remote-og-plugins' ) );
		foreach ( $notices as $notice ) {
			echo '<div class="wpog-notice is-' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		?>
			<div class="wpog-card">
			<h2 class="wpog-card-title"><?php esc_html_e( 'Add a font', 'wp-remote-og-plugins' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="wp-remote-og-font-upload">
				<?php wp_nonce_field( 'wp_remote_og_upload_font', 'wp_remote_og_font_nonce' ); ?>
				<input type="file" name="wp_remote_og_font" accept=".ttf,.otf,.woff,.woff2" required>
				<?php submit_button( __( 'Upload Font', 'wp-remote-og-plugins' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="post" class="wp-remote-og-font-upload wp-remote-og-google-font-form">
				<?php wp_nonce_field( 'wp_remote_og_add_google_font', 'wp_remote_og_google_font_nonce' ); ?>
				<div class="wp-remote-og-google-font-field">
					<label for="wp-remote-og-google-font-family"><?php esc_html_e( 'Google font family', 'wp-remote-og-plugins' ); ?></label>
					<div class="wp-remote-og-font-picker">
						<input
							id="wp-remote-og-google-font-family"
							type="text"
							name="wp_remote_og_google_font_family"
							required
							autocomplete="off"
							aria-autocomplete="list"
							aria-expanded="false"
							aria-controls="wp-remote-og-google-font-suggestions"
							placeholder="<?php echo esc_attr__( 'Roboto, Inter:wght@400;700', 'wp-remote-og-plugins' ); ?>"
						>
						<button
							type="button"
							class="wp-remote-og-font-picker-toggle"
							aria-label="<?php echo esc_attr__( 'Show Google font suggestions', 'wp-remote-og-plugins' ); ?>"
							aria-expanded="false"
							aria-controls="wp-remote-og-google-font-suggestions"
						></button>
						<div id="wp-remote-og-google-font-suggestions" class="wp-remote-og-font-suggestions" role="listbox" hidden></div>
					</div>
					<datalist id="wp-remote-og-google-font-list">
						<option value="Roboto:wght@400;700">
						<option value="Open Sans:wght@300;400;700">
					</datalist>
				</div>
				<p class="description">
					<span id="wp-remote-og-google-font-status"><?php esc_html_e( 'Loading Google font directory…', 'wp-remote-og-plugins' ); ?></span>
					<?php esc_html_e( 'Type to search, and still add variants manually (for example Inter:wght@400;700).', 'wp-remote-og-plugins' ); ?>
				</p>
				<?php submit_button( __( 'Add Google Font', 'wp-remote-og-plugins' ), 'secondary', 'submit_google_font', false ); ?>
			</form>
			</div>
			<div class="wpog-card">
			<h2 class="wpog-card-title"><?php esc_html_e( 'Available fonts', 'wp-remote-og-plugins' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Font', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'File', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Server Rendering', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Uploaded', 'wp-remote-og-plugins' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $fonts ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No custom fonts added yet.', 'wp-remote-og-plugins' ); ?></td></tr>
					<?php endif; ?>
				<?php foreach ( $fonts as $font ) : ?>
					<tr>
						<?php
						$font_file    = ! empty( $font['source'] ) && 'google' === $font['source'] ? esc_html__( 'Google Fonts', 'wp-remote-og-plugins' ) : ( isset( $font['filename'] ) ? $font['filename'] : '' );
						$font_support = ! empty( $font['renderable'] ) ? esc_html__( 'Supported', 'wp-remote-og-plugins' ) : esc_html__( 'Preview only; renderer may not support this format', 'wp-remote-og-plugins' );
						?>
						<td><?php echo esc_html( $font['label'] ); ?></td>
						<td><?php echo esc_html( $font_file ); ?></td>
						<td><?php echo esc_html( $font_support ); ?></td>
						<td><?php echo esc_html( isset( $font['uploaded_at'] ) ? $font['uploaded_at'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php
		self::page_close();
	}

	public static function render_tools_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$log = get_option( WP_Remote_OG_Plugin::OPTION_GENERATION_LOG, array() );
		$log = is_array( $log ) ? array_reverse( $log ) : array();
		self::page_open( 'wp-remote-og-tools', __( 'Generation Tools', 'wp-remote-og-plugins' ), __( 'Bulk generate, regenerate, and clean up the social preview images across your posts.', 'wp-remote-og-plugins' ) );
		?>
			<?php if ( get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ) ) : ?>
				<div class="wpog-notice is-info"><p><strong><?php esc_html_e( 'Template changed. Regenerate all existing OG images now?', 'wp-remote-og-plugins' ); ?></strong></p></div>
			<?php endif; ?>
			<div class="wpog-card">
			<h2 class="wpog-card-title"><?php esc_html_e( 'Actions', 'wp-remote-og-plugins' ); ?></h2>
			<div class="wp-remote-og-tool-actions">
				<button type="button" class="button button-primary wp-remote-og-bulk" data-mode="all"><?php esc_html_e( 'Regenerate OG Images for All Posts', 'wp-remote-og-plugins' ); ?></button>
				<button type="button" class="button wp-remote-og-bulk" data-mode="missing"><?php esc_html_e( 'Regenerate Missing OG Images Only', 'wp-remote-og-plugins' ); ?></button>
				<button type="button" class="button" id="wp-remote-og-cleanup-orphans"><?php esc_html_e( 'Delete Orphaned OG Images', 'wp-remote-og-plugins' ); ?></button>
			</div>
			<div class="wp-remote-og-progress" id="wp-remote-og-progress" aria-live="polite"></div>
			</div>
			<div class="wpog-card">
			<h2 class="wpog-card-title"><?php esc_html_e( 'Generation Log', 'wp-remote-og-plugins' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Post', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Status', 'wp-remote-og-plugins' ); ?></th><th><?php esc_html_e( 'Message', 'wp-remote-og-plugins' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $log ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No generation events yet.', 'wp-remote-og-plugins' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo esc_html( $entry['post_id'] ); ?></td>
							<td><?php echo esc_html( $entry['status'] ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php
		self::page_close();
	}

	public static function render_diagnostics_page() {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-remote-og-plugins' ) );
		}

		$diagnostics = WP_Remote_OG_Diagnostics::get();
		self::page_open( 'wp-remote-og-diagnostics', __( 'Diagnostics', 'wp-remote-og-plugins' ), __( 'Check the rendering environment and image generation status at a glance.', 'wp-remote-og-plugins' ) );
		?>
			<div class="wpog-card">
			<table class="widefat striped wp-remote-og-diagnostics">
				<tbody>
					<tr><th><?php esc_html_e( 'Imagick availability', 'wp-remote-og-plugins' ); ?></th><td><?php echo self::badge( $diagnostics['imagick'], __( 'Available', 'wp-remote-og-plugins' ), __( 'Unavailable', 'wp-remote-og-plugins' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th><?php esc_html_e( 'GD availability', 'wp-remote-og-plugins' ); ?></th><td><?php echo self::badge( $diagnostics['gd'], __( 'Available', 'wp-remote-og-plugins' ), __( 'Unavailable', 'wp-remote-og-plugins' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th><?php esc_html_e( 'Rank Math availability', 'wp-remote-og-plugins' ); ?></th><td><?php echo self::badge( $diagnostics['rank_math'], __( 'Active', 'wp-remote-og-plugins' ), __( 'Inactive', 'wp-remote-og-plugins' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th><?php esc_html_e( 'ACF availability', 'wp-remote-og-plugins' ); ?></th><td><?php echo self::badge( $diagnostics['acf'], __( 'Active', 'wp-remote-og-plugins' ), __( 'Inactive', 'wp-remote-og-plugins' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th><?php esc_html_e( 'Uploads directory', 'wp-remote-og-plugins' ); ?></th><td><code><?php echo esc_html( $diagnostics['upload_dir'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Uploads writable', 'wp-remote-og-plugins' ); ?></th><td><?php echo self::badge( $diagnostics['upload_writable'], __( 'Writable', 'wp-remote-og-plugins' ), __( 'Not writable', 'wp-remote-og-plugins' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th><?php esc_html_e( 'Generated images', 'wp-remote-og-plugins' ); ?></th><td><?php echo esc_html( $diagnostics['generated_count'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing images', 'wp-remote-og-plugins' ); ?></th><td><?php echo esc_html( $diagnostics['missing_count'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Orphaned images', 'wp-remote-og-plugins' ); ?></th><td><?php echo esc_html( $diagnostics['orphaned_count'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Last bulk result', 'wp-remote-og-plugins' ); ?></th><td><?php echo esc_html( $diagnostics['last_bulk_result'] ? $diagnostics['last_bulk_result'] : __( 'No bulk run yet.', 'wp-remote-og-plugins' ) ); ?></td></tr>
				</tbody>
			</table>
			</div>
		<?php
		self::page_close();
	}

	public static function add_meta_box() {
		add_meta_box(
			'wp_remote_og_meta',
			__( 'Remote OG Image', 'wp-remote-og-plugins' ),
			array( __CLASS__, 'render_meta_box' ),
			'post',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage OG images.', 'wp-remote-og-plugins' ) . '</p>';
			return;
		}

		$meta = WP_Remote_OG_Storage::metadata( $post->ID );
		$preview = WP_Remote_OG_Dynamic_Fields::preview_data( $post->ID );
		$warnings = ! is_wp_error( $preview ) && ! empty( $preview['warnings'] ) ? $preview['warnings'] : array();
		?>
		<div class="wp-remote-og-post-box">
			<?php if ( ! empty( $meta['url'] ) ) : ?>
				<img src="<?php echo esc_url( $meta['url'] ); ?>" alt="" class="wp-remote-og-post-preview">
				<p><a href="<?php echo esc_url( $meta['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View generated image', 'wp-remote-og-plugins' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No generated OG image yet.', 'wp-remote-og-plugins' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $warnings ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( implode( ' ', $warnings ) ); ?></p>
				</div>
			<?php endif; ?>
			<p><strong><?php esc_html_e( 'Generated at:', 'wp-remote-og-plugins' ); ?></strong> <?php echo esc_html( $meta['generated_at'] ? $meta['generated_at'] : __( 'Never', 'wp-remote-og-plugins' ) ); ?></p>
			<p><strong><?php esc_html_e( 'Template version:', 'wp-remote-og-plugins' ); ?></strong> <?php echo esc_html( $meta['template_version'] ); ?></p>
			<button type="button" class="button button-primary wp-remote-og-generate-post" data-post-id="<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Generate / Regenerate OG Image', 'wp-remote-og-plugins' ); ?></button>
			<div class="wp-remote-og-post-status" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function verify_ajax() {
		check_ajax_referer( 'wp_remote_og_admin', 'nonce' );
		if ( ! WP_Remote_OG_Plugin::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage WP Remote OG.', 'wp-remote-og-plugins' ) ), 403 );
		}
	}

	public static function ajax_save_template() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax(); the template array is fully sanitized in sanitize_template() via save_template().
		$template = isset( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array();
		$template = WP_Remote_OG_Plugin::save_template( $template );
		wp_send_json_success(
			array(
				'template' => $template,
				'version'  => get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_VERSION ),
				'dirty'    => (bool) get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ),
			)
		);
	}

	public static function ajax_preview() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax().
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax(); the template array is fully sanitized in sanitize_template() before use.
		$template = isset( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : null;
		$data     = WP_Remote_OG_Dynamic_Fields::preview_data( $post_id, $template );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ), 400 );
		}

		wp_send_json_success( $data );
	}

	public static function ajax_generate_post() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax().
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$result  = WP_Remote_OG_Generator::generate_for_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	public static function ajax_bulk_ids() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax().
		$mode = isset( $_POST['mode'] ) && 'missing' === $_POST['mode'] ? 'missing' : 'all';
		wp_send_json_success( array( 'ids' => WP_Remote_OG_Generator::bulk_post_ids( $mode ) ) );
	}

	public static function ajax_bulk_process() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax(); every id is cast with absint().
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$results = array();
		$errors  = array();

		foreach ( array_slice( $ids, 0, 10 ) as $post_id ) {
			$result = WP_Remote_OG_Generator::generate_for_post( $post_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$results[] = $result;
			}
		}

		$settings = get_option( WP_Remote_OG_Plugin::OPTION_SETTINGS, array() );
		$settings['last_bulk_result'] = sprintf( 'Processed %d posts with %d errors at %s', count( $results ), count( $errors ), current_time( 'mysql' ) );
		update_option( WP_Remote_OG_Plugin::OPTION_SETTINGS, $settings, false );

		wp_send_json_success(
			array(
				'results' => $results,
				'errors'  => $errors,
			)
		);
	}

	public static function ajax_google_fonts() {
		self::verify_ajax();
		$fonts = WP_Remote_OG_Fonts::get_google_font_catalog();
		if ( ! is_array( $fonts ) ) {
			$fonts = array();
		}

		wp_send_json_success(
			array(
				'fonts' => array_values( $fonts ),
				'count' => count( $fonts ),
			)
		);
	}

	public static function ajax_apply_preset() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax().
		$key    = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$preset = WP_Remote_OG_Presets::get( $key );
		if ( ! $preset ) {
			wp_send_json_error( array( 'message' => __( 'Unknown template preset.', 'wp-remote-og-plugins' ) ), 400 );
		}

		update_option(
			WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP,
			array(
				'template'   => WP_Remote_OG_Plugin::get_template(),
				'created_at' => current_time( 'mysql' ),
			),
			false
		);

		$template = WP_Remote_OG_Plugin::save_template( $preset['template'] );

		wp_send_json_success(
			array(
				'template' => $template,
				'preset'   => $preset['key'],
				'dirty'    => (bool) get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ),
				'backup'   => true,
			)
		);
	}

	public static function ajax_restore_template_backup() {
		self::verify_ajax();
		$backup = get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );
		if ( ! is_array( $backup ) || empty( $backup['template'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No previous template is available to restore.', 'wp-remote-og-plugins' ) ), 400 );
		}

		$template = WP_Remote_OG_Plugin::save_template( $backup['template'] );
		delete_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_BACKUP );

		wp_send_json_success(
			array(
				'template' => $template,
				'dirty'    => (bool) get_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY ),
			)
		);
	}

	public static function ajax_clear_template_notice() {
		self::verify_ajax();
		delete_option( WP_Remote_OG_Plugin::OPTION_TEMPLATE_DIRTY );
		wp_send_json_success();
	}

	public static function ajax_cleanup_orphans() {
		self::verify_ajax();
		$result = WP_Remote_OG_Uploads::cleanup_orphans();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}
}

WP_Remote_OG_Plugin::init();
