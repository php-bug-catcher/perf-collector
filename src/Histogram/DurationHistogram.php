<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Histogram;

/**
 * Counts per bin on a fixed logarithmic scale. Histograms **add exactly**, which is the entire
 * reason one is shipped instead of a mean and a maximum: percentiles survive every roll-up with
 * bounded error, where a mean hides precisely the regression you are looking for and a maximum
 * reacts to a single outlier.
 *
 * A bin is half-open and lower-inclusive - bin 1 is `[1 ms, 2 ms)` - and the last bin holds
 * everything from the final edge upwards.
 *
 * `EDGES_MS` is a wire format. The server keeps its own copy in
 * `BugCatcher\Service\Perf\Histogram\HistogramBins` with a test pinning it against this one, so
 * changing the edges is a migration of stored data and not an edit.
 */
final class DurationHistogram {

	public const array EDGES_MS = [1, 2, 5, 10, 25, 50, 100, 250, 500, 1_000, 2_000, 5_000, 10_000, 30_000, 60_000];

	public const int BIN_COUNT = 16;

	/** @var list<int> */
	private array $bins;

	public function __construct() {
		$this->bins = array_fill(0, self::BIN_COUNT, 0);
	}

	public function record(float $seconds, int $weight = 1): void {
		$milliseconds = $seconds * 1000;
		foreach (self::EDGES_MS as $bin => $edge) {
			if ($milliseconds < $edge) {
				$this->bins[$bin] += $weight;

				return;
			}
		}

		$this->bins[self::BIN_COUNT - 1] += $weight;
	}

	public function merge(self $other): void {
		foreach ($other->bins as $bin => $count) {
			$this->bins[$bin] += $count;
		}
	}

	/** @return list<int> */
	public function toArray(): array {
		return $this->bins;
	}
}
