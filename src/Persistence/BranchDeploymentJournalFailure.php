<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RuntimeException;

/** A persistence failure means the durable execution state is unknown. */
final class BranchDeploymentJournalFailure extends RuntimeException {}
