<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests;

use BugCatcher\PerfCollector\Clock;

/**
 * "Which minute is complete" and "which day's log file" are decisions the aggregator makes from
 * the clock, so the clock is the thing the tests have to hold still.
 */
final class FrozenClock implements Clock {

	private float $now;

	public function __construct(string $time) {
		$this->now = (float) strtotime($time);
	}

	public function now(): float {
		return $this->now;
	}

	public function advance(float $seconds): void {
		$this->now += $seconds;
	}
}
