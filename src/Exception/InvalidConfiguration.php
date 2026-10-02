<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Exception;

use RuntimeException;

/**
 * Something the operator wrote is wrong - a rules file, a command line option, a path. The CLI
 * turns these into an exit code and a line on stderr rather than a stack trace.
 */
final class InvalidConfiguration extends RuntimeException {
}
