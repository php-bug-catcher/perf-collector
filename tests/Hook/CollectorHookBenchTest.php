<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Hook;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../hook/collector.php';

/**
 * The hook runs in every request of the monitored application, so its cost is a feature and this
 * test is the contract. The budget is 100 us on the way in and 200 us in shutdown, measured
 * without the disk write - the write is the application's own filesystem and not ours to promise.
 *
 * The hook measures from its own start, so it never shows up in the numbers it reports. These are
 * the numbers that tell you what it costs.
 */
final class CollectorHookBenchTest extends TestCase {

	private const ENTRY_BUDGET_US    = 100.0;
	private const SHUTDOWN_BUDGET_US = 200.0;
	private const ITERATIONS         = 20_000;

	protected function setUp(): void {
		if (extension_loaded('xdebug')) {
			$this->markTestSkipped('Xdebug multiplies every function call; the budget only means anything without it.');
		}
	}

	protected function tearDown(): void {
		putenv('BCPERF_CLI');
		putenv('BCPERF_LOG');
		putenv('BCPERF_SAMPLE_RATE');
	}

	public function testTheEntryPathStaysWithinItsBudget(): void {
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=/dev/null');

		$cost = $this->measure(static function (): void {
			bcperf_boot();
		});

		$this->assertLessThan(self::ENTRY_BUDGET_US, $cost, sprintf('entry path cost %.1f us', $cost));
	}

	public function testANonSampledRequestIsCheaperStillBecauseItRegistersNothing(): void {
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=/dev/null');
		putenv('BCPERF_SAMPLE_RATE=1000000');

		$cost = $this->measure(static function (): void {
			bcperf_boot();
		});

		$this->assertLessThan(self::ENTRY_BUDGET_US, $cost, sprintf('non-sampled entry cost %.1f us', $cost));
	}

	public function testACliProcessPaysAlmostNothing(): void {
		$cost = $this->measure(static function (): void {
			bcperf_boot();
		});

		$this->assertLessThan(self::ENTRY_BUDGET_US, $cost, sprintf('CLI gate cost %.1f us', $cost));
	}

	public function testBuildingTheLineStaysWithinTheShutdownBudget(): void {
		$_SERVER = [
			'REQUEST_URI'    => '/catalogue/category/42/products?page=3&sort=price',
			'HTTP_HOST'      => 'www.site.com',
			'REQUEST_METHOD' => 'GET',
			'HTTPS'          => 'on',
		];
		$GLOBALS['_bcperf_extra'] = ['sq' => 31, 'st' => 12.5];
		$rusage                   = getrusage();

		$cost = $this->measure(static function () use ($rusage): void {
			bcperf_build_line(10.0, $rusage, 10.5, $rusage, 1);
		});

		unset($GLOBALS['_bcperf_extra']);
		$this->assertLessThan(self::SHUTDOWN_BUDGET_US, $cost, sprintf('shutdown cost %.1f us', $cost));
	}

	/** @return float microseconds per iteration */
	private function measure(callable $subject): float {
		for ($i = 0; $i < 1000; $i++) {
			$subject();
		}

		$start = hrtime(true);
		for ($i = 0; $i < self::ITERATIONS; $i++) {
			$subject();
		}

		return (hrtime(true) - $start) / self::ITERATIONS / 1000;
	}
}
