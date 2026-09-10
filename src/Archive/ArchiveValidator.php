<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput -- ZIP stream validation needs direct bounded reads and throws bounded internal failures.
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\WPBranchUpdater\V1\Runtime\Deployment;
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
	public function validate( string $path, Deployment $d, ?string $installed, int $compressedLimit, int $expandedLimit, string $wordpressVersion ): array {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->fail( self::CODE_ZIP_UNAVAILABLE, 'The ZIP extension is unavailable.' );
		}
		if ( ! is_file( $path ) || filesize( $path ) < 1 || filesize( $path ) > $compressedLimit ) {
			$this->fail( self::CODE_COMPRESSED_TOO_LARGE, 'The archive size is invalid.' );
		}
		$this->assertInstalledIdentityAdmission( $d, $installed );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			$this->fail( self::CODE_ZIP_INVALID, 'The archive is not a readable ZIP file.' );
		}
		try {
			$this->assertEntryCount( $zip->numFiles );
			$entries  = array();
			$root     = null;
			$expanded = 0;
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat || ! is_string( $stat['name'] ?? null ) || ! is_int( $stat['size'] ?? null ) || $stat['size'] < 0 || ! is_int( $stat['crc'] ?? null ) ) {
					$this->fail( self::CODE_ENTRY_INVALID, 'The archive contains invalid entry metadata.' );
				}
				$name       = $stat['name'];
				$normalized = $this->validateEntryName( $name );
				if ( isset( $entries[ $normalized ] ) ) {
					$this->fail( self::CODE_PATH_COLLISION, 'The archive contains duplicate paths.' );
				}
				$entries[ $normalized ] = array(
					'index'     => $index,
					'directory' => str_ends_with( $name, '/' ),
				);
				$entryRoot              = explode( '/', $normalized, 2 )[0];
				$root                 ??= $entryRoot;
				if ( ! hash_equals( $root, $entryRoot ) ) {
					$this->fail( self::CODE_MULTIPLE_ROOTS, 'The archive must contain one package root.' );
				}
				$this->assertSafeEntryType( $zip, $index, $stat, str_ends_with( $name, '/' ) );
				if ( ! str_ends_with( $name, '/' ) ) {
					$this->verifyEntryContents( $zip, $index, $stat['size'], $stat['crc'], $expandedLimit );
				}
				$expanded = $this->addExpandedBytes( $expanded, $stat['size'], $expandedLimit );
			}
			if ( null === $root ) {
				$this->fail( self::CODE_LAYOUT_INVALID, 'The archive does not contain a package.' );
			}
			$this->assertNoPathCollisions( $entries );
			return array(
				'expanded'         => $expanded,
				'expected_version' => $this->assertPackageIdentity( $zip, $d, $entries, $root, $installed, $wordpressVersion ),
			);
		} finally {
			$zip->close();
		}
	}

	private function assertInstalledIdentity( Deployment $d, ?string $installed ): void {
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

	private function assertInstalledIdentityAdmission( Deployment $d, ?string $installed ): void {
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
	private function assertEntryCount( int $entries ): void {
		if ( 0 === $entries ) {
			$this->fail( self::CODE_LAYOUT_INVALID, 'The archive does not contain a package.' );
		}
		if ( $entries > self::MAX_ENTRIES ) {
			$this->fail( self::CODE_ENTRY_LIMIT, 'The archive exceeds the entry limit.' );
		}
	}
	private function validateEntryName( string $name ): string {
		$path = ArchiveSafety::normalizePath( $name );
		if ( null === $path ) {
			$this->fail( self::CODE_PATH_UNSAFE, 'The archive contains an unsafe path.' );
		}
		return $path['path'];
	}
	/** @param array<string,array{index:int,directory:bool}> $entries */
	private function assertNoPathCollisions( array $entries ): void {
		$paths = array();
		foreach ( $entries as $path => $entry ) {
			$paths[] = array(
				'path'      => $path,
				'directory' => $entry['directory'],
			);
		}
		$failure = ArchiveSafety::collisionFailure( $paths );
		if ( 'path_duplicate' === $failure ) {
			$this->fail( self::CODE_PATH_COLLISION, 'The archive contains duplicate paths.' );
		}
		if ( 'file_parent_collision' === $failure ) {
			$this->fail( self::CODE_FILE_PARENT_COLLISION, 'The archive contains a file-parent collision.' );
		}
	}
	/** @param array<string,mixed> $stat */
	private function assertSafeEntryType( ZipArchive $zip, int $index, array $stat, bool $namedDirectory ): void {
		if ( ZipArchive::EM_NONE !== (int) ( $stat['encryption_method'] ?? ZipArchive::EM_NONE ) ) {
			$this->fail( self::CODE_ENTRY_ENCRYPTED, 'Encrypted archive entries are unsupported.' );
		}
		$operations = 0;
		$attributes = 0;
		$available  = $zip->getExternalAttributesIndex( $index, $operations, $attributes, ZipArchive::FL_UNCHANGED );
		$failure    = ArchiveSafety::entryTypeFailure( $available ? $operations : null, $available ? $attributes : null, $namedDirectory );
		if ( 'entry_type_unsupported' === $failure ) {
			$this->fail( self::CODE_ENTRY_UNSUPPORTED, 'The archive contains a link or device entry.' );
		}
		if ( 'entry_metadata_invalid' === $failure ) {
			$this->fail( self::CODE_ENTRY_INVALID, 'The archive contains invalid entry metadata.' );
		}
	}
	private function verifyEntryContents( ZipArchive $zip, int $index, int $expectedSize, int $expectedCrc, int $expandedLimit ): void {
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
				$read = $this->addExpandedBytes( $read, strlen( $chunk ), $expandedLimit );
				hash_update( $hash, $chunk );
			}
		} finally {
			fclose( $stream );
		}
		if ( $read !== $expectedSize || ! hash_equals( sprintf( '%08x', $expectedCrc ), hash_final( $hash ) ) ) {
			$this->fail( self::CODE_ARCHIVE_INTEGRITY, 'The archive contains unreadable entry data.' );
		}
	}
	private function addExpandedBytes( int $current, int $entry, int $limit ): int {
		if ( $current < 0 || $entry < 0 || $current > $limit - $entry ) {
			$this->fail( self::CODE_EXPANDED_TOO_LARGE, 'The archive exceeds the expanded-size limit.' );
		}
		return $current + $entry;
	}
	/** @param array<string,array{index:int,directory:bool}> $entries */
	private function assertPackageIdentity( ZipArchive $zip, Deployment $d, array $entries, string $root, ?string $installed, string $wordpressVersion ): string {
		$this->assertInstalledIdentity( $d, $installed );
		$prefix = $this->validateEntryName( $root . ( null !== $d->subdirectory && '' !== $d->subdirectory ? '/' . $d->subdirectory : '' ) );
		$files  = array_filter( $entries, static fn( array $entry, string $name ): bool => ! $entry['directory'] && str_starts_with( $name, $prefix . '/' ), ARRAY_FILTER_USE_BOTH );
		if ( array() === $files ) {
			$this->fail( null !== $d->subdirectory && '' !== $d->subdirectory ? self::CODE_SUBDIRECTORY_MISSING : self::CODE_PACKAGE_DIRECTORY_MISSING, 'The configured package directory is absent from the archive.' );
		}
		if ( 'theme' === $d->packageType ) {
			$style = $files[ $prefix . '/style.css' ] ?? null;
			if ( null === $style ) {
				$this->fail( self::CODE_THEME_MISSING, 'The archive does not contain the expected theme.' );
			}
			$headers = $this->readHeaders( $zip, $style['index'], 'Theme Name' );
			$this->assertCompatibility( $headers, $wordpressVersion );
			return $this->version( $headers );
		}
		$candidates = array();
		foreach ( $files as $name => $entry ) {
			$relative = substr( $name, strlen( $prefix ) + 1 );
			if ( ! str_contains( $relative, '/' ) && str_ends_with( strtolower( $relative ), '.php' ) ) {
				$headers = $this->readHeaders( $zip, $entry['index'], 'Plugin Name', false );
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
		$this->assertCompatibility( $headers, $wordpressVersion );
		return $this->version( $headers );
	}
	/** @return array<string,string>|null */
	private function readHeaders( ZipArchive $zip, int $index, string $required, bool $requiredFile = true ): ?array {
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
			if ( $requiredFile ) {
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
	private function assertCompatibility( array $headers, string $wordpressVersion ): void {
		foreach ( array( $headers['Requires PHP'], $headers['Requires at least'] ) as $version ) {
			if ( '' !== $version && preg_match( '/^[0-9]+(?:\\.[0-9]+){0,3}(?:[-+._][A-Za-z0-9.-]+)?$/D', $version ) !== 1 ) {
				$this->fail( self::CODE_COMPATIBILITY_INVALID, 'The package compatibility header is invalid.' );
			}
		}
		if ( '' !== $headers['Requires PHP'] && version_compare( PHP_VERSION, $headers['Requires PHP'], '<' ) ) {
			$this->fail( self::CODE_REQUIRES_NEWER_PHP, 'The package requires a newer PHP version.' );
		}
		if ( '' !== $headers['Requires at least'] && ( '' === $wordpressVersion || version_compare( $wordpressVersion, $headers['Requires at least'], '<' ) ) ) {
			$this->fail( self::CODE_REQUIRES_NEWER_WP, 'The package requires a newer WordPress version.' );
		}
	}
	/** @return never */
	private function fail( int $code, string $message ): never {
		throw new RuntimeException( $message, $code );
	}
}
