<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** A WordPress updater-lock database read/write could not be reconciled. */
final class BranchDeploymentLockStorageFailure extends RuntimeException {}
