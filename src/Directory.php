<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;

/**
 * The state directory is the tool's own - cursors and, by default, the lock file - so the tool
 * creates it. The first run of a fresh install must not fail because nobody ran mkdir.
 */
final class Directory {

	public static function ensure(string $path): void {
		// The repeated is_dir() is not redundant: a concurrent run may win the race to mkdir.
		if (!is_dir($path) && !@mkdir($path, 0o777, true) && !is_dir($path)) {
			throw new InvalidConfiguration(sprintf('The directory "%s" does not exist and cannot be created.', $path));
		}
	}
}
