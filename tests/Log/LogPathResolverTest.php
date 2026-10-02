<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Log;

use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Tests\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../hook/collector.php';

final class LogPathResolverTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bcperf-logs-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->dir);
	}

	public function testAPlainPathResolvesToItself(): void {
		$this->touch('bcperf.jsonl');

		$this->assertSame([$this->dir . '/bcperf.jsonl'], $this->resolve('/bcperf.jsonl', '2026-03-09 14:20:00'));
	}

	public function testAPlainPathThatDoesNotExistYetResolvesToNothing(): void {
		$this->assertSame([], $this->resolve('/bcperf.jsonl', '2026-03-09 14:20:00'));
	}

	/**
	 * The aggregator runs every minute, including the minute after midnight, when the lines it
	 * still owes are in yesterday's file and the hook is already writing to today's.
	 */
	public function testADailyPatternPicksUpYesterdayAsWellAsToday(): void {
		$this->touch('bcperf-20260308.jsonl');
		$this->touch('bcperf-20260309.jsonl');

		$this->assertSame(
			[$this->dir . '/bcperf-20260308.jsonl', $this->dir . '/bcperf-20260309.jsonl'],
			$this->resolve('/bcperf-%Y%m%d.jsonl', '2026-03-09 00:00:30'),
		);
	}

	public function testOnlyTheFilesThatExistComeBack(): void {
		$this->touch('bcperf-20260309.jsonl');

		$this->assertSame(
			[$this->dir . '/bcperf-20260309.jsonl'],
			$this->resolve('/bcperf-%Y%m%d.jsonl', '2026-03-09 00:00:30'),
		);
	}

	public function testAnHourlyPatternPicksUpThePreviousHour(): void {
		$this->touch('13.jsonl');
		$this->touch('14.jsonl');

		$this->assertSame(
			[$this->dir . '/13.jsonl', $this->dir . '/14.jsonl'],
			$this->resolve('/%H.jsonl', '2026-03-09 14:00:30'),
		);
	}

	public function testTheSameFileIsNeverReturnedTwice(): void {
		$this->touch('bcperf-20260309.jsonl');

		$this->assertSame(
			[$this->dir . '/bcperf-20260309.jsonl'],
			$this->resolve('/bcperf-%Y%m%d.jsonl', '2026-03-09 14:20:00'),
		);
	}

	public function testFilesComeBackOldestFirstSoLinesAreProcessedInOrder(): void {
		$this->touch('bcperf-20260308.jsonl');
		$this->touch('bcperf-20260309.jsonl');

		$resolved = $this->resolve('/bcperf-%Y%m%d.jsonl', '2026-03-09 00:30:00');

		$this->assertStringEndsWith('20260308.jsonl', $resolved[0]);
		$this->assertStringEndsWith('20260309.jsonl', $resolved[1]);
	}

	#[DataProvider('patterns')]
	public function testExpansionAgreesWithTheHookToTheByte(string $pattern): void {
		$time = (float) strtotime('2026-03-09 14:20:00');

		$this->assertSame(
			bcperf_expand_path($pattern, $time),
			LogPathResolver::expand($pattern, $time),
			'the hook writes the file this resolver has to find',
		);
	}

	public static function patterns(): iterable {
		yield 'plain' => ['/var/log/bcperf.jsonl'];
		yield 'daily' => ['/var/log/bcperf-%Y%m%d.jsonl'];
		yield 'hourly' => ['/var/log/%Y/%m/%d/%H.jsonl'];
		yield 'escaped percent' => ['/var/log/100%%.jsonl'];
		yield 'unknown placeholder' => ['/var/log/%Q.jsonl'];
		yield 'two digit year is not a token' => ['/var/log/%y.jsonl'];
	}

	/** @return list<string> */
	private function resolve(string $pattern, string $now): array {
		return (new LogPathResolver(new FrozenClock($now)))->resolve($this->dir . $pattern);
	}

	private function touch(string $name): void {
		file_put_contents($this->dir . '/' . $name, "{}\n");
	}
}
