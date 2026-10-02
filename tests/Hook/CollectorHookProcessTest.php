<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Hook;

use PHPUnit\Framework\TestCase;

/**
 * The unit test exercises the hook's functions; this one exercises the file as PHP actually loads
 * it - through `auto_prepend_file`, in a real process, with a real shutdown.
 */
final class CollectorHookProcessTest extends TestCase {

	private const HOOK = __DIR__ . '/../../hook/collector.php';

	private string $log;
	private array $scripts = [];

	protected function setUp(): void {
		$this->log = sys_get_temp_dir() . '/bcperf-proc-' . bin2hex(random_bytes(6)) . '.jsonl';
	}

	protected function tearDown(): void {
		@unlink($this->log);
		foreach ($this->scripts as $script) {
			@unlink($script);
		}
	}

	public function testAPrependedRequestLeavesExactlyOneLineBehind(): void {
		$script = $this->script('http_response_code(503); usleep(20000);');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$row = $this->onlyLine();
		$this->assertSame($script, $row['p']);
		$this->assertSame(503, $row['c']);
		$this->assertSame(gethostname(), $row['n']);
		$this->assertGreaterThan(0.02, $row['d']);
		$this->assertGreaterThan(0, $row['i']);
	}

	public function testTheHookWritesNothingToTheApplicationsOutput(): void {
		$script = $this->script('echo "payload";');

		$output = $this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$this->assertSame('payload', $output);
	}

	public function testAnUncaughtThrowableStillLeavesTheMeasurementBehind(): void {
		$script = $this->script('throw new \RuntimeException("boom");');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$this->assertSame(1, substr_count((string) file_get_contents($this->log), "\n"));
	}

	public function testCliProcessesAreIgnoredByDefaultSoCronDoesNotFloodTheLog(): void {
		$script = $this->script('usleep(1000);');

		$this->php($script, ['BCPERF_LOG' => $this->log]);

		$this->assertFileDoesNotExist($this->log);
	}

	public function testNothingIsWrittenWithoutALogPath(): void {
		$script = $this->script('usleep(1000);');

		$this->php($script, ['BCPERF_CLI' => '1']);

		$this->assertFileDoesNotExist($this->log);
	}

	public function testTheExtraGlobalSetByTheApplicationReachesTheLine(): void {
		$script = $this->script('$GLOBALS["_bcperf_extra"] = ["sq" => 7, "st" => 1.5];');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$this->assertSame(['sq' => 7, 'st' => 1.5], $this->onlyLine()['e']);
	}

	public function testTheLogPathRotatesOnItsOwnPattern(): void {
		$pattern = sys_get_temp_dir() . '/bcperf-proc-%Y%m%d-' . bin2hex(random_bytes(4)) . '.jsonl';
		$script  = $this->script('usleep(1000);');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $pattern]);

		$expanded = str_replace(['%Y', '%m', '%d'], [date('Y'), date('m'), date('d')], $pattern);
		$this->assertFileExists($expanded);
		@unlink($expanded);
	}

	private function onlyLine(): array {
		$this->assertFileExists($this->log);
		$lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertCount(1, $lines);

		return json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
	}

	private function script(string $body): string {
		$path = sys_get_temp_dir() . '/bcperf-script-' . bin2hex(random_bytes(6)) . '.php';
		file_put_contents($path, "<?php\n" . $body . "\n");
		$this->scripts[] = $path;

		return $path;
	}

	/** @param array<string,string> $env */
	private function php(string $script, array $env): string {
		$command = implode(' ', array_map(
			static fn(string $name, string $value): string => $name . '=' . escapeshellarg($value),
			array_keys($env),
			$env,
		)) . ' ' . escapeshellarg(PHP_BINARY)
			. ' -d ' . escapeshellarg('auto_prepend_file=' . self::HOOK)
			. ' ' . escapeshellarg($script) . ' 2>/dev/null';

		return (string) shell_exec($command);
	}
}
