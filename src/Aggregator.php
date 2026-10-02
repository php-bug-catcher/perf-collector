<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Log\Cursor;
use BugCatcher\PerfCollector\Log\CursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Sample\Sample;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\Ship\HttpShipper;
use Generator;

/**
 * Lock, resolve, read, decode, group, ship, and only then move the cursor.
 *
 * Two rules carry the whole design:
 *
 *   - **Only a 2xx advances the cursor.** A failed ship leaves everything exactly where it was,
 *     so the next run retries the same window instead of losing it.
 *   - **The cursor is the record of what has been shipped.** Nothing is read twice, which is
 *     what lets the server add counters without a batch ever doubling a number.
 */
final readonly class Aggregator {

	public function __construct(
		private LogPathResolver $paths,
		private CursorStore $cursors,
		private JsonLinesReader $reader,
		private SampleDecoder $decoder,
		private SampleGrouper $grouper,
		private BatchPayloadBuilder $payloads,
		private HttpShipper $shipper,
		private FileLock $lock,
		private string $logPattern,
		private string $projectCode,
		private int $maxBytesPerRun = 16_777_216,
		private bool $dryRun = false,
	) {
	}

	public function run(): AggregateResult {
		if (!$this->lock->acquire()) {
			return AggregateResult::lockedOut();
		}

		try {
			return $this->aggregate();
		} finally {
			$this->lock->release();
		}
	}

	private function aggregate(): AggregateResult {
		$read      = 0;
		$malformed = 0;
		$buckets   = [];
		/** @var array<string,Cursor> $reached */
		$reached = [];
		$budget  = $this->maxBytesPerRun;

		foreach ($this->paths->resolve($this->logPattern) as $file) {
			$cursor = $this->cursors->load($file) ?? new Cursor($file, (int) @fileinode($file), 0);
			$stream = $this->reader->read($file, $cursor->offset, $budget);
			$buckets = $this->grouper->group($this->samples($stream, $read, $malformed), $buckets);

			$offset         = $stream->getReturn();
			$reached[$file] = $cursor->movedTo($offset);
			$budget        -= $offset - $cursor->offset;

			if ($budget <= 0) {
				break;
			}
		}

		if ($buckets === []) {
			return new AggregateResult(samplesRead: $read, malformed: $malformed);
		}

		if ($this->dryRun) {
			return new AggregateResult($read, $malformed, count($buckets));
		}

		[$status, $response] = $this->shipper->ship($this->payloads->build($this->projectCode, $buckets));
		if (!HttpShipper::accepted($status)) {
			return new AggregateResult($read, $malformed, count($buckets), false, $status);
		}

		foreach ($reached as $cursor) {
			$this->cursors->save($cursor->movedTo($this->reclaim($cursor->path, $cursor->offset)));
		}

		return new AggregateResult($read, $malformed, count($buckets), true, $status);
	}

	/**
	 * @param  Generator<int,string,void,int> $lines
	 * @return Generator<int,Sample,void,void>
	 */
	private function samples(Generator $lines, int &$read, int &$malformed): Generator {
		foreach ($lines as $line) {
			$sample = $this->decoder->decode($line);
			if ($sample === null) {
				$malformed++;

				continue;
			}

			$read++;

			yield $sample;
		}
	}

	/**
	 * "The machine keeps nothing": a log we have read to the end is emptied, so a monitored box
	 * does not accumulate the raw samples it has already handed over.
	 *
	 * Under the same exclusive lock the hook writes with, and only when the file is still exactly
	 * as long as what we read - anything appended while the batch was in flight is left for the
	 * next run rather than thrown away. Returns the offset to remember.
	 */
	private function reclaim(string $path, int $offset): int {
		$handle = @fopen($path, 'r+');
		if ($handle === false) {
			return $offset;
		}

		try {
			if (!flock($handle, LOCK_EX)) {
				return $offset;
			}

			clearstatcache(true, $path);
			if (filesize($path) !== $offset) {
				return $offset;
			}

			return ftruncate($handle, 0) ? 0 : $offset;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
