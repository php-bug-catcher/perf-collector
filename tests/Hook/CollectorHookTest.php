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
		unset($GLOBALS['_bcperf_extra']);
		foreach (['BCPERF_LOG', 'BCPERF_CLI', 'BCPERF_SAMPLE_RATE'] as $name) {
			putenv($name);
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		unset($GLOBALS['_bcperf_extra']);
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

		$this->assertSame('/srv/app/bin/console', $row['p']);
		$this->assertSame('', $row['q']);
		$this->assertSame('', $row['h']);
		$this->assertSame('', $row['x']);
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
