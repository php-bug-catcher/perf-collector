<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\FileLock;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that make this converge rather than double:
 *   - only a 2xx advances the cursor, so a failed ship is retried whole;
 *   - the cursor is what says a line has been shipped, so nothing is shipped twice.
 */
final class AggregatorTest extends TestCase {

	private string $dir;
	private string $log;
	private RecordingShipper $shipper;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bcperf-agg-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->log     = $this->dir . '/bcperf.jsonl';
		$this->shipper = new RecordingShipper('https://bugcatcher.example.com');
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		foreach (glob($this->dir . '/state/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->dir . '/state');
		@rmdir($this->dir);
	}

	public function testThereIsNothingToDoWithoutALog(): void {
		$result = $this->aggregator()->run();

		$this->assertSame(0, $result->samplesRead);
		$this->assertSame(0, $result->bucketsShipped);
		$this->assertFalse($result->shipped);
		$this->assertSame([], $this->shipper->bodies, 'an empty run must not wake the server up');
	}

	public function testAnEmptyLogShipsNothing(): void {
		file_put_contents($this->log, '');

		$this->assertFalse($this->aggregator()->run()->shipped);
	}

	public function testSamplesBecomeOneBatchForTheProject(): void {
		$this->givenLines([
			$this->line('/user/4711', 0.4),
			$this->line('/user/8', 0.2),
			$this->line('/news/', 1.5),
		]);

		$result = $this->aggregator()->run();

		$this->assertSame(3, $result->samplesRead);
		$this->assertSame(2, $result->bucketsShipped, '/user/{id} is one bucket');
		$this->assertTrue($result->shipped);
		$this->assertSame(201, $result->httpStatus);

		$body = $this->lastBody();
		$this->assertSame('myapp', $body['projectCode']);
		$this->assertSame(['/user/{id}', '/news/'], array_column($body['rows'], 'path'));
		$this->assertSame([2, 1], array_column($body['rows'], 'hits'));
	}

	public function testAShippedLogIsReclaimedSoTheMachineKeepsNothing(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);

		$this->aggregator()->run();

		$this->assertSame('', file_get_contents($this->log));
	}

	public function testNothingIsShippedTwiceAcrossRuns(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);
		$this->aggregator()->run();

		$this->givenLines([$this->line('/feed/', 0.6)]);
		$this->aggregator()->run();

		$this->assertCount(2, $this->shipper->bodies);
		$this->assertSame(1, $this->lastBody()['rows'][0]['hits']);
	}

	public function testAFailedShipLeavesTheCursorAndTheLogWhereTheyWere(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);
		$this->shipper->status = 503;

		$result = $this->aggregator()->run();

		$this->assertFalse($result->shipped);
		$this->assertSame(503, $result->httpStatus);
		$this->assertNotSame('', file_get_contents($this->log));
	}

	public function testTheRunAfterAFailureShipsTheSameWindowExactlyOnce(): void {
		$this->givenLines([$this->line('/feed/', 0.4), $this->line('/feed/', 0.6)]);
		$this->shipper->status = 503;
		$this->aggregator()->run();

		$this->shipper->status = 201;
		$this->aggregator()->run();

		$this->assertSame(2, $this->lastBody()['rows'][0]['hits'], 'a retry must converge, not double');
		$this->assertSame('', file_get_contents($this->log));
	}

	/** The hook keeps appending while we work; truncating what we did not read would lose it. */
	public function testALineWrittenWhileTheBatchWasInFlightIsNotThrownAway(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);
		$this->shipper->onShip = fn() => file_put_contents($this->log, $this->line('/late/', 9.9) . "\n", FILE_APPEND);

		$this->aggregator()->run();
		$this->shipper->onShip = null;
		$this->aggregator()->run();

		$this->assertSame(['/late/'], array_column($this->lastBody()['rows'], 'path'));
	}

	public function testABadLineIsCountedAndTheRestOfTheRunCarriesOn(): void {
		$this->givenLines([$this->line('/feed/', 0.4), '{"t":1759400040,"d":0.4', 'not json at all']);

		$result = $this->aggregator()->run();

		$this->assertSame(1, $result->samplesRead);
		$this->assertSame(2, $result->malformed);
		$this->assertTrue($result->shipped);
	}

	public function testADryRunLooksButDoesNotTouchAnything(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);

		$result = $this->aggregator(dryRun: true)->run();

		$this->assertSame(1, $result->samplesRead);
		$this->assertSame(1, $result->bucketsShipped);
		$this->assertFalse($result->shipped);
		$this->assertSame([], $this->shipper->bodies);
		$this->assertNotSame('', file_get_contents($this->log));
	}

	public function testARunThatMeetsAnotherOneBacksOffWithoutReadingAnything(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);
		$held = new FileLock($this->dir . '/run.lock');
		$held->acquire();

		$result = $this->aggregator()->run();

		$this->assertTrue($result->lockedOut);
		$this->assertSame([], $this->shipper->bodies);
		$this->assertNotSame('', file_get_contents($this->log));
		$held->release();
	}

	public function testTheLockIsHandedBackSoTheNextMinuteCanRun(): void {
		$this->givenLines([$this->line('/feed/', 0.4)]);

		$this->aggregator()->run();

		$this->assertTrue((new FileLock($this->dir . '/run.lock'))->acquire());
	}

	public function testBothSidesOfARotationAreReadInOneRun(): void {
		file_put_contents($this->dir . '/bcperf-20260308.jsonl', $this->line('/yesterday/', 0.4) . "\n");
		file_put_contents($this->dir . '/bcperf-20260309.jsonl', $this->line('/today/', 0.4) . "\n");

		$this->aggregator(pattern: '/bcperf-%Y%m%d.jsonl', now: '2026-03-09 00:00:30')->run();

		$this->assertSame(['/yesterday/', '/today/'], array_column($this->lastBody()['rows'], 'path'));
	}

	public function testABacklogIsDrainedOverSeveralRunsRatherThanInOneImpossibleRequest(): void {
		$lines = array_map(fn(int $i): string => $this->line("/page/{$i}", 0.1), range(1, 100));
		$this->givenLines($lines);
		$budget = strlen($lines[0]) * 10;

		$first  = $this->aggregator(maxBytes: $budget)->run();
		$second = $this->aggregator(maxBytes: $budget)->run();

		$this->assertSame(10, $first->samplesRead);
		$this->assertSame(10, $second->samplesRead);
		$this->assertSame(
			['/page/{id}'],
			array_unique(array_column($this->lastBody()['rows'], 'path')),
		);
	}

	private function aggregator(
		bool $dryRun = false,
		string $pattern = '/bcperf.jsonl',
		string $now = '2026-03-09 14:20:00',
		int $maxBytes = PHP_INT_MAX,
	): Aggregator {
		return new Aggregator(
			paths: new LogPathResolver(new FrozenClock($now)),
			cursors: new FileCursorStore($this->dir . '/state'),
			reader: new JsonLinesReader(),
			decoder: new SampleDecoder(),
			grouper: new SampleGrouper(PathNormalizer::withDefaults()),
			payloads: new BatchPayloadBuilder(),
			shipper: $this->shipper,
			lock: new FileLock($this->dir . '/run.lock'),
			logPattern: $this->dir . $pattern,
			projectCode: 'myapp',
			maxBytesPerRun: $maxBytes,
			dryRun: $dryRun,
		);
	}

	/** @param list<string> $lines */
	private function givenLines(array $lines): void {
		file_put_contents($this->log, implode("\n", $lines) . "\n", FILE_APPEND);
	}

	private function line(string $path, float $duration): string {
		return (string) json_encode([
			't' => 1759400040.0, 'd' => $duration, 'u' => 0.1, 's' => 0.01, 'm' => 1024,
			'c' => 200, 'sv' => 'https', 'h' => 'www.site.com', 'p' => $path, 'q' => '',
			'x' => 'GET', 'i' => 1, 'w' => 1, 'n' => 'web-01',
		], JSON_UNESCAPED_SLASHES);
	}

	private function lastBody(): array {
		$this->assertNotEmpty($this->shipper->bodies, 'nothing was shipped');

		return json_decode((string) end($this->shipper->bodies), true, 512, JSON_THROW_ON_ERROR);
	}
}
