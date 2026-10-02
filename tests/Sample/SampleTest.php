<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Sample;

use BugCatcher\PerfCollector\Sample\Sample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleTest extends TestCase {

	#[DataProvider('timestamps')]
	public function testTheMinuteIsTheOneTheSampleStartedIn(float $timestamp, int $expected): void {
		$this->assertSame($expected, self::sample(timestamp: $timestamp)->minute());
	}

	public static function timestamps(): iterable {
		yield 'on the minute' => [1759400040.0, 1759400040];
		yield 'inside the minute' => [1759400059.999, 1759400040];
		yield 'the next minute' => [1759400100.0, 1759400100];
	}

	#[DataProvider('statuses')]
	public function testAStatusIsClassifiedTheWayTheBucketCountsIt(int $status, bool $client, bool $server): void {
		$sample = self::sample(status: $status);

		$this->assertSame($client, $sample->isClientError());
		$this->assertSame($server, $sample->isServerError());
	}

	public static function statuses(): iterable {
		yield 'unknown' => [0, false, false];
		yield 'ok' => [200, false, false];
		yield 'redirect' => [302, false, false];
		yield 'not found' => [404, true, false];
		yield 'teapot' => [418, true, false];
		yield 'server error' => [500, false, true];
		yield 'gateway timeout' => [504, false, true];
		yield 'out of range' => [600, false, false];
	}

	private static function sample(float $timestamp = 1759400040.0, int $status = 200): Sample {
		return new Sample(
			timestamp: $timestamp,
			duration: 0.4,
			userCpu: 0.3,
			systemCpu: 0.01,
			memory: 1024,
			status: $status,
			scheme: 'https',
			host: 'www.site.com',
			path: '/feed/',
			query: '',
			method: 'GET',
			pid: 1,
			weight: 1,
			serverName: 'web-01',
		);
	}
}
