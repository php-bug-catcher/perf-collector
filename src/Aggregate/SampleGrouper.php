<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Aggregate;

use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\Sample;

/**
 * Samples in, one accumulator per `(minute, machine, vhost, normalised path)` out. Takes an
 * iterable so the log can be streamed: memory is bounded by the number of distinct buckets, not
 * by the number of lines.
 *
 * Every sample it is handed is grouped, including one in the minute that is still running. The
 * alternative - holding the current minute back for the next run - cannot be made exact, because
 * the hook writes its line in shutdown: the log is in finish order while the bucket key is start
 * time, so a request that began at 10:00:59 and ran for 90 seconds is read long after 10:00 would
 * have been declared complete. Shipping it late is correct instead of approximate, because the
 * server's upsert adds counters: a bucket that is topped up by a later batch converges on the
 * same numbers.
 */
final readonly class SampleGrouper {

	public function __construct(private PathNormalizer $normalizer) {
	}

	/**
	 * @param  iterable<Sample>               $samples
	 * @return array<string,BucketAccumulator>
	 */
	public function group(iterable $samples): array {
		$buckets = [];

		foreach ($samples as $sample) {
			$key   = new BucketKey(
				$sample->minute(),
				$sample->serverName,
				$sample->host,
				$this->normalizer->normalize($sample->path),
			);
			$index = (string) $key;

			$buckets[$index] ??= new BucketAccumulator($key);
			$buckets[$index]->add($sample);
		}

		return $buckets;
	}
}
