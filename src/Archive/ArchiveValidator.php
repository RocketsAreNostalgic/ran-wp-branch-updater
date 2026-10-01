<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- ZIP stream validation needs direct bounded reads.
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier;
use RuntimeException;
use ZipArchive;

/** Inspects the exact local ZIP before WordPress receives it. */
final class ArchiveValidator {
	public const CODE_ZIP_UNAVAILABLE           = 1000;
	public const CODE_ZIP_INVALID               = 1001;
	public const CODE_ENTRY_LIMIT               = 1002;
	public const CODE_ENTRY_INVALID             = 1003;
	public const CODE_PATH_UNSAFE               = 1004;
	public const CODE_PATH_COLLISION            = 1005;
	public const CODE_LAYOUT_INVALID            = 1006;
	public const CODE_ENTRY_UNSUPPORTED         = 1007;
	public const CODE_ENTRY_ENCRYPTED           = 1008;
	public const CODE_COMPRESSED_TOO_LARGE      = 1009;
	public const CODE_ARCHIVE_INTEGRITY         = 1010;
	public const CODE_SUBDIRECTORY_MISSING      = 1011;
	public const CODE_PACKAGE_IDENTITY          = 1012;
	public const CODE_HEADER_UNREADABLE         = 1013;
	public const CODE_VERSION_INVALID           = 1014;
	public const CODE_COMPATIBILITY_INVALID     = 1015;
	public const CODE_REQUIRES_NEWER_PHP        = 1016;
	public const CODE_REQUIRES_NEWER_WP         = 1017;
	public const CODE_EXPANDED_TOO_LARGE        = 1018;
	public const CODE_PLUGIN_MISSING            = 1019;
	public const CODE_THEME_MISSING             = 1020;
	public const CODE_MULTIPLE_PLUGINS          = 1021;
	public const CODE_HEADER_MISSING            = 1022;
	public const CODE_VERSION_MISSING           = 1023;
	public const CODE_MULTIPLE_ROOTS            = 1024;
	public const CODE_PACKAGE_DIRECTORY_MISSING = 1025;
	public const CODE_THEME_IDENTITY_MISMATCH   = 1026;
	public const CODE_FILE_PARENT_COLLISION     = 1027;
	private const MAX_ENTRIES                   = 10000;

	/** @return array{expanded:int,expected_version:string} */
	public function validate( string $path, BranchDeploymentDeclaration $d, ?string $installed, int $compressed_limit, int $expanded_limit, string $wordpress_version ): array {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->fail( self::CODE_ZIP_UNAVAILABLE, 'The ZIP extension is unavailable.' );
		}
		if ( ! is_file( $path ) || filesize( $path ) < 1 || filesize( $path ) > $compressed_limit ) {
			$this->fail( self::CODE_COMPRESSED_TOO_LARGE, 'The archive size is invalid.' );
		}
		$this->assert_installed_identity_admission( $d, $installed );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			$this->fail( self::CODE_ZIP_INVALID, 'The archive is not a readable ZIP file.' );
		}
		try {
			$this->assert_entry_count( $zip->numFiles );
			$entries  = array();
			$root     = null;
			$expanded = 0;
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat || ! is_string( $stat['name'] ?? null ) || ! is_int( $stat['size'] ?? null ) || $stat['size'] < 0 || ! is_int( $stat['crc'] ?? null ) ) {
					$this->fail( self::CODE_ENTRY_INVALID, 'The archive contains invalid entry metadata.' );
				}
				$name       = $stat['name'];
				$normalized = $this->validate_entry_name( $name );
				if ( isset( $entries[ $normalized ] ) ) {
					$this->fail( self::CODE_PATH_COLLISION, 'The archive contains duplicate paths.' );
				}
				$entries[ $normalized ] = array(
					'index'     => $index,
					'directory' => str_ends_with( $name, '/' ),
				);
				$entry_root             = explode( '/', $normalized, 2 )[0];
				$root                 ??= $entry_root;
				if ( ! hash_equals( $root, $entry_root ) ) {
					$this->fail( self::CODE_MULTIPLE_ROOTS, 'The archive must contain one package root.' );
				}
				$this->assert_safe_entry_type( $zip, $index, $stat, str_ends_with( $name, '/' ) );
				if ( ! str_ends_with( $name, '/' ) ) {
					$this->verify_entry_contents( $zip, $index, $stat['size'], $stat['crc'], $expanded_limit );
				}
				$expanded = $this->add_expanded_bytes( $expanded, $stat['size'], $expanded_limit );
			}
			if ( null === $root ) {
				$this->fail( self::CODE_LAYOUT_INVALID, 'The archive does not contain a package.' );
			}
			$this->assert_no_path_collisions( $entries );
			return array(
				'expanded'         => $expanded,
				'expected_version' => $this->assert_package_identity( $zip, $d, $entries, $root, $installed, $wordpress_version ),
			);
		} finally {
			$zip->close();
		}
	}

	private function assert_installed_identity( BranchDeploymentDeclaration $d, ?string $installed ): void {
		if ( null === $installed ) {
			return;
		}
		if ( 'theme' === $d->packageType && ! hash_equals( $d->slug, $installed ) ) {
			$this->fail( self::CODE_THEME_IDENTITY_MISMATCH, 'The installed theme identity does not match the deployment.' );
		}
		if ( 'plugin' === $d->packageType && ( ! str_contains( $installed, '/' ) || ! hash_equals( $d->slug, basename( dirname( $installed ) ) ) ) ) {
			$this->fail( self::CODE_PACKAGE_IDENTITY, 'The installed plugin identity does not match the deployment.' );
		}
	}

	private function assert_installed_identity_admission( BranchDeploymentDeclaration $d, ?string $installed ): void {
		if ( null === $installed && 'update' === $d->operation ) {
			$this->fail( self::CODE_PACKAGE_IDENTITY, 'An update requires the installed package identity.' );
		}
		if ( null === $installed ) {
			return;
		}
		try {
			if ( trim( $installed ) !== $installed ) {
				throw new \InvalidArgumentException( 'The installed package identifier is invalid.' );
			}
			InstalledPackageIdentifier::normalize( $installed );
		} catch ( \InvalidArgumentException ) {
			$this->fail( self::CODE_PACKAGE_IDENTITY, 'The installed package identity is unsafe.' );
		}
		if ( 'plugin' === $d->packageType && ! str_contains( $installed, '/' ) ) {
			$this->fail( self::CODE_PACKAGE_IDENTITY, 'A plugin update requires a directory-backed installed identity.' );
		}
	}
	private function assert_entry_count( int $entries ): void {
		if ( 0 === $entries ) {
			$this->fail( self::CODE_LAYOUT_INVALID, 'The archive does not contain a package.' );
		}
		if ( $entries > self::MAX_ENTRIES ) {
			$this->fail( self::CODE_ENTRY_LIMIT, 'The archive exceeds the entry limit.' );
		}
	}
	private function validate_entry_name( string $name ): string {
		$path = ArchiveSafety::normalize_path( $name );
		if ( null === $path ) {
			$this->fail( self::CODE_PATH_UNSAFE, 'The archive contains an unsafe path.' );
		}
		return $path['path'];
	}
	/** @param array<string,array{index:int,directory:bool}> $entries */
	private function assert_no_path_collisions( array $entries ): void {
		$paths = array();
		foreach ( $entries as $path => $entry ) {
			$paths[] = array(
				'path'      => $path,
				'directory' => $entry['directory'],
			);
		}
		$failure = ArchiveSafety::collision_failure( $paths );
		if ( 'path_duplicate' === $failure ) {
			$this->fail( self::CODE_PATH_COLLISION, 'The archive contains duplicate paths.' );
		}
		if ( 'file_parent_collision' === $failure ) {
			$this->fail( self::CODE_FILE_PARENT_COLLISION, 'The archive contains a file-parent collision.' );
		}
	}
	/** @param array<string,mixed> $stat */
	private function assert_safe_entry_type( ZipArchive $zip, int $index, array $stat, bool $named_directory ): void {
		if ( ZipArchive::EM_NONE !== (int) ( $stat['encryption_method'] ?? ZipArchive::EM_NONE ) ) {
			$this->fail( self::CODE_ENTRY_ENCRYPTED, 'Encrypted archive entries are unsupported.' );
		}
		$operations = 0;
		$attributes = 0;
		$available  = $zip->getExternalAttributesIndex( $index, $operations, $attributes, ZipArchive::FL_UNCHANGED );
		$failure    = ArchiveSafety::entry_type_failure( $available ? $operations : null, $available ? $attributes : null, $named_directory );
		if ( 'entry_type_unsupported' === $failure ) {
			$this->fail( self::CODE_ENTRY_UNSUPPORTED, 'The archive contains a link or device entry.' );
		}
		if ( 'entry_metadata_invalid' === $failure ) {
			$this->fail( self::CODE_ENTRY_INVALID, 'The archive contains invalid entry metadata.' );
		}
	}
	private function verify_entry_contents( ZipArchive $zip, int $index, int $expected_size, int $expected_crc, int $expanded_limit ): void {
		$stream = $zip->getStreamIndex( $index, ZipArchive::FL_UNCHANGED );
		if ( false === $stream ) {
			$this->fail( self::CODE_ARCHIVE_INTEGRITY, 'The archive contains unreadable entry data.' );
		}
		$read = 0;
		$hash = hash_init( 'crc32b' );
		try {
			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, 65536 );
				if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
					$this->fail( self::CODE_ARCHIVE_INTEGRITY, 'The archive contains unreadable entry data.' );
				}
				if ( '' === $chunk ) {
					break;
				}
				$read = $this->add_expanded_bytes( $read, strlen( $chunk ), $expanded_limit );
				hash_update( $hash, $chunk );
			}
		} finally {
			fclose( $stream );
		}
		if ( $read !== $expected_size || ! hash_equals( sprintf( '%08x', $expected_crc ), hash_final( $hash ) ) ) {
			$this->fail( self::CODE_ARCHIVE_INTEGRITY, 'The archive contains unreadable entry data.' );
		}
	}
	private function add_expanded_bytes( int $current, int $entry, int $limit ): int {
		if ( $current < 0 || $entry < 0 || $current > $limit - $entry ) {
			$this->fail( self::CODE_EXPANDED_TOO_LARGE, 'The archive exceeds the expanded-size limit.' );
		}
		return $current + $entry;
	}
	/** @param array<string,array{index:int,directory:bool}> $entries */
	private function assert_package_identity( ZipArchive $zip, BranchDeploymentDeclaration $d, array $entries, string $root, ?string $installed, string $wordpress_version ): string {
		$this->assert_installed_identity( $d, $installed );
		$prefix = $this->validate_entry_name( $root . ( null !== $d->subdirectory && '' !== $d->subdirectory ? '/' . $d->subdirectory : '' ) );
		$files  = array_filter( $entries, static fn( array $entry, string $name ): bool => ! $entry['directory'] && str_starts_with( $name, $prefix . '/' ), ARRAY_FILTER_USE_BOTH );
		if ( array() === $files ) {
			$this->fail( null !== $d->subdirectory && '' !== $d->subdirectory ? self::CODE_SUBDIRECTORY_MISSING : self::CODE_PACKAGE_DIRECTORY_MISSING, 'The configured package directory is absent from the archive.' );
		}
		if ( 'theme' === $d->packageType ) {
			$style = $files[ $prefix . '/style.css' ] ?? null;
			if ( null === $style ) {
				$this->fail( self::CODE_THEME_MISSING, 'The archive does not contain the expected theme.' );
			}
			$headers = $this->read_headers( $zip, $style['index'], 'Theme Name' );
			$this->assert_compatibility( $headers, $wordpress_version );
			return $this->version( $headers );
		}
		$candidates = array();
		foreach ( $files as $name => $entry ) {
			$relative = substr( $name, strlen( $prefix ) + 1 );
			if ( ! str_contains( $relative, '/' ) && str_ends_with( strtolower( $relative ), '.php' ) ) {
				$headers = $this->read_headers( $zip, $entry['index'], 'Plugin Name', false );
				if ( null !== $headers ) {
					$candidates[ $relative ] = $headers;
				}
			}
		}
		if ( 0 === count( $candidates ) ) {
			$this->fail( self::CODE_PLUGIN_MISSING, 'The archive does not contain the expected plugin.' );
		}
		if ( 1 < count( $candidates ) ) {
			$this->fail( self::CODE_MULTIPLE_PLUGINS, 'The archive contains multiple top-level plugins.' );
		}
		$main = null === $installed ? null : basename( $installed );
		if ( null !== $main && ! isset( $candidates[ $main ] ) ) {
			$this->fail( self::CODE_PLUGIN_MISSING, 'The archive does not contain the installed plugin main file.' );
		}
		$headers = reset( $candidates );
		$this->assert_compatibility( $headers, $wordpress_version );
		return $this->version( $headers );
	}
	/** @return array<string,string>|null */
	private function read_headers( ZipArchive $zip, int $index, string $required, bool $required_file = true ): ?array {
		$contents = $zip->getFromIndex( $index, 8192, ZipArchive::FL_UNCHANGED );
		if ( false === $contents ) {
			$this->fail( self::CODE_HEADER_UNREADABLE, 'The package header cannot be read.' );
		}
		$headers = array();
		foreach ( array( $required, 'Version', 'Requires at least', 'Requires PHP' ) as $header ) {
			$pattern = '/^[ \t\\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+?)\\s*$/mi';
			$value   = preg_match( $pattern, $contents, $match ) === 1 ? trim( $match[1] ) : '';
			if ( strlen( $value ) > 64 || preg_match( '/[[:cntrl:]]/', $value ) === 1 ) {
				$this->fail( 'Version' === $header ? self::CODE_VERSION_INVALID : ( $required === $header ? self::CODE_HEADER_UNREADABLE : self::CODE_COMPATIBILITY_INVALID ), 'The package header is invalid.' );
			}
			$headers[ $header ] = $value;
		}
		if ( '' === $headers[ $required ] ) {
			if ( $required_file ) {
				$this->fail( self::CODE_HEADER_MISSING, 'The package header is missing.' );
			}
			return null;
		}
		return $headers;
	}
	/** @param array<string,string> $headers */
	private function version( array $headers ): string {
		$version = $headers['Version'] ?? '';
		if ( '' === $version ) {
			$this->fail( self::CODE_VERSION_MISSING, 'The package version is missing.' );
		}
		if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $version ) !== 1 ) {
			$this->fail( self::CODE_VERSION_INVALID, 'The package version is invalid.' );
		}
		return $version;
	}
	/** @param array<string,string> $headers */
	private function assert_compatibility( array $headers, string $wordpress_version ): void {
		foreach ( array( $headers['Requires PHP'], $headers['Requires at least'] ) as $version ) {
			if ( '' !== $version && preg_match( '/^[0-9]+(?:\\.[0-9]+){0,3}(?:[-+._][A-Za-z0-9.-]+)?$/D', $version ) !== 1 ) {
				$this->fail( self::CODE_COMPATIBILITY_INVALID, 'The package compatibility header is invalid.' );
			}
		}
		if ( '' !== $headers['Requires PHP'] && version_compare( PHP_VERSION, $headers['Requires PHP'], '<' ) ) {
			$this->fail( self::CODE_REQUIRES_NEWER_PHP, 'The package requires a newer PHP version.' );
		}
		if ( '' !== $headers['Requires at least'] && ( '' === $wordpress_version || version_compare( $wordpress_version, $headers['Requires at least'], '<' ) ) ) {
			$this->fail( self::CODE_REQUIRES_NEWER_WP, 'The package requires a newer WordPress version.' );
		}
	}
	/** @return never */
	private function fail( int $code, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the bounded internal validation message and numeric error code; neither is rendered output.
		throw new RuntimeException( $message, $code );
	}
}
