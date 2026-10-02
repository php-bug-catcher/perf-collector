<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\FileLock;
use PHPUnit\Framework\TestCase;

/**
 * Cron overlaps. A run that takes longer than a minute must not meet the next one over the same
 * log, or both read the same lines and ship them twice.
 */
final class FileLockTest extends TestCase {

	private string $path;

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/bcperf-lock-' . bin2hex(random_bytes(6)) . '.lock';
	}

	protected function tearDown(): void {
		@unlink($this->path);
	}

	public function testAFreeLockIsTaken(): void {
		$this->assertTrue((new FileLock($this->path))->acquire());
	}

	public function testASecondRunIsTurnedAwayRatherThanMadeToWait(): void {
		$held = new FileLock($this->path);
		$held->acquire();

		$this->assertFalse((new FileLock($this->path))->acquire());
	}

	public function testTheLockIsFreeAgainAfterTheRunEnds(): void {
		$first = new FileLock($this->path);
		$first->acquire();
		$first->release();

		$this->assertTrue((new FileLock($this->path))->acquire());
	}

	public function testAskingTwiceForALockYouAlreadyHoldIsFine(): void {
		$lock = new FileLock($this->path);

		$this->assertTrue($lock->acquire());
		$this->assertTrue($lock->acquire());
	}

	public function testReleasingALockYouNeverTookIsFine(): void {
		(new FileLock($this->path))->release();

		$this->expectNotToPerformAssertions();
	}

	public function testTheLockGoesAwayWhenTheRunDoesEvenWithoutARelease(): void {
		$lock = new FileLock($this->path);
		$lock->acquire();

		unset($lock);

		$this->assertTrue((new FileLock($this->path))->acquire());
	}

	public function testALockFileThatCannotBeCreatedIsAConfigurationProblem(): void {
		$this->expectException(InvalidConfiguration::class);

		(new FileLock(sys_get_temp_dir() . '/bcperf-no-such-dir/run.lock'))->acquire();
	}
}
