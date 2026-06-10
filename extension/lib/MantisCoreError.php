<?php

declare(strict_types=1);

namespace MantisMcp\Extension;

use RuntimeException;

/**
 * A business error signalled by the Mantis core via trigger_error()
 * (e.g. "Issue not found", "Access denied"), converted into an exception
 * by our error handler so tools can report it as a clean tool error.
 */
final class MantisCoreError extends RuntimeException
{
}
