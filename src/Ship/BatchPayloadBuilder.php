<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Ship;

use BugCatcher\PerfCollector\Aggregate\BucketAccumulator;

/**
 * One POST per run, not one per bucket: the body of `POST /api/perf_buckets`.
 *
 * `serverName` lives on each row rather than on the batch, because it is part of the bucket key -
 * a log directory shared between machines can legitimately produce rows for more than one.
 */
final readonly class BatchPayloadBuilder {

	/** @param iterable<BucketAccumulator> $buckets */
	public function build(string $projectCode, iterable $buckets): string {
		$rows = [];
		foreach ($buckets as $bucket) {
			$rows[] = $bucket->toPayloadRow();
		}

		return json_encode([
			'projectCode' => $projectCode,
			'rows'        => $rows,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}
}
