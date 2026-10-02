<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Aggregate;

use BugCatcher\PerfCollector\Aggregate\BucketAccumulator;
use BugCatcher\PerfCollector\Aggregate\BucketKey;
use BugCatcher\PerfCollector\Sample\Sample;
use PHPUnit\Framework\TestCase;

final class BucketAccumulatorTest extends TestCase {

	public function testOneSampleBecomesOneHit(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(duration: 0.438));

		$row = $accumulator->toPayloadRow();
		$this->assertSame(1, $row['hits']);
		$this->assertSame(0.438, $row['sumDuration']);
	}

	/**
	 * A sample taken 1-in-10 stands for ten requests, which is the whole point of writing the
	 * rate into the line. Sums scale by it; a maximum is a measurement of one request and does
	 * not.
	 */
	public function testSumsScaleWithTheSampleRateAndMaximaDoNot(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(duration: 0.4, userCpu: 0.3, systemCpu: 0.01, memory: 1_000, weight: 10));

		$row = $accumulator->toPayloadRow();
		$this->assertSame(10, $row['hits']);
		$this->assertSame(4.0, $row['sumDuration']);
		$this->assertSame(3.0, $row['sumUser']);
		$this->assertSame(0.1, $row['sumSys']);
		$this->assertSame(10_000, $row['sumMem']);
		$this->assertSame(0.4, $row['maxDuration']);
		$this->assertSame(1_000, $row['maxMem']);
	}

	public function testTheMaximaAreTheLargestSeenNotTheLast(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(duration: 3.1, memory: 90_000));
		$accumulator->add($this->sample(duration: 0.2, memory: 1_000));

		$row = $accumulator->toPayloadRow();
		$this->assertSame(3.1, $row['maxDuration']);
		$this->assertSame(90_000, $row['maxMem']);
	}

	public function testErrorsAreCountedApartAndScaleWithTheRateToo(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(status: 200, weight: 10));
		$accumulator->add($this->sample(status: 404, weight: 10));
		$accumulator->add($this->sample(status: 503, weight: 10));
		$accumulator->add($this->sample(status: 500));

		$row = $accumulator->toPayloadRow();
		$this->assertSame(31, $row['hits']);
		$this->assertSame(10, $row['clientErrors']);
		$this->assertSame(11, $row['serverErrors']);
	}

	public function testEverySampleReachesTheHistogram(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(duration: 0.040, weight: 10));
		$accumulator->add($this->sample(duration: 90.0));

		$expected      = array_fill(0, 16, 0);
		$expected[5]   = 10;
		$expected[15]  = 1;
		$this->assertSame($expected, $accumulator->toPayloadRow()['histogram']);
		$this->assertSame($accumulator->toPayloadRow()['hits'], array_sum($expected), 'the histogram and the hit count must agree');
	}

	public function testExtraNumbersAreSummedAndScaledLikeAnyOtherSum(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample(weight: 10, extra: ['sq' => 3, 'st' => 1.5]));
		$accumulator->add($this->sample(extra: ['sq' => 2]));

		$this->assertSame(['sq' => 32, 'st' => 15.0], $accumulator->toPayloadRow()['extra']);
	}

	public function testAnExtraNobodyElseReportedStillCounts(): void {
		$accumulator = $this->accumulator();

		$accumulator->add($this->sample());
		$accumulator->add($this->sample(extra: ['cache_misses' => 4]));

		$this->assertSame(['cache_misses' => 4], $accumulator->toPayloadRow()['extra']);
	}

	public function testThePayloadRowCarriesTheBucketItBelongsTo(): void {
		$accumulator = $this->accumulator();
		$accumulator->add($this->sample());

		$row = $accumulator->toPayloadRow();

		$this->assertSame('2025-10-02T09:34:00Z', $row['bucketAt']);
		$this->assertSame('web-01', $row['serverName']);
		$this->assertSame('www.site.com', $row['host']);
		$this->assertSame('/feed/', $row['path']);
	}

	public function testThePayloadRowHasExactlyTheFieldsTheServerReads(): void {
		$accumulator = $this->accumulator();
		$accumulator->add($this->sample());

		$this->assertSame([
			'bucketAt', 'serverName', 'host', 'path', 'hits',
			'sumDuration', 'sumUser', 'sumSys', 'maxDuration',
			'sumMem', 'maxMem', 'clientErrors', 'serverErrors',
			'histogram', 'extra',
		], array_keys($accumulator->toPayloadRow()));
	}

	public function testAnUntouchedBucketIsAllZeroesRatherThanMissingFields(): void {
		$row = $this->accumulator()->toPayloadRow();

		$this->assertSame(0, $row['hits']);
		$this->assertSame(0.0, $row['sumDuration']);
		$this->assertSame(0.0, $row['maxDuration']);
		$this->assertSame([], $row['extra']);
		$this->assertSame(array_fill(0, 16, 0), $row['histogram']);
	}

	private function accumulator(): BucketAccumulator {
		return new BucketAccumulator(new BucketKey(1759397640, 'web-01', 'www.site.com', '/feed/'));
	}

	/** @param array<string,int|float> $extra */
	private function sample(
		float $duration = 0.4,
		float $userCpu = 0.0,
		float $systemCpu = 0.0,
		int $memory = 0,
		int $status = 200,
		int $weight = 1,
		array $extra = [],
	): Sample {
		return new Sample(
			timestamp: 1759397640.0,
			duration: $duration,
			userCpu: $userCpu,
			systemCpu: $systemCpu,
			memory: $memory,
			status: $status,
			scheme: 'https',
			host: 'www.site.com',
			path: '/feed/',
			query: '',
			method: 'GET',
			pid: 1,
			weight: $weight,
			serverName: 'web-01',
			extra: $extra,
		);
	}
}
