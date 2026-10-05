<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Hook;

use PHPUnit\Framework\TestCase;

/**
 * The unit test exercises the hook's functions; this one exercises the file as PHP actually loads
 * it - in a real process, with a real shutdown, both ways it can be loaded: through
 * `auto_prepend_file` and through a `require` in the application's entry point.
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
		$this->assertSame('/' . basename($script), $row['p']);
		$this->assertSame(503, $row['c']);
		$this->assertSame(gethostname(), $row['n']);
		$this->assertGreaterThan(0.02, $row['d']);
		$this->assertGreaterThan(0, $row['i']);
	}

	/**
	 * The unit test can arrange `$_SERVER['argv']`; only a real process proves PHP fills it in the
	 * first place, which is what the whole naming of a cron task hangs on.
	 */
	public function testTheCommandsArgumentNamesTheRunInARealProcess(): void {
		$script = $this->script('usleep(1000);');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log], 'Cron\\Money\\SyncAllPayments', '-test', '0');

		$this->assertSame('/' . basename($script) . '/Cron/Money/SyncAllPayments', $this->onlyLine()['p']);
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

	public function testAnIncludedHookRecordsTheRunWithoutAnyIniAccess(): void {
		$script = $this->script($this->inlineConfig(['cli' => true]) . 'usleep(20000);');

		$this->phpWithoutPrepend($script, [], 'Cron\\Money\\SyncAllPayments');

		$row = $this->onlyLine();
		$this->assertSame('/' . basename($script) . '/Cron/Money/SyncAllPayments', $row['p']);
		$this->assertSame(gethostname(), $row['n']);
		$this->assertGreaterThan(0.02, $row['d']);
	}

	/**
	 * The point of measuring from REQUEST_TIME_FLOAT: an application that requires the hook after
	 * some of its bootstrap has already run still reports the whole request, so the two
	 * installation modes do not disagree about what a request cost.
	 */
	public function testAnIncludedHookStillMeasuresWhatRanBeforeIt(): void {
		$script = $this->script('usleep(40000);' . $this->inlineConfig(['cli' => true]));

		$this->phpWithoutPrepend($script, []);

		$this->assertGreaterThan(0.04, $this->onlyLine()['d']);
	}

	public function testRequiringTheHookTwiceRecordsTheRunOnce(): void {
		$script = $this->script($this->inlineConfig(['cli' => true]) . 'require ' . var_export(self::HOOK, true) . ';');

		$this->phpWithoutPrepend($script, []);

		$this->onlyLine();
	}

	/**
	 * A machine-wide `auto_prepend_file` with no configuration of its own must not lock out the
	 * `require` that brings the configuration with it - which is what the BCPERF_ACTIVE guard used
	 * to do when it closed on a hook that had registered nothing.
	 */
	public function testAnUnconfiguredPrependDoesNotLockOutTheIncludedHook(): void {
		$script = $this->script($this->inlineConfig(['cli' => true]) . 'usleep(1000);');

		$this->php($script, ['BCPERF_CLI' => '1']);

		$this->onlyLine();
	}

	public function testAConfiguredPrependAndAnIncludeRecordTheRunOnce(): void {
		$script = $this->script($this->inlineConfig(['cli' => true]) . 'usleep(1000);');

		$this->php($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$this->onlyLine();
	}

	public function testTheEnvironmentWinsOverTheInlineConfiguration(): void {
		$ignored = sys_get_temp_dir() . '/bcperf-ignored-' . bin2hex(random_bytes(6)) . '.jsonl';
		$script  = $this->script($this->inlineConfig(['cli' => true], $ignored) . 'usleep(1000);');

		$this->phpWithoutPrepend($script, ['BCPERF_CLI' => '1', 'BCPERF_LOG' => $this->log]);

		$this->onlyLine();
		$this->assertFileDoesNotExist($ignored);
	}

	/** @param array<string,bool|int|string> $extra */
	private function inlineConfig(array $extra = [], ?string $log = null): string {
		$config = ['log' => $log ?? $this->log] + $extra;

		return '$GLOBALS["_bcperf_config"] = ' . var_export($config, true) . ';'
			. 'require ' . var_export(self::HOOK, true) . ';';
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
	private function php(string $script, array $env, string ...$arguments): string {
		return $this->execute($script, $env, true, ...$arguments);
	}

	/**
	 * The installation with no php.ini access: PHP is given no auto_prepend_file at all and the
	 * script requires the hook itself.
	 *
	 * @param array<string,string> $env
	 */
	private function phpWithoutPrepend(string $script, array $env, string ...$arguments): string {
		return $this->execute($script, $env, false, ...$arguments);
	}

	/** @param array<string,string> $env */
	private function execute(string $script, array $env, bool $prepend, string ...$arguments): string {
		$command = implode(' ', array_map(
			static fn(string $name, string $value): string => $name . '=' . escapeshellarg($value),
			array_keys($env),
			$env,
		)) . ' ' . escapeshellarg(PHP_BINARY)
			. ($prepend ? ' -d ' . escapeshellarg('auto_prepend_file=' . self::HOOK) : '')
			. ' ' . escapeshellarg($script)
			. ($arguments === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $arguments)))
			. ' 2>/dev/null';

		return (string) shell_exec($command);
	}
}
