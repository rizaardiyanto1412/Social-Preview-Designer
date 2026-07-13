<?php
/**
 * Build a distributable plugin zip.
 *
 * @package WPRemoteOG
 */

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP ZipArchive extension is required.\n" );
	exit( 1 );
}

$root        = __DIR__;
$plugin_slug = basename( $root );
$dist_dir    = $root . DIRECTORY_SEPARATOR . 'dist';
$zip_path    = $dist_dir . DIRECTORY_SEPARATOR . $plugin_slug . '.zip';

$include_paths = array(
	'assets',
	'wp-remote-og-plugins.php',
);

if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0755, true ) ) {
	fwrite( STDERR, "Unable to create dist directory: {$dist_dir}\n" );
	exit( 1 );
}

if ( file_exists( $zip_path ) && ! unlink( $zip_path ) ) {
	fwrite( STDERR, "Unable to remove existing zip: {$zip_path}\n" );
	exit( 1 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Unable to open zip for writing: {$zip_path}\n" );
	exit( 1 );
}

foreach ( $include_paths as $relative_path ) {
	$path = $root . DIRECTORY_SEPARATOR . $relative_path;
	if ( is_dir( $path ) ) {
		add_directory_to_zip( $zip, $root, $plugin_slug, $path );
		continue;
	}

	if ( is_file( $path ) ) {
		$zip->addFile( $path, $plugin_slug . '/' . normalize_path( $relative_path ) );
	}
}

$zip->close();

echo "Built {$zip_path}\n";

/**
 * Add a directory tree to a zip archive.
 *
 * @param ZipArchive $zip         Zip archive.
 * @param string     $root        Project root path.
 * @param string     $plugin_slug Top-level plugin folder in the archive.
 * @param string     $directory   Directory to add.
 * @return void
 */
function add_directory_to_zip( ZipArchive $zip, $root, $plugin_slug, $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			continue;
		}

		$absolute_path = $file->getPathname();
		$relative_path = substr( $absolute_path, strlen( $root ) + 1 );
		$zip->addFile( $absolute_path, $plugin_slug . '/' . normalize_path( $relative_path ) );
	}
}

/**
 * Normalize filesystem paths for zip archives.
 *
 * @param string $path File path.
 * @return string
 */
function normalize_path( $path ) {
	return str_replace( DIRECTORY_SEPARATOR, '/', $path );
}
