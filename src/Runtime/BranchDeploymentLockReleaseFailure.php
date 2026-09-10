<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** The exact mutation lock token could not be released, so state is uncertain. */
final class BranchDeploymentLockReleaseFailure extends RuntimeException {}
