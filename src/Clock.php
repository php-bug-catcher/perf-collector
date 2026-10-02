<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector;

/**
 * Two decisions in this package depend on the current time and nothing else: which minute is
 * complete enough to ship, and which log files a rotation pattern can still be pointing at. Both
 * have to be held still in a test, so neither reads the clock directly.
 */
interface Clock {

	public function now(): float;
}
