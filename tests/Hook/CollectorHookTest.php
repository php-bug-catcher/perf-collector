<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Hook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../hook/collector.php';

/**
 * The hook cannot be autoloaded - it runs before the application's autoloader - so the test seam
 * is the prefixed functions it declares. Including the file from a CLI test process is a no-op:
 * the CLI gate stops the top-level code before anything is registered.
 */
final class CollectorHookTest extends TestCase {

	private array $server;

	protected function setUp(): void {
		$this->server = $_SERVER;
		unset($GLOBALS['_bcperf_extra'], $GLOBALS['_bcperf_config']);
		foreach (['BCPERF_LOG', 'BCPERF_CLI', 'BCPERF_SAMPLE_RATE'] as $name) {
			putenv($name);
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		unset($GLOBALS['_bcperf_extra'], $GLOBALS['_bcperf_config']);
		foreach (['BCPERF_LOG', 'BCPERF_CLI', 'BCPERF_SAMPLE_RATE'] as $name) {
			putenv($name);
		}
	}

	public function testTheLineCarriesTheDocumentedKeysInOrder(): void {
		$this->givenRequest('/feed/?page=2');

		$row = $this->decode(bcperf_build_line(1759400000.123456, self::rusage(0.0, 0.0), 1759400000.561456, self::rusage(0.4, 0.012), 1));

		$this->assertSame(['t', 'd', 'u', 's', 'm', 'c', 'sv', 'h', 'p', 'q', 'x', 'i', 'w', 'n'], array_keys($row));
	}

	public function testTheLineCarriesTheRequestAndItsTimings(): void {
		$this->givenRequest('/feed/?page=2');

		$row = $this->decode(bcperf_build_line(1759400000.123456, self::rusage(0.0, 0.0), 1759400000.561456, self::rusage(0.4, 0.012), 1));

		$this->assertSame(1759400000.123, $row['t']);
		$this->assertSame(0.438, $row['d']);
		$this->assertSame(0.4, $row['u']);
		$this->assertSame(0.012, $row['s']);
		$this->assertSame('https', $row['sv']);
		$this->assertSame('www.site.com', $row['h']);
		$this->assertSame('/feed/', $row['p']);
		$this->assertSame('page=2', $row['q']);
		$this->assertSame('GET', $row['x']);
		$this->assertSame(getmypid(), $row['i']);
		$this->assertSame(1, $row['w']);
		$this->assertSame(gethostname(), $row['n']);
		$this->assertGreaterThan(0, $row['m']);
		$this->assertIsInt($row['c']);
	}

	public function testCpuIsTheDeltaBetweenTheTwoSnapshotsNotTheProcessTotal(): void {
		$this->givenRequest('/');

		$row = $this->decode(bcperf_build_line(10.0, self::rusage(2.5, 1.25), 10.5, self::rusage(2.75, 1.3), 1));

		$this->assertSame(0.25, $row['u']);
		$this->assertSame(0.05, $row['s']);
	}

	public function testAMissingRusageKeyIsTreatedAsZeroRatherThanAWarning(): void {
		$this->givenRequest('/');

		$row = $this->decode(bcperf_build_line(10.0, [], 10.5, [], 1));

		$this->assertSame(0.0, $row['u']);
		$this->assertSame(0.0, $row['s']);
	}

	public function testTheWeightIsTheSampleRateSoTheAggregatorCanScaleCountsBackUp(): void {
		$this->givenRequest('/');

		$row = $this->decode(bcperf_build_line(10.0, self::rusage(0.0, 0.0), 10.1, self::rusage(0.0, 0.0), 10));

		$this->assertSame(10, $row['w']);
	}

	public function testTheLineIsExactlyOneJsonLine(): void {
		$this->givenRequest("/feed/\n/injected?x=\n");

		$line = bcperf_build_line(10.0, self::rusage(0.0, 0.0), 10.1, self::rusage(0.0, 0.0), 1);

		$this->assertStringEndsWith("\n", $line);
		$this->assertSame(1, substr_count($line, "\n"));
	}

	#[DataProvider('schemes')]
	public function testTheSchemeIsDerivedFromTheUsualServerVariables(array $server, string $expected): void {
		$this->givenRequest('/');
		unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME']);
		$_SERVER = $server + $_SERVER;

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame($expected, $row['sv']);
	}

	public static function schemes(): iterable {
		yield 'explicit' => [['REQUEST_SCHEME' => 'https'], 'https'];
		yield 'https on' => [['HTTPS' => 'on'], 'https'];
		yield 'https off' => [['HTTPS' => 'off'], 'http'];
		yield 'plain' => [[], 'http'];
	}

	public function testTheQueryStringIsUsedWhenTheUriCarriesNone(): void {
		$this->givenRequest('/feed/');
		$_SERVER['QUERY_STRING'] = 'page=3';

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/feed/', $row['p']);
		$this->assertSame('page=3', $row['q']);
	}

	public function testACliProcessIsRecordedUnderItsScriptName(): void {
		$_SERVER = ['SCRIPT_NAME' => '/srv/app/bin/console', 'argv' => ['bin/console']];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/console', $row['p']);
		$this->assertSame('', $row['q']);
		$this->assertSame('', $row['h']);
		$this->assertSame('', $row['x']);
	}

	/**
	 * The whole point of naming a CLI run after its command: one entry point, a hundred jobs.
	 *
	 * `execute.php` is what a cron scheduler runs for every task there is, so a path that stops at
	 * the script tells you the machine ran something - not which task, not which one got slower.
	 */
	public function testACommandIsRecordedUnderTheJobItWasAskedToRun(): void {
		$_SERVER = ['argv' => ['C:/inetpub/wwwroot/cron/execute.php', 'Cron\\Money\\SyncAllPayments']];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/execute.php/Cron/Money/SyncAllPayments', $row['p']);
	}

	/**
	 * How a task was asked to run is not which task it was. Taking every argument would put a
	 * path per invocation into a table whose whole retention story assumes a path per route.
	 */
	public function testOptionsAndTheirValuesAreLeftOutOfThePath(): void {
		$_SERVER = ['argv' => ['execute.php', 'Cron\\Iptv\\Deactivate', '-isps', '1,6', '-delete-iptv', '1']];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/execute.php/Cron/Iptv/Deactivate', $row['p']);
	}

	/** `php -f script.php -- job`: PHP usually eats the separator, and we must not read it as the job. */
	public function testTheArgumentSeparatorIsNotMistakenForTheJob(): void {
		$_SERVER = ['argv' => ['execute.php', '--', 'Cron\\Rds\\Hosting\\Deactivate']];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/execute.php/Cron/Rds/Hosting/Deactivate', $row['p']);
	}

	/**
	 * The same script is written `C:\app\execute.php` in one scheduled task and `c:/app/execute.php`
	 * in the next. As paths those are two strings for one script, so neither the directory nor the
	 * slashes survive - otherwise one task's numbers arrive split across two rows.
	 */
	public function testTheInvocationPathDoesNotChangeWhatTheJobIsCalled(): void {
		$paths = [];

		foreach (['C:\\inetpub\\wwwroot\\cron\\execute.php', 'c:/inetpub/wwwroot/cron/execute.php', 'execute.php'] as $script) {
			$_SERVER = ['argv' => [$script, 'Cron\\Rds\\UpdateFlagsData']];
			$paths[] = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1))['p'];
		}

		$this->assertSame(['/execute.php/Cron/Rds/UpdateFlagsData'], array_values(array_unique($paths)));
	}

	public function testACommandWithNoArgumentsIsStillNamedAfterItsScript(): void {
		$_SERVER = ['argv' => ['C:/inetpub/wwwroot/cron/cron_tasks.php']];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/cron_tasks.php', $row['p']);
	}

	/** A CLI worker that says what it stands in for is believed over its own command line. */
	public function testAnExplicitRequestUriStillWins(): void {
		$_SERVER = ['argv' => ['worker.php', 'consume'], 'REQUEST_URI' => '/queue/email'];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame('/queue/email', $row['p']);
	}

	public function testNumericExtraGlobalsAreMergedUnderTheirOwnKey(): void {
		$this->givenRequest('/');
		$GLOBALS['_bcperf_extra'] = ['sq' => 12, 'st' => 4.5];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame(['sq' => 12, 'st' => 4.5], $row['e']);
	}

	public function testNonNumericExtraValuesAreDroppedRatherThanShipped(): void {
		$this->givenRequest('/');
		$GLOBALS['_bcperf_extra'] = ['sq' => 12, 'label' => 'slow', 'nested' => ['a'], 'flag' => true];

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertSame(['sq' => 12], $row['e']);
	}

	public function testAnExtraGlobalThatIsNotAnArrayIsIgnored(): void {
		$this->givenRequest('/');
		$GLOBALS['_bcperf_extra'] = 'nonsense';

		$row = $this->decode(bcperf_build_line(10.0, [], 10.1, [], 1));

		$this->assertArrayNotHasKey('e', $row);
	}

	public function testAHugeRequestStillYieldsAParsableLineUnderTheAtomicWriteLimit(): void {
		$this->givenRequest('/' . str_repeat('segment/', 2000) . '?' . str_repeat('k=v&', 2000));
		$GLOBALS['_bcperf_extra'] = array_combine(
			array_map(static fn(int $i): string => "metric_{$i}", range(1, 500)),
			range(1, 500),
		);

		$line = bcperf_build_line(10.0, [], 10.1, [], 1);

		$this->assertLessThanOrEqual(4096, strlen($line));
		$row = $this->decode($line);
		$this->assertStringStartsWith('/segment/', $row['p']);
	}

	public function testInvalidUtf8InTheUriDoesNotKillTheLine(): void {
		$this->givenRequest("/caf\xE9/");

		$line = bcperf_build_line(10.0, [], 10.1, [], 1);

		$this->assertNotNull($line);
		$this->assertIsArray(json_decode(rtrim($line, "\n"), true));
	}

	public function testTheHookIsDisabledOnCliUnlessAskedFor(): void {
		putenv('BCPERF_LOG=/tmp/bcperf.jsonl');

		$this->assertFalse(bcperf_enabled());

		putenv('BCPERF_CLI=1');

		$this->assertTrue(bcperf_enabled());
	}

	public function testTheHookIsDisabledWithoutALogPath(): void {
		putenv('BCPERF_CLI=1');

		$this->assertFalse(bcperf_enabled());
	}

	public function testBootReturnsNothingToRegisterWhenDisabled(): void {
		$this->assertNull(bcperf_boot());
	}

	public function testBootReturnsTheShutdownHandlerWhenEnabled(): void {
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $this->tempFile());

		$this->assertInstanceOf(\Closure::class, bcperf_boot());
	}

	public function testBootDropsNonSampledRequestsBeforeRegisteringAnything(): void {
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $this->tempFile());
		putenv('BCPERF_SAMPLE_RATE=1000000');

		$handlers = array_filter(array_map(static fn(): ?\Closure => bcperf_boot(), range(1, 20)));

		$this->assertLessThan(20, count($handlers));
	}

	#[DataProvider('nonsenseSampleRates')]
	public function testANonsenseSampleRateFallsBackToRecordingEverything(string $rate): void {
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $this->tempFile());
		putenv('BCPERF_SAMPLE_RATE=' . $rate);

		$this->assertInstanceOf(\Closure::class, bcperf_boot());
	}

	public static function nonsenseSampleRates(): iterable {
		yield 'zero' => ['0'];
		yield 'negative' => ['-5'];
		yield 'not a number' => ['often'];
	}

	/**
	 * The channel for an installation with no php.ini and no process environment, where the entry
	 * point requires the hook itself and hands it the configuration in a global.
	 */
	public function testTheInlineConfigurationIsEnoughToSwitchTheHookOn(): void {
		$GLOBALS['_bcperf_config'] = ['log' => $this->tempFile(), 'cli' => '1'];

		$this->assertTrue(bcperf_enabled());
		$this->assertInstanceOf(\Closure::class, bcperf_boot());
	}

	#[DataProvider('inlineValues')]
	public function testTheInlineConfigurationTakesWhatOneActuallyWritesInAPhpArray(mixed $value, string $expected): void {
		$GLOBALS['_bcperf_config'] = ['sample_rate' => $value];

		$this->assertSame($expected, bcperf_setting('SAMPLE_RATE'));
	}

	public static function inlineValues(): iterable {
		yield 'string' => ['10', '10'];
		yield 'int' => [10, '10'];
		yield 'float' => [1.5, '1.5'];
		yield 'true' => [true, '1'];
		yield 'false' => [false, ''];
		yield 'null is ignored' => [null, ''];
		yield 'array is ignored' => [['10'], ''];
		yield 'object is ignored' => [new \stdClass(), ''];
		yield 'empty string falls through to the default' => ['', ''];
	}

	/** A hook left inert by a mistyped key is the silent failure this mode has to avoid. */
	public function testTheInlineConfigurationAlsoAnswersForAnUpperCaseKey(): void {
		$GLOBALS['_bcperf_config'] = ['SAMPLE_RATE' => '10'];

		$this->assertSame('10', bcperf_setting('SAMPLE_RATE'));
	}

	public function testAnInlineConfigurationThatIsNotAnArrayIsIgnoredRatherThanFatal(): void {
		$GLOBALS['_bcperf_config'] = 'log=/var/log/bcperf.jsonl';

		$this->assertSame('fallback', bcperf_setting('LOG', 'fallback'));
	}

	/**
	 * Inline is last of the three sources, so an operator who does have env or php.ini can override
	 * a value the application ships without editing the application.
	 */
	public function testTheEnvironmentWinsOverTheInlineConfiguration(): void {
		$GLOBALS['_bcperf_config'] = ['log' => '/inline/bcperf.jsonl'];
		putenv('BCPERF_LOG=/env/bcperf.jsonl');

		$this->assertSame('/env/bcperf.jsonl', bcperf_setting('LOG'));
	}

	/**
	 * The duration is measured from the start of the request rather than from the moment the hook
	 * ran, which is what lets a `require` in index.php report the same number an auto_prepend_file
	 * would - wherever in the bootstrap that require happens to sit.
	 */
	public function testTheMeasurementStartsAtTheStartOfTheRequestNotAtTheHook(): void {
		$this->givenRequest('/');
		$_SERVER['REQUEST_TIME_FLOAT'] = 1759400000.123456;
		$log                           = $this->tempFile();
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $log);

		(bcperf_boot())();

		$this->assertSame(1759400000.123, $this->decode((string) file_get_contents($log))['t']);
	}

	/** Some SAPIs, and a variables_order without "S", leave $_SERVER unpopulated. */
	public function testWithoutRequestTimeFloatTheHookFallsBackToItsOwnClock(): void {
		$this->givenRequest('/');
		$log = $this->tempFile();
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $log);

		(bcperf_boot())();

		$this->assertArrayNotHasKey('REQUEST_TIME_FLOAT', $_SERVER);
		$this->assertEqualsWithDelta(microtime(true), $this->decode((string) file_get_contents($log))['t'], 5.0);
	}

	public function testTheShutdownHandlerAppendsOneLinePerRequest(): void {
		$this->givenRequest('/');
		$log = $this->tempFile();
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . $log);

		(bcperf_boot())();
		(bcperf_boot())();

		$this->assertSame(2, substr_count((string) file_get_contents($log), "\n"));
	}

	public function testTheShutdownHandlerSwallowsAnUnwritableLogRatherThanBreakTheApplication(): void {
		$this->givenRequest('/');
		putenv('BCPERF_CLI=1');
		putenv('BCPERF_LOG=' . sys_get_temp_dir() . '/bcperf-no-such-dir/' . bin2hex(random_bytes(4)) . '.jsonl');

		(bcperf_boot())();

		$this->expectNotToPerformAssertions();
	}

	public function testWriteAppendsInsteadOfTruncating(): void {
		$log = $this->tempFile();

		bcperf_write($log, "a\n", 10.0);
		bcperf_write($log, "b\n", 10.0);

		$this->assertSame("a\nb\n", file_get_contents($log));
	}

	#[DataProvider('logPatterns')]
	public function testTheLogPathExpandsRotationPlaceholders(string $pattern, string $expected): void {
		$time = mktime(14, 30, 0, 3, 9, 2026);

		$this->assertSame($expected, bcperf_expand_path($pattern, (float) $time));
	}

	public static function logPatterns(): iterable {
		yield 'plain path' => ['/var/log/bcperf.jsonl', '/var/log/bcperf.jsonl'];
		yield 'daily' => ['/var/log/bcperf-%Y%m%d.jsonl', '/var/log/bcperf-20260309.jsonl'];
		yield 'hourly' => ['/var/log/%Y/%m/%d/%H.jsonl', '/var/log/2026/03/09/14.jsonl'];
		yield 'escaped percent' => ['/var/log/100%%.jsonl', '/var/log/100%.jsonl'];
		yield 'unknown placeholder is left alone' => ['/var/log/%Q.jsonl', '/var/log/%Q.jsonl'];
	}

	/**
	 * Windows has no getrusage() at all, and the hook has to keep working there - everything
	 * except the CPU split is measurable on any platform.
	 */
	public function testAPlatformWithoutGetrusageStillProducesALine(): void {
		$this->givenRequest('/feed/?page=2');

		$row = $this->decode(bcperf_build_line(1759400000.123456, null, 1759400000.561456, null, 1));

		$this->assertSame(0.438, $row['d']);
		$this->assertSame('/feed/', $row['p']);
		$this->assertSame('www.site.com', $row['h']);
	}

	/**
	 * Absent and not zero. The server subtracts CPU from wallclock to get the waiting band, so
	 * zeroes would say every request on that machine waited for its whole duration.
	 */
	public function testWithoutGetrusageTheCpuKeysAreLeftOutRatherThanSentAsZero(): void {
		$this->givenRequest('/feed/');

		$row = $this->decode(bcperf_build_line(1759400000.123456, null, 1759400000.561456, null, 1));

		$this->assertArrayNotHasKey('u', $row);
		$this->assertArrayNotHasKey('s', $row);
		$this->assertSame(['t', 'd', 'm', 'c', 'sv', 'h', 'p', 'q', 'x', 'i', 'w', 'n'], array_keys($row));
	}

	/** One measured end and one unmeasured end is not a delta anybody can take. */
	public function testHalfAMeasurementIsNoMeasurement(): void {
		$this->givenRequest('/feed/');

		$opening = $this->decode(bcperf_build_line(1759400000.1, self::rusage(0.1, 0.0), 1759400000.5, null, 1));
		$closing = $this->decode(bcperf_build_line(1759400000.1, null, 1759400000.5, self::rusage(0.1, 0.0), 1));

		$this->assertArrayNotHasKey('u', $opening);
		$this->assertArrayNotHasKey('u', $closing);
	}

	/**
	 * The guard is the whole point of the helper: this used to be a bare getrusage() at the top
	 * of every request, outside the shutdown handler's try/catch.
	 */
	public function testTheRusageHelperAnswersForThisPlatformWithoutThrowing(): void {
		$rusage = bcperf_rusage();

		if (function_exists('getrusage')) {
			$this->assertIsArray($rusage);
			$this->assertArrayHasKey('ru_utime.tv_sec', $rusage);
		} else {
			$this->assertNull($rusage);
		}
	}

	private function givenRequest(string $uri): void {
		$_SERVER = [
			'REQUEST_URI'    => $uri,
			'HTTP_HOST'      => 'www.site.com',
			'REQUEST_METHOD' => 'GET',
			'HTTPS'          => 'on',
		];
	}

	private function decode(?string $line): array {
		$this->assertNotNull($line, 'the hook produced no line');

		return json_decode(rtrim($line, "\n"), true, 512, JSON_THROW_ON_ERROR);
	}

	private function tempFile(): string {
		$path = sys_get_temp_dir() . '/bcperf-test-' . bin2hex(random_bytes(6)) . '.jsonl';
		register_shutdown_function(static function () use ($path): void {
			@unlink($path);
		});

		return $path;
	}

	private static function rusage(float $user, float $system): array {
		return [
			'ru_utime.tv_sec'  => (int) $user,
			'ru_utime.tv_usec' => (int) round(fmod($user, 1.0) * 1_000_000),
			'ru_stime.tv_sec'  => (int) $system,
			'ru_stime.tv_usec' => (int) round(fmod($system, 1.0) * 1_000_000),
		];
	}
}
