<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Log;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\Log\Cursor;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor is what makes a failed run retry instead of losing a window, and what stops a
 * rotated log from being read from the middle. Both halves of that are here.
 */
final class FileCursorStoreTest extends TestCase {

	private string $stateDir;
	private string $log;

	protected function setUp(): void {
		$this->stateDir = $this->tempPath('state');
		$this->log      = $this->tempPath('log') . '.jsonl';
		file_put_contents($this->log, "one\ntwo\n");
	}

	protected function tearDown(): void {
		foreach (glob($this->stateDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->stateDir);
		@unlink($this->log);
	}

	public function testThereIsNoCursorBeforeTheFirstRun(): void {
		$this->assertNull($this->store()->load($this->log));
	}

	public function testACursorSurvivesIntoTheNextRun(): void {
		$store = $this->store();

		$store->save(new Cursor($this->log, (int) fileinode($this->log), 4));

		$cursor = $store->load($this->log);
		$this->assertNotNull($cursor);
		$this->assertSame($this->log, $cursor->path);
		$this->assertSame(4, $cursor->offset);
	}

	public function testTheStateDirectoryIsCreatedOnDemand(): void {
		$store = $this->store();

		$store->save(new Cursor($this->log, (int) fileinode($this->log), 4));

		$this->assertDirectoryExists($this->stateDir);
	}

	public function testTwoLogsKeepTheirOwnCursors(): void {
		$other = $this->tempPath('log') . '.jsonl';
		file_put_contents($other, "x\n");
		$store = $this->store();

		$store->save(new Cursor($this->log, (int) fileinode($this->log), 4));
		$store->save(new Cursor($other, (int) fileinode($other), 2));

		$this->assertSame(4, $store->load($this->log)?->offset);
		$this->assertSame(2, $store->load($other)?->offset);
		@unlink($other);
	}

	public function testANewInodeMeansTheLogRotatedSoReadingStartsOver(): void {
		$store = $this->store();
		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));

		$this->rotate("three\n");

		$this->assertSame(0, $store->load($this->log)?->offset);
	}

	public function testTheCursorPicksUpTheNewInodeSoTheNextRunDoesNotResetAgain(): void {
		$store = $this->store();
		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));

		$this->rotate("three\n");
		$cursor = $store->load($this->log);

		$this->assertSame((int) fileinode($this->log), $cursor?->inode);
	}

	public function testALogShorterThanTheCursorWasTruncatedInPlaceSoReadingStartsOver(): void {
		$store = $this->store();
		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));

		file_put_contents($this->log, "a\n");

		$this->assertSame(0, $store->load($this->log)?->offset);
	}

	public function testACursorExactlyAtTheEndOfTheLogIsNotMistakenForARotation(): void {
		$store = $this->store();
		$size  = (int) filesize($this->log);

		$store->save(new Cursor($this->log, (int) fileinode($this->log), $size));

		$this->assertSame($size, $store->load($this->log)?->offset);
	}

	public function testAVanishedLogLeavesNothingToResumeFrom(): void {
		$store = $this->store();
		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));

		unlink($this->log);

		$this->assertNull($store->load($this->log));
	}

	#[DataProvider('unusableStateFiles')]
	public function testAnUnreadableStateFileIsTreatedAsNoCursorRatherThanKillingTheRun(string $contents): void {
		$store = $this->store();
		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));
		$state = glob($this->stateDir . '/*.json');
		file_put_contents($state[0], $contents);

		$this->assertNull($store->load($this->log));
	}

	public static function unusableStateFiles(): iterable {
		yield 'truncated mid-write' => ['{"inode":1234,"off'];
		yield 'empty' => [''];
		yield 'a list' => ['[1,2]'];
		yield 'wrong types' => ['{"inode":"a","offset":"b"}'];
		yield 'missing offset' => ['{"inode":12}'];
	}

	public function testSavingIsAtomicSoACrashCannotLeaveATornStateFile(): void {
		$store = $this->store();

		$store->save(new Cursor($this->log, (int) fileinode($this->log), 8));

		$this->assertCount(1, glob($this->stateDir . '/*') ?: [], 'a temporary file was left behind');
	}

	public function testAStateDirectoryThatCannotBeUsedIsReportedNotSwallowed(): void {
		$blocked = $this->tempPath('blocked');
		file_put_contents($blocked, 'I am a file, not a directory');

		$this->expectException(InvalidConfiguration::class);

		try {
			(new FileCursorStore($blocked . '/state'))->save(new Cursor($this->log, 1, 8));
		} finally {
			@unlink($blocked);
		}
	}

	private function store(): FileCursorStore {
		return new FileCursorStore($this->stateDir);
	}

	/** Replaces the log with a different file at the same path - what logrotate does. */
	private function rotate(string $contents): void {
		$replacement = $this->tempPath('rotated');
		file_put_contents($replacement, $contents);
		rename($replacement, $this->log);
	}

	private function tempPath(string $kind): string {
		return sys_get_temp_dir() . '/bcperf-' . $kind . '-' . bin2hex(random_bytes(6));
	}
}
