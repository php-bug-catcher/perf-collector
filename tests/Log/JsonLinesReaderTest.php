<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Log;

use BugCatcher\PerfCollector\Log\JsonLinesReader;
use PHPUnit\Framework\TestCase;

/**
 * The hook appends to the same file this reads, so a run always lands in the middle of a write
 * sooner or later. Stopping at the last newline is what keeps the half-written line in the file
 * for the next run instead of handing the decoder a torn one.
 */
final class JsonLinesReaderTest extends TestCase {

	private string $log;

	protected function setUp(): void {
		$this->log = sys_get_temp_dir() . '/bcperf-reader-' . bin2hex(random_bytes(6)) . '.jsonl';
	}

	protected function tearDown(): void {
		@unlink($this->log);
	}

	public function testItReadsEveryCompleteLine(): void {
		$this->given("one\ntwo\nthree\n");

		[$lines, $offset] = $this->read(0);

		$this->assertSame(['one', 'two', 'three'], $lines);
		$this->assertSame(14, $offset);
	}

	public function testItResumesFromTheOffsetInsteadOfRereadingTheFile(): void {
		$this->given("one\ntwo\nthree\n");

		[$lines, $offset] = $this->read(4);

		$this->assertSame(['two', 'three'], $lines);
		$this->assertSame(14, $offset);
	}

	public function testAPartlyWrittenTrailingLineIsLeftForTheNextRun(): void {
		$this->given("one\ntwo\n{\"t\":175");

		[$lines, $offset] = $this->read(0);

		$this->assertSame(['one', 'two'], $lines);
		$this->assertSame(8, $offset, 'the offset must stop before the torn line');
	}

	public function testTheTornLineIsReadWholeOnceItIsFinished(): void {
		$this->given("one\ntwo\n{\"t\":175");
		[, $offset] = $this->read(0);

		file_put_contents($this->log, "9400000}\n", FILE_APPEND);
		[$lines] = $this->read($offset);

		$this->assertSame(['{"t":1759400000}'], $lines);
	}

	public function testNothingNewMeansNoLinesAndNoMovement(): void {
		$this->given("one\n");

		[$lines, $offset] = $this->read(4);

		$this->assertSame([], $lines);
		$this->assertSame(4, $offset);
	}

	public function testAMissingLogIsNotAnError(): void {
		[$lines, $offset] = $this->read(0);

		$this->assertSame([], $lines);
		$this->assertSame(0, $offset);
	}

	public function testAnEmptyLogYieldsNothing(): void {
		$this->given('');

		[$lines, $offset] = $this->read(0);

		$this->assertSame([], $lines);
		$this->assertSame(0, $offset);
	}

	public function testBlankLinesAreSkippedButStillAccountedFor(): void {
		$this->given("one\n\n\ntwo\n");

		[$lines, $offset] = $this->read(0);

		$this->assertSame(['one', 'two'], $lines);
		$this->assertSame(10, $offset);
	}

	public function testItCrossesChunkBoundariesWithoutLosingOrSplittingALine(): void {
		$lines = array_map(static fn(int $i): string => json_encode(['i' => $i, 'pad' => str_repeat('x', 200)]), range(1, 500));
		$this->given(implode("\n", $lines) . "\n");

		[$read, $offset] = $this->read(0, chunkSize: 64);

		$this->assertSame($lines, $read);
		$this->assertSame(filesize($this->log), $offset);
	}

	public function testMemoryStaysBoundedByTheChunkNotByTheFile(): void {
		$batch = str_repeat(json_encode(['pad' => str_repeat('x', 3_000)]) . "\n", 500);
		$this->given('');
		for ($i = 0; $i < 10; $i++) {
			file_put_contents($this->log, $batch, FILE_APPEND);
		}
		unset($batch);

		$before = memory_get_usage(true);
		$count  = 0;
		foreach ((new JsonLinesReader(chunkSize: 8_192))->read($this->log, 0) as $ignored) {
			$count++;
		}

		$this->assertSame(5_000, $count);
		$this->assertLessThan(2_000_000, memory_get_usage(true) - $before, 'the reader buffered the file');
	}

	/**
	 * The hook caps a line at 4096 bytes, so this can only be corruption - but a cron job that
	 * runs out of memory on a corrupt log stops collecting anything at all.
	 */
	public function testALineLongerThanAnyTheHookCanWriteIsDiscardedRatherThanBuffered(): void {
		$this->given(str_repeat('x', 200_000) . "\nafter\n");

		[$lines, $offset] = $this->read(0, chunkSize: 8_192);

		$this->assertSame(['after'], $lines);
		$this->assertSame(filesize($this->log), $offset);
	}

	private function given(string $contents): void {
		file_put_contents($this->log, $contents);
	}

	/** @return array{list<string>, int} */
	private function read(int $offset, int $chunkSize = 262_144): array {
		$reader = new JsonLinesReader(chunkSize: $chunkSize);
		$lines  = [];
		$stream = $reader->read($this->log, $offset);
		foreach ($stream as $line) {
			$lines[] = $line;
		}

		return [$lines, $stream->getReturn()];
	}
}
