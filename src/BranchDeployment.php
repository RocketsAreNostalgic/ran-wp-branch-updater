<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile,WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors,WordPress.Security.EscapeOutput -- Archive custody contracts require atomic local-file identity operations and are deliberately co-located.
declare(strict_types=1);

namespace RAN\BranchDeployment;

use RuntimeException;

/** Declaration is deliberately source-specific: a branch revision is not a release. */
final readonly class Deployment {
	public function __construct(
		public string $attemptId,
		public string $packageType,
		public string $slug,
		public string $repository,
		public string $repositoryId,
		public string $branch,
		public ?string $expectedHead,
		public string $operation = 'update',
		public ?string $subdirectory = null,
		public ?string $installedIdentifier = null
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $slug ) || ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $operation, array( 'install', 'update' ), true ) || '' === trim( $attemptId ) || '' === trim( $repository ) || '' === trim( $repositoryId )
			|| '' === trim( $branch ) || ( null !== $expectedHead && '' === trim( $expectedHead ) ) ) {
			throw new RuntimeException( 'Invalid branch deployment declaration.' ); }
	}
}

interface BranchProvider {
	/** Must resolve $deployment->expectedHead and reject it when $branch has advanced. */
	public function prepare( Deployment $deployment ): ArchiveOffer;
}

final readonly class ArchiveOffer {
	/** $copyTo is a provider implementation boundary, not a generic network callback framework. */
	public function __construct( public string $provider, public string $repositoryId, public string $resolvedRef, private \Closure $copyTo, private \Closure $verifyHead ) {}
	public function acquire( string $destination ): void {
		( $this->copyTo )( $destination ); }
	public function verifyCurrentHead(): void {
		( $this->verifyHead )(); }
}

interface PackageExecutor {
	/** Run target/maintenance/version admission before the durable mutation fence. */
	public function preflight( Deployment $deployment, PreparedArchive $archive ): array;
	public function execute( Deployment $deployment, PreparedArchive $archive ): void;
}
interface MutationLock {
	public function run( callable $operation ): mixed;
}

/** Exact-byte custody from provider download through the WordPress boundary. */
final class PreparedArchive implements PreparedPackageArtifact {
	private bool $cleaned = false;
	private function __construct( private string $path, public readonly string $resolvedRef, private array $identity, private string $digest, public readonly string $version ) {}
	public static function downloadAndValidate( ArchiveOffer $offer, Deployment $d, string $directory ): self {
		if ( ( file_exists( $directory ) || is_link( $directory ) ) && ( is_link( $directory ) || ! is_dir( $directory ) ) ) {
			throw new RuntimeException( 'Archive directory is unsafe.' );
		}
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Cannot create private archive directory.' );
		}
		chmod( $directory, 0700 );
		if ( is_link( $directory ) || ( fileperms( $directory ) & 0777 ) !== 0700 ) {
			throw new RuntimeException( 'Archive directory is not private.' );
		}
		$path = tempnam( $directory, 'ran-branch-' );
		if ( false === $path || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Cannot create private archive file.' );
		}
		$created = null;
		try {
			$created = self::identity( $path );
			if ( null === $created ) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$offer->acquire( $path );
			$identity = self::identity( $path );
			if ( null === $identity || $identity['dev'] !== $created['dev'] || $identity['ino'] !== $created['ino'] || $identity['size'] < 1 || $identity['size'] > 52428800 ) {
				throw new RuntimeException( 'Archive file identity or size is invalid.' );
			}
			$digest = hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) ) {
				throw new RuntimeException( 'Cannot fingerprint archive.' );
			}
			$inspection = ( new ArchiveValidator() )->validate(
				$path,
				$d,
				'update' === $d->operation ? $d->installedIdentifier : null,
				52428800,
				209715200,
				function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : ''
			);
			return new self( $path, $offer->resolvedRef, $identity, $digest, $inspection['expected_version'] );
		} catch ( \Throwable $e ) {
			if ( null !== $created ) {
				$current = self::identity( $path );
				if ( null !== $current && $current['dev'] === $created['dev'] && $current['ino'] === $created['ino'] ) {
					if ( ! unlink( $path ) ) {
						throw new RuntimeException( 'Rejected archive could not be cleaned safely.', 0, $e );
					}
				} elseif ( file_exists( $path ) || is_link( $path ) ) {
					throw new RuntimeException( 'Rejected archive replacement could not be cleaned safely.', 0, $e ); }
			}
			throw $e;
		}
	}
	public function path(): string {
		$this->assertUnchanged();
		return $this->path; }
	public function getPath(): string {
		return $this->path(); }
	public function getExpectedVersion(): string {
		return $this->version; }
	public function assertUnchanged(): void {
		if ( $this->cleaned || self::identity( $this->path ) !== $this->identity || ! hash_equals( $this->digest, (string) hash_file( 'sha256', $this->path ) ) ) {
			throw new RuntimeException( 'Prepared archive changed before use.' );
		}
	}
	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		} $this->assertUnchanged();
		if ( ! unlink( $this->path ) ) {
			throw new RuntimeException( 'Cannot safely remove archive.' );
		} $this->cleaned = true; }
	private static function identity( string $path ): ?array {
		clearstatcache( true, $path );
		$s = @lstat( $path );
		if ( ! is_array( $s ) || is_link( $path ) || ! is_file( $path ) || ( ( $s['mode'] & 0170000 ) !== 0100000 ) || ( ( $s['mode'] & 0777 ) !== 0600 ) || $s['nlink'] !== 1 ) {
			return null;
		}
		return array(
			'dev'   => (int) $s['dev'],
			'ino'   => (int) $s['ino'],
			'size'  => (int) $s['size'],
			'mode'  => (int) $s['mode'] & 0777,
			'nlink' => (int) $s['nlink'],
		);
	}
}
