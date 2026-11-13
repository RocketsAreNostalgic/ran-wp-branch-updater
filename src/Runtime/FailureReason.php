<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

/**
 * The closed, stable vocabulary of failure reasons. A reason refines, and never
 * replaces, the closed outcome code. Values match /\A[a-z][a-z0-9_]{0,47}\z/.
 * Removing or renaming a value is a breaking change; adding one is additive.
 */
enum FailureReason: string {
	// Provider and source checks.
	case SELECTED_COMMIT_UNSUPPORTED = 'selected_commit_unsupported';
	case REPOSITORY_MISMATCH         = 'repository_mismatch';
	case REF_MISMATCH                = 'ref_mismatch';
	case PROVIDER_ERROR              = 'provider_error';
	// Archive custody and validation.
	case ARCHIVE_PREPARE_FAILED  = 'archive_prepare_failed';
	case ARCHIVE_CUSTODY_CHANGED = 'archive_custody_changed';
	case ZIP_UNAVAILABLE         = 'zip_unavailable';
	case ZIP_INVALID             = 'zip_invalid';
	case ENTRY_LIMIT             = 'entry_limit';
	case ENTRY_INVALID           = 'entry_invalid';
	case PATH_UNSAFE             = 'path_unsafe';
	case PATH_COLLISION          = 'path_collision';
	case LAYOUT_INVALID          = 'layout_invalid';
	case ENTRY_UNSUPPORTED       = 'entry_unsupported';
	case ENTRY_ENCRYPTED         = 'entry_encrypted';
	case COMPRESSED_TOO_LARGE    = 'compressed_too_large';
	case EXPANDED_TOO_LARGE      = 'expanded_too_large';
	case ARCHIVE_CORRUPT         = 'archive_corrupt';
	case SUBDIRECTORY_MISSING    = 'subdirectory_missing';
	case PACKAGE_DIR_MISSING     = 'package_dir_missing';
	case IDENTITY_MISMATCH       = 'identity_mismatch';
	case HEADER_MISSING          = 'header_missing';
	case HEADER_UNREADABLE       = 'header_unreadable';
	case VERSION_MISSING         = 'version_missing';
	case VERSION_INVALID         = 'version_invalid';
	case COMPAT_INVALID          = 'compat_invalid';
	case REQUIRES_NEWER_PHP      = 'requires_newer_php';
	case REQUIRES_NEWER_WP       = 'requires_newer_wp';
	case PLUGIN_MISSING          = 'plugin_missing';
	case THEME_MISSING           = 'theme_missing';
	case MULTIPLE_PLUGINS        = 'multiple_plugins';
	// Preflight.
	case PREFLIGHT_MULTISITE             = 'preflight_multisite';
	case PREFLIGHT_FILE_MODS_DISABLED    = 'preflight_file_mods_disabled';
	case PREFLIGHT_FILESYSTEM_NOT_DIRECT = 'preflight_filesystem_not_direct';
	case PREFLIGHT_MAINTENANCE_ACTIVE    = 'preflight_maintenance_active';
	// Mutation lock.
	case LOCK_HELD                = 'lock_held';
	case LOCK_TOKEN_INVALID       = 'lock_token_invalid';
	case LOCK_STALE_RELEASE_FAIL  = 'lock_stale_release_failed';
	case LOCK_STALE_REINSERT_FAIL = 'lock_stale_reinsert_failed';
	case LOCK_STORAGE             = 'lock_storage';
	case LOCK_RELEASE             = 'lock_release';
	// WordPress Core execution (one per CorePackageExecutionFailure value).
	case CORE_INVALID_REQUEST     = 'core_invalid_request';
	case CORE_RUNTIME_UNSUPPORTED = 'core_runtime_unsupported';
	case CORE_WORDPRESS_REFUSED   = 'core_wordpress_refused';
	case CORE_WORDPRESS_FAILED    = 'core_wordpress_failed';
	case CORE_WORDPRESS_RESTORED  = 'core_wordpress_restored';
	case CORE_WORDPRESS_UNCERTAIN = 'core_wordpress_uncertain';
	case CORE_OPERATION_MISMATCH  = 'core_operation_mismatch';
	// Postcondition and restoration checks.
	case BASELINE_UNREADABLE = 'baseline_unreadable';
	case BASELINE_CHANGED    = 'baseline_changed';
	case IDENTIFIER_MISMATCH = 'identifier_mismatch';
	case ADOPT_FAILED        = 'adopt_failed';
	// Ambiguous durability, and cleanup that failed beside another cause.
	case DURABILITY_UNCERTAIN = 'durability_uncertain';
	case JOURNAL_UNCERTAIN    = 'journal_uncertain';
	case CLEANUP_ALSO_FAILED  = 'cleanup_also_failed';

	public static function for_core( CorePackageExecutionFailure $failure ): self {
		return self::from( 'core_' . $failure->value );
	}
}
