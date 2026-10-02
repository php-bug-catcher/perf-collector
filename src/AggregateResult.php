<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector;

/** What one run of the aggregator did, for the command line and for cron mail. */
final readonly class AggregateResult {

	public function __construct(
		public int $samplesRead = 0,
		public int $malformed = 0,
		public int $bucketsShipped = 0,
		public bool $shipped = false,
		public int $httpStatus = 0,
		public bool $lockedOut = false,
	) {
	}

	/** Another run already holds the lock. Not a failure: there will be another minute. */
	public static function lockedOut(): self {
		return new self(lockedOut: true);
	}

	public function summary(): string {
		if ($this->lockedOut) {
			return 'another run holds the lock; nothing done';
		}

		return sprintf(
			'%d samples read, %d malformed, %d buckets %s%s',
			$this->samplesRead,
			$this->malformed,
			$this->bucketsShipped,
			$this->shipped ? 'shipped' : 'not shipped',
			$this->httpStatus === 0 ? '' : sprintf(' (HTTP %d)', $this->httpStatus),
		);
	}
}
