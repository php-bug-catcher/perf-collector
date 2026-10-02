<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Histogram;

use BugCatcher\PerfCollector\Histogram\DurationHistogram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Histograms add exactly, which is the entire point of storing one: p50, p95 and p99 can be
 * estimated at any roll-up level with bounded error, where a mean hides precisely the regression
 * you are looking for. The bin edges are a wire format shared with the server - the server has a
 * copy of this list and a test that pins it against this one - so changing them is a migration,
 * not an edit.
 */
final class DurationHistogramTest extends TestCase {

	public function testTheBinEdgesAreTheOnesTheServerExpects(): void {
		$this->assertSame(
			[1, 2, 5, 10, 25, 50, 100, 250, 500, 1_000, 2_000, 5_000, 10_000, 30_000, 60_000],
			DurationHistogram::EDGES_MS,
		);
	}

	public function testThereIsOneOverflowBinOnTopOfTheEdges(): void {
		$this->assertSame(16, DurationHistogram::BIN_COUNT);
		$this->assertCount(16, (new DurationHistogram())->toArray());
	}

	public function testAFreshHistogramIsAllZeroesRatherThanEmpty(): void {
		$this->assertSame(array_fill(0, 16, 0), (new DurationHistogram())->toArray());
	}

	#[DataProvider('samples')]
	public function testASampleLandsInTheBinItsDurationBelongsTo(float $seconds, int $expectedBin): void {
		$histogram = new DurationHistogram();

		$histogram->record($seconds);

		$this->assertSame($expectedBin, $this->onlyPopulatedBin($histogram));
	}

	public static function samples(): iterable {
		yield 'sub-millisecond' => [0.0004, 0];
		yield 'just under 1 ms' => [0.000_999, 0];
		yield 'exactly 1 ms is the start of the next bin' => [0.001, 1];
		yield 'just under 2 ms' => [0.001_999, 1];
		yield 'exactly 2 ms' => [0.002, 2];
		yield '7 ms' => [0.007, 3];
		yield '40 ms' => [0.040, 5];
		yield '210 ms' => [0.210, 7];
		yield 'exactly 1 s' => [1.0, 10];
		yield '3.1 s' => [3.1, 11];
		yield 'just under a minute' => [59.999, 14];
		yield 'exactly a minute overflows' => [60.0, 15];
		yield 'an hour overflows' => [3600.0, 15];
		yield 'a negative duration is clamped to the first bin' => [-0.5, 0];
	}

	#[DataProvider('edges')]
	public function testEveryEdgeIsLowerInclusive(int $index, int $edgeMs): void {
		$below = new DurationHistogram();
		$at    = new DurationHistogram();

		$below->record(($edgeMs - 0.001) / 1000);
		$at->record($edgeMs / 1000);

		$this->assertSame($index, $this->onlyPopulatedBin($below));
		$this->assertSame($index + 1, $this->onlyPopulatedBin($at));
	}

	public static function edges(): iterable {
		foreach (DurationHistogram::EDGES_MS as $index => $edge) {
			yield "{$edge} ms" => [$index, $edge];
		}
	}

	public function testTheSampleWeightIsWhatIsCountedSoSamplingScalesBackUp(): void {
		$histogram = new DurationHistogram();

		$histogram->record(0.040, 10);
		$histogram->record(0.040, 10);

		$this->assertSame(20, $histogram->toArray()[5]);
	}

	public function testOneSampleCountsOnceByDefault(): void {
		$histogram = new DurationHistogram();

		$histogram->record(0.040);

		$this->assertSame(1, $histogram->toArray()[5]);
	}

	public function testMergingAddsBinForBinAndNothingElse(): void {
		$left  = new DurationHistogram();
		$right = new DurationHistogram();
		$left->record(0.040, 3);
		$left->record(90.0);
		$right->record(0.040, 2);
		$right->record(0.210);

		$left->merge($right);

		$expected          = array_fill(0, 16, 0);
		$expected[5]       = 5;
		$expected[7]       = 1;
		$expected[15]      = 1;
		$this->assertSame($expected, $left->toArray());
	}

	public function testMergingLeavesTheOtherHistogramAlone(): void {
		$left  = new DurationHistogram();
		$right = new DurationHistogram();
		$right->record(0.040);

		$left->merge($right);

		$this->assertSame(1, array_sum($right->toArray()));
	}

	public function testTheTotalIsPreservedWhicheverWayRoundTheMergeGoes(): void {
		$left  = new DurationHistogram();
		$right = new DurationHistogram();
		$left->record(0.001, 7);
		$right->record(12.0, 5);
		$mirror = new DurationHistogram();
		$mirror->record(12.0, 5);
		$other = new DurationHistogram();
		$other->record(0.001, 7);

		$left->merge($right);
		$mirror->merge($other);

		$this->assertSame($left->toArray(), $mirror->toArray());
		$this->assertSame(12, array_sum($left->toArray()));
	}

	private function onlyPopulatedBin(DurationHistogram $histogram): int {
		$bins = $histogram->toArray();
		$this->assertSame(1, count(array_filter($bins)), 'expected exactly one populated bin');

		return (int) array_key_first(array_filter($bins));
	}
}
