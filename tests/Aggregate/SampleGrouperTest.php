<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Aggregate;

use BugCatcher\PerfCollector\Aggregate\BucketAccumulator;
use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\Sample;
use PHPUnit\Framework\TestCase;

final class SampleGrouperTest extends TestCase {

	public function testNothingInNothingOut(): void {
		$this->assertSame([], $this->group([]));
	}

	public function testSamplesOfOneRouteInOneMinuteBecomeOneBucket(): void {
		$buckets = $this->group([
			$this->sample(path: '/user/4711'),
			$this->sample(path: '/user/8'),
			$this->sample(path: '/user/2026'),
		]);

		$this->assertCount(1, $buckets);
		$bucket = reset($buckets);
		$this->assertSame('/user/{id}', $bucket->key->path);
		$this->assertSame(3, $bucket->toPayloadRow()['hits']);
	}

	public function testEachMinuteIsItsOwnBucket(): void {
		$buckets = $this->group([
			$this->sample(timestamp: 1759400040.0),
			$this->sample(timestamp: 1759400099.9),
			$this->sample(timestamp: 1759400100.0),
		]);

		$this->assertSame([1759400040, 1759400100], $this->minutes($buckets));
		$this->assertSame([2, 1], $this->hits($buckets));
	}

	public function testTheVhostAndTheMachineAreKeptApart(): void {
		$buckets = $this->group([
			$this->sample(host: 'www.site.com', serverName: 'web-01'),
			$this->sample(host: 'shop.site.com', serverName: 'web-01'),
			$this->sample(host: 'www.site.com', serverName: 'web-02'),
		]);

		$this->assertCount(3, $buckets, '"checkout is slow" and "checkout is slow on web-03" are different facts');
	}

	public function testTheQueryStringDoesNotSplitABucket(): void {
		$buckets = $this->group([
			$this->sample(query: 'page=1'),
			$this->sample(query: 'page=2'),
		]);

		$this->assertCount(1, $buckets);
	}

	/**
	 * The hook writes its line in shutdown, so the log is in finish order while the bucket key is
	 * start time: a request that began at 10:00:59 and ran for 90 seconds is read long after the
	 * minute it belongs to was shipped. It has to land in that minute anyway - the server adds
	 * counters, so the bucket simply grows.
	 */
	public function testASampleThatArrivesLateStillLandsInTheMinuteItStartedIn(): void {
		$buckets = $this->group([
			$this->sample(timestamp: 1759400159.0, duration: 0.2),
			$this->sample(timestamp: 1759400040.0, duration: 90.0),
		]);

		$this->assertSame([1759400100, 1759400040], $this->minutes($buckets));
	}

	public function testGroupingAcceptsAGeneratorSoTheLogIsNeverHeldInMemory(): void {
		$samples = (function (): iterable {
			yield $this->sample(path: '/a');
			yield $this->sample(path: '/b');
		})();

		$this->assertCount(2, $this->group($samples));
	}

	/** @param iterable<Sample> $samples @return array<string,BucketAccumulator> */
	private function group(iterable $samples): array {
		return (new SampleGrouper(PathNormalizer::withDefaults()))->group($samples);
	}

	/** @param array<string,BucketAccumulator> $buckets @return list<int> */
	private function minutes(array $buckets): array {
		return array_values(array_map(static fn(BucketAccumulator $b): int => $b->key->minute, $buckets));
	}

	/** @param array<string,BucketAccumulator> $buckets @return list<int> */
	private function hits(array $buckets): array {
		return array_values(array_map(static fn(BucketAccumulator $b): int => $b->toPayloadRow()['hits'], $buckets));
	}

	private function sample(
		float $timestamp = 1759400040.0,
		float $duration = 0.4,
		string $host = 'www.site.com',
		string $path = '/feed/',
		string $query = '',
		string $serverName = 'web-01',
	): Sample {
		return new Sample(
			timestamp: $timestamp,
			duration: $duration,
			userCpu: 0.0,
			systemCpu: 0.0,
			memory: 0,
			status: 200,
			scheme: 'https',
			host: $host,
			path: $path,
			query: $query,
			method: 'GET',
			pid: 1,
			weight: 1,
			serverName: $serverName,
		);
	}
}
