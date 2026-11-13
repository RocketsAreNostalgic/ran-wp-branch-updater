<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use Closure;
use InvalidArgumentException;
use RAN\WPBranchUpdater\V1\Archive\PackageSubdirectory;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;
use Throwable;
use WP_Error;

/**
 * Give one immutable package transaction to WordPress core.
 *
 * Provider work, persistence, locking, cleanup and postcondition policy belong
 * to the deployment runner. This class owns only the scoped WordPress call.
 */
class WordPressCorePackageExecutor {
	/**
	 * The only WP_Error codes whose text is passed on as failure detail: stable, public WordPress upgrader
	 * and filesystem codes. Any other code, and every WP_Error message (which can hold local paths), is dropped.
	 */
	private const UPGRADER_ERROR_CODES = array( 'incompatible_archive', 'incompatible_archive_empty', 'incompatible_archive_no_plugins', 'incompatible_archive_theme_no_style', 'incompatible_archive_theme_no_name', 'incompatible_archive_theme_no_template', 'incompatible_archive_theme_no_theme', 'fs_unavailable', 'fs_error', 'fs_no_root_dir', 'fs_no_content_dir', 'fs_no_plugins_dir', 'fs_no_themes_dir', 'fs_no_folder', 'mkdir_failed', 'mkdir_failed_destination', 'copy_failed', 'copy_failed_to_dest', 'files_not_writable', 'remove_old_failed', 'folder_exists', 'bad_request', 'no_package', 'download_failed', 'up_to_date', 'plugin_not_found', 'theme_not_found', 'plugin_update_fatal_error_rollback_successful' );
	/** Closed causes carried as the data of this package's own source-selection WP_Error. */
	private const SOURCE_FAILURES = array( 'source_invalid', 'subdirectory_missing', 'destination_exists', 'move_failed' );
	private const SOURCE_ERROR    = 'ran_branch_deployment_invalid_package_source';
	/** @var Closure(string, string, string, object|null): mixed|null */
	private ?Closure $core_operation;
	private string $offer_namespace;

	/** @param callable(string, string, string, object|null): mixed|null $core_operation */
	public function __construct( ?callable $core_operation = null, string $offer_namespace = 'ran-branch-deployment' ) {
		$this->core_operation  = null === $core_operation ? null : Closure::fromCallable( $core_operation );
		$this->offer_namespace = $offer_namespace;
	}

	public function install_plugin( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		return $this->execute_install( 'plugin', $artifact, $package_slug, $subdirectory );
	}
	public function install_theme( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		return $this->execute_install( 'theme', $artifact, $package_slug, $subdirectory );
	}
	public function update_plugin( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory, string $plugin_file ): CorePackageExecutionResult {
		return $this->execute_update( 'plugin', $artifact, $package_slug, $subdirectory, $plugin_file );
	}
	public function update_theme( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory, string $stylesheet ): CorePackageExecutionResult {
		return $this->execute_update( 'theme', $artifact, $package_slug, $subdirectory, $stylesheet );
	}

	private function execute_install( string $type, PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory ): CorePackageExecutionResult {
		$inputs = $this->validate_inputs( $artifact, $package_slug, $subdirectory );
		if ( $inputs instanceof CorePackageExecutionResult ) {
			return $inputs;
		}
		$parent_failure = 'theme' === $type ? $this->theme_parent_failure( $artifact, $inputs['slug'], $inputs['subdirectory'] ) : null;
		if ( null !== $parent_failure ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST, $parent_failure );
		}
		$pre_download  = $this->pre_download_filter( $type, 'install', $artifact, null );
		$source_filter = $this->source_selection_filter( $inputs['slug'], $inputs['subdirectory'], $type, 'install', null );
		$completions   = array();
		$complete      = $this->completion_collector( $completions );
		add_filter( 'upgrader_pre_download', $pre_download, 10, 4 );
		add_filter( 'upgrader_source_selection', $source_filter, 10, 4 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );
		try {
			$result = $this->run_core_operation( 'install', $type, $inputs['path'], null );
			return $this->map_result( $result, $type, 'install', null, $completions );
		} catch ( Throwable $failure ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN, $failure instanceof \Error ? 'php_error' : 'exception' );
		} finally {
			remove_filter( 'upgrader_pre_download', $pre_download, 10 );
			remove_filter( 'upgrader_source_selection', $source_filter, 10 );
			remove_action( 'upgrader_process_complete', $complete, 100 );
		}
	}

	private function execute_update( string $type, PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory, string $installed_identifier ): CorePackageExecutionResult {
		$inputs = $this->validate_inputs( $artifact, $package_slug, $subdirectory, $installed_identifier );
		if ( $inputs instanceof CorePackageExecutionResult ) {
			return $inputs;
		}
		if ( ( 'plugin' === $type && dirname( $inputs['identifier'] ) !== $inputs['slug'] ) || ( 'theme' === $type && $inputs['identifier'] !== $inputs['slug'] ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST, 'identifier_mismatch' );
		}
		$offer            = $this->update_offer( $type, $artifact, $inputs['slug'], $inputs['identifier'] );
		$transient_hook   = 'pre_site_transient_update_' . ( 'plugin' === $type ? 'plugins' : 'themes' );
		$transient_filter = $this->transient_filter( $type, $offer, $inputs['identifier'] );
		$pre_download     = $this->pre_download_filter( $type, 'update', $artifact, $inputs['identifier'] );
		$source_filter    = $this->source_selection_filter( $inputs['slug'], $inputs['subdirectory'], $type, 'update', $inputs['identifier'] );
		$vcs_filter       = $this->vcs_filter( $type, $inputs['identifier'] );
		$core_auto_update = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		$cron_filter      = static fn (): bool => true;
		$completions      = array();
		$complete         = $this->completion_collector( $completions );
		if ( false !== $core_auto_update && ! remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $core_auto_update ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN, 'hook_removal_failed' );
		}
		add_filter( $transient_hook, $transient_filter, 10, 1 );
		add_filter( 'upgrader_pre_download', $pre_download, 10, 4 );
		add_filter( 'upgrader_source_selection', $source_filter, 10, 4 );
		add_filter( 'automatic_updates_is_vcs_checkout', $vcs_filter, 10, 2 );
		add_filter( 'wp_doing_cron', $cron_filter, PHP_INT_MAX, 1 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );
		try {
			$result = $this->run_core_operation( 'update', $type, $inputs['path'], $offer );
			return $this->map_result( $result, $type, 'update', $inputs['identifier'], $completions );
		} catch ( Throwable $failure ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN, $failure instanceof \Error ? 'php_error' : 'exception' );
		} finally {
			remove_filter( $transient_hook, $transient_filter, 10 );
			remove_filter( 'upgrader_pre_download', $pre_download, 10 );
			remove_filter( 'upgrader_source_selection', $source_filter, 10 );
			remove_filter( 'automatic_updates_is_vcs_checkout', $vcs_filter, 10 );
			remove_filter( 'wp_doing_cron', $cron_filter, PHP_INT_MAX );
			remove_action( 'upgrader_process_complete', $complete, 100 );
			if ( false !== $core_auto_update ) {
				add_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update', $core_auto_update );
			}
		}
	}

	/** @return array{path:string,slug:string,subdirectory:string|null,identifier:string}|CorePackageExecutionResult */
	private function validate_inputs( PreparedPackageArtifact $artifact, string $package_slug, ?string $subdirectory, string $installed_identifier = '' ): array|CorePackageExecutionResult {
		try {
			$artifact->assert_unchanged();
			$slug         = PackageSubdirectory::normalize_slug( $package_slug );
			$subdirectory = PackageSubdirectory::normalize( $subdirectory );
			$identifier   = '' === $installed_identifier ? '' : InstalledPackageIdentifier::normalize( $installed_identifier );
		} catch ( Throwable $failure ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST, $failure instanceof ReasonedFailure ? 'archive_changed' : 'input_invalid' );
		}
		return array(
			'path'         => $artifact->get_path(),
			'slug'         => $slug,
			'subdirectory' => $subdirectory,
			'identifier'   => $identifier,
		);
	}

	private function update_offer( string $type, PreparedPackageArtifact $artifact, string $slug, string $identifier ): object {
		$offer          = array(
			'id'           => $this->offer_namespace . '/' . $slug,
			'slug'         => $slug,
			'new_version'  => $artifact->get_expected_version(),
			'package'      => $artifact->get_path(),
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);
		$offer[ $type ] = $identifier;
		return (object) $offer;
	}

	private function transient_filter( string $type, object $offer, string $identifier ): Closure {
		return static function ( mixed $transient ) use ( $type, $offer, $identifier ): object {
			if ( ! $transient instanceof \stdClass ) {
				// Native update transients are public value bags, not arbitrary mutable objects.
				$fields    = is_object( $transient ) ? get_object_vars( $transient ) : array();
				$transient = new \stdClass();
				foreach ( $fields as $name => $value ) {
					$transient->{$name} = $value;
				}
				if ( isset( $transient->response ) && is_array( $transient->response ) ) {
					// Replacing an offer must not write through a foreign array-element reference.
					unset( $transient->response[ $identifier ] );
				}
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $identifier ] = 'plugin' === $type ? $offer : (array) $offer;
			return $transient;
		};
	}

	private function pre_download_filter( string $type, string $action, PreparedPackageArtifact $artifact, ?string $identifier ): Closure {
		return static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( $type, $action, $artifact, $identifier ): mixed {
			if ( false !== $reply ) {
				return $reply;
			}
			$archive_path         = $artifact->get_path();
			$operation_identifier = null === $identifier || ( $extra[ $type ] ?? null ) === $identifier;
			if ( is_string( $package ) && hash_equals( $archive_path, $package ) && ( $extra['type'] ?? null ) === $type && ( $extra['action'] ?? null ) === $action && $operation_identifier ) {
				$artifact->assert_unchanged();
				return $archive_path;
			}
			return $reply;
		};
	}

	private function vcs_filter( string $type, string $identifier ): Closure {
		$allowed_context = WP_PLUGIN_DIR;
		if ( 'theme' === $type ) {
			$theme_root = realpath( get_theme_root( $identifier ) );
			if ( false === $theme_root || ! is_dir( $theme_root ) ) {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the two-argument WordPress VCS filter callback signature.
				return static fn ( bool $checkout, string $context ): bool => $checkout;
			}
			$allowed_context = $theme_root;
		}
		return static function ( bool $checkout, string $context ) use ( $type, $allowed_context ): bool {
			if ( 'plugin' === $type ) {
				return WP_PLUGIN_DIR === $context ? false : $checkout;
			}
			$canonical_context = realpath( $context );
			return false !== $canonical_context && hash_equals( $allowed_context, $canonical_context ) ? false : $checkout;
		};
	}

	private function source_selection_filter( string $slug, ?string $subdirectory, string $type, string $action, ?string $identifier ): Closure {
		return static function ( mixed $source, mixed $remote_source, mixed $upgrader, array $extra ) use ( $slug, $subdirectory, $type, $action, $identifier ): mixed {
			if ( ( $extra['type'] ?? null ) !== $type || ( $extra['action'] ?? null ) !== $action || ( null !== $identifier && ( $extra[ $type ] ?? null ) !== $identifier ) ) {
				return $source;
			}
			if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
				return self::invalid_package_source( 'source_invalid' );
			}
			$source_root = realpath( $source );
			$remote_root = realpath( $remote_source );
			if ( false === $source_root || false === $remote_root || ! is_dir( $source_root ) || ! is_dir( $remote_root ) || ! self::is_canonical_child( $source_root, $remote_root ) ) {
				return self::invalid_package_source( 'source_invalid' );
			}
			$selected_source = $source_root;
			if ( null !== $subdirectory ) {
				$selected_source = realpath( $source_root . DIRECTORY_SEPARATOR . $subdirectory );
				if ( false === $selected_source || ! is_dir( $selected_source ) || ! self::is_canonical_child( $selected_source, $source_root ) ) {
					return self::invalid_package_source( 'subdirectory_missing' );
				}
			}
			$destination = $remote_root . DIRECTORY_SEPARATOR . $slug;
			if ( hash_equals( $selected_source, $destination ) ) {
				return trailingslashit( $selected_source );
			}
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return self::invalid_package_source( 'destination_exists' );
			}
			global $wp_filesystem;
			if ( ! $wp_filesystem instanceof \WP_Filesystem_Base || ! $wp_filesystem->move( $selected_source, $destination, false ) ) {
				return self::invalid_package_source( 'move_failed' );
			}
			return trailingslashit( $destination );
		};
	}

	private static function invalid_package_source( string $cause = 'source_invalid' ): WP_Error {
		return new WP_Error( self::SOURCE_ERROR, '', $cause );
	}

	/** Only an allow-listed code, or this package's own closed cause, becomes detail; the message never does. */
	private function error_detail( WP_Error $error ): ?string {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		if ( self::SOURCE_ERROR === $code ) {
			return is_string( $data ) && in_array( $data, self::SOURCE_FAILURES, true ) ? $data : null;
		}
		return is_string( $code ) && in_array( $code, self::UPGRADER_ERROR_CODES, true ) ? $code : null;
	}

	/** Null when the theme has no missing parent; otherwise a closed cause. */
	private function theme_parent_failure( PreparedPackageArtifact $artifact, string $slug, ?string $subdirectory ): ?string {
		$parent = $this->theme_parent_from_archive( $artifact, $subdirectory );
		if ( false === $parent ) {
			return 'theme_archive_invalid';
		}
		if ( null === $parent ) {
			return null;
		}
		if ( $slug === $parent || ! function_exists( 'wp_get_theme' ) ) {
			return 'theme_parent_invalid';
		}
		try {
			return wp_get_theme( $parent )->exists() ? null : 'theme_parent_missing';
		} catch ( Throwable ) {
			return 'theme_parent_missing';
		}
	}

	private function theme_parent_from_archive( PreparedPackageArtifact $artifact, ?string $subdirectory ): string|null|false {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $artifact->get_path(), \ZipArchive::RDONLY ) ) {
			return false;
		}
		$subdirectory_segments = null === $subdirectory ? array() : explode( '/', $subdirectory );
		$candidates            = array();
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive owns the numFiles property name.
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index );
				if ( ! is_string( $name ) || str_contains( $name, '\\' ) || str_starts_with( $name, '/' ) ) {
					continue;
				}
				$segments = explode( '/', trim( $name, '/' ) );
				if ( count( $segments ) !== count( $subdirectory_segments ) + 2 || 'style.css' !== end( $segments ) || array_slice( $segments, 1, -1 ) !== $subdirectory_segments ) {
					continue;
				}
				$candidates[] = $index;
			}
			if ( 1 !== count( $candidates ) ) {
				return false;
			}
			$header = $zip->getFromIndex( $candidates[0], 8192 );
		} finally {
			$zip->close();
		}
		if ( ! is_string( $header ) ) {
			return false;
		}
		$header = str_replace( "\r", "\n", $header );
		$parent = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Template:(.*)$/mi', $header, $match ) === 1 && $match[1] ? trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ) : '';
		if ( '' === $parent ) {
			return null;
		}
		try {
			return PackageSubdirectory::normalize_slug( $parent );
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function is_canonical_child( string $path, string $parent_path ): bool {
		return $path !== $parent_path && str_starts_with( $path . DIRECTORY_SEPARATOR, $parent_path . DIRECTORY_SEPARATOR );
	}
	/**
	 * @param list<array<array-key,mixed>> $completions
	 * @return Closure(object,array<array-key,mixed>):void
	 */
	private function completion_collector( array &$completions ): Closure {
		return static function ( object $upgrader, array $extra ) use ( &$completions ): void {
			$completions[] = $extra;
		};
	}
	/**
	 * @param list<array<array-key,mixed>> $completions
	 */
	private function map_result( mixed $result, string $type, string $action, ?string $identifier, array $completions ): CorePackageExecutionResult {
		$successful_installation = $this->is_canonical_installation_result( $result );
		$requires_completion     = true === $result || $successful_installation || $this->is_restored_plugin_failure( $type, $result );
		if ( array() !== $completions || $requires_completion ) {
			if ( 1 !== count( $completions ) || ! $this->completion_matches( $completions[0], $type, $action, $identifier ) ) {
				return CorePackageExecutionResult::failed( CorePackageExecutionFailure::OPERATION_MISMATCH );
			}
		}
		if ( true === $result || $successful_installation ) {
			return CorePackageExecutionResult::succeeded();
		}
		if ( false === $result ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED );
		}
		if ( $this->is_restored_plugin_failure( $type, $result ) ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_RESTORED );
		}
		if ( $result instanceof WP_Error ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, $this->error_detail( $result ) );
		}
		return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN );
	}
	private function is_canonical_installation_result( mixed $result ): bool {
		if ( ! is_array( $result ) || array_keys( $result ) !== array( 'source', 'source_files', 'destination', 'destination_name', 'local_destination', 'remote_destination', 'clear_destination' ) || ! is_string( $result['source'] ) || ! is_array( $result['source_files'] ) || ! array_is_list( $result['source_files'] ) || ! is_string( $result['destination'] ) || ! is_string( $result['destination_name'] ) || ! is_string( $result['local_destination'] ) || ! is_string( $result['remote_destination'] ) || ! is_bool( $result['clear_destination'] ) ) {
			return false;
		}
		foreach ( $result['source_files'] as $source_file ) {
			if ( ! is_string( $source_file ) ) {
				return false;
			}
		}
		return true;
	}
	/**
	 * @param array<array-key,mixed> $completion
	 */
	private function completion_matches( array $completion, string $type, string $action, ?string $identifier ): bool {
		if ( ( $completion['type'] ?? null ) !== $type || ( $completion['action'] ?? null ) !== $action ) {
			return false;
		}
		return null === $identifier || ( $completion[ $type ] ?? null ) === $identifier;
	}
	private function is_restored_plugin_failure( string $type, mixed $result ): bool {
		return 'plugin' === $type && $result instanceof WP_Error && 'plugin_update_fatal_error_rollback_successful' === $result->get_error_code();
	}
	private function run_core_operation( string $action, string $type, string $archive_path, ?object $offer ): mixed {
		if ( null !== $this->core_operation ) {
			return ( $this->core_operation )( $action, $type, $archive_path, $offer );
		}
		$this->load_word_press_upgraders();
		if ( 'install' === $action ) {
			$skin = new \Automatic_Upgrader_Skin();
			return 'plugin' === $type ? ( new \Plugin_Upgrader( $skin ) )->install( $archive_path ) : ( new \Theme_Upgrader( $skin ) )->install( $archive_path );
		}
		if ( null === $offer ) {
			throw new InvalidArgumentException( 'WordPress update requires an offer.' );
		}
		return ( new \WP_Automatic_Updater() )->update( $type, $offer );
	}
	private function load_word_press_upgraders(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new \RuntimeException( 'WordPress is unavailable.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	}
}
