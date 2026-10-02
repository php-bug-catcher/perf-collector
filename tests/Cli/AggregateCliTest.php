<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Cli;

use BugCatcher\PerfCollector\Cli\AggregateCli;
use PHPUnit\Framework\TestCase;

final class AggregateCliTest extends TestCase {

	private string $dir;
	private string $log;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bcperf-cli-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->log = $this->dir . '/bcperf.jsonl';
	}

	protected function tearDown(): void {
		foreach ([...glob($this->dir . '/*') ?: [], ...glob($this->dir . '/state/*') ?: []] as $file) {
			@unlink($file);
		}
		@rmdir($this->dir . '/state');
		@rmdir($this->dir);
	}

	public function testHelpExplainsItselfAndSucceeds(): void {
		[$code, $out] = $this->invoke(['--help']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('--endpoint', $out);
		$this->assertStringContainsString('--dry-run', $out);
	}

	public function testABadCommandLineSaysWhyOnStderrAndDoesNotPretendToSucceed(): void {
		[$code, $out, $err] = $this->invoke(['--endpoint=https://bc.example.com']);

		$this->assertSame(2, $code);
		$this->assertSame('', $out);
		$this->assertStringContainsString('--log', $err);
	}

	public function testAnUnreadableRulesFileIsAConfigurationErrorNotACrash(): void {
		[$code, , $err] = $this->invoke($this->arguments(['--rules=/no/such/rules.json']));

		$this->assertSame(2, $code);
		$this->assertStringContainsString('rules', $err);
	}

	public function testThereIsNothingToSayAboutAnEmptyLog(): void {
		[$code, $out] = $this->invoke($this->arguments(['--dry-run']));

		$this->assertSame(0, $code);
		$this->assertStringContainsString('0 samples read', $out);
	}

	public function testADryRunReportsWhatItWouldHaveShippedAndLeavesTheLogAlone(): void {
		$this->givenALine();

		[$code, $out] = $this->invoke($this->arguments(['--dry-run']));

		$this->assertSame(0, $code);
		$this->assertStringContainsString('1 samples read', $out);
		$this->assertStringContainsString('1 buckets not shipped', $out);
		$this->assertNotSame('', file_get_contents($this->log));
	}

	public function testAServerThatCannotBeReachedIsAFailureTheOperatorHearsAbout(): void {
		$this->givenALine();

		[$code, , $err] = $this->invoke($this->arguments(endpoint: 'http://127.0.0.1:1'));


		$this->assertSame(1, $code);
		$this->assertStringContainsString('not shipped', $err);
		$this->assertNotSame('', file_get_contents($this->log), 'a failed ship must leave the window alone');
	}

	public function testApplyingCustomRulesNeedsNoNetworkToBeVisible(): void {
		$this->givenALine('/v2/user/4711');
		$rules = $this->dir . '/rules.json';
		file_put_contents($rules, '[{"pattern":"~/v\\\\d+(?=/|$)~","replacement":"/{version}"}]');

		[$code, $out] = $this->invoke($this->arguments(['--dry-run', '--rules=' . $rules]));

		$this->assertSame(0, $code);
		$this->assertStringContainsString('1 buckets', $out);
	}

	/**
	 * @param  list<string> $extra
	 * @return list<string>
	 */
	private function arguments(array $extra = [], string $endpoint = 'https://bc.example.com'): array {
		return [
			'--log=' . $this->log,
			'--endpoint=' . $endpoint,
			'--project=myapp',
			'--state-dir=' . $this->dir . '/state',
			...$extra,
		];
	}

	private function givenALine(string $path = '/feed/'): void {
		file_put_contents($this->log, json_encode([
			't' => 1759400040.0, 'd' => 0.4, 'm' => 1024, 'c' => 200,
			'h' => 'www.site.com', 'p' => $path, 'n' => 'web-01', 'w' => 1,
		]) . "\n", FILE_APPEND);
	}

	/**
	 * @param  list<string>            $arguments
	 * @return array{int,string,string}
	 */
	private function invoke(array $arguments): array {
		$stdout = fopen('php://memory', 'w+b');
		$stderr = fopen('php://memory', 'w+b');

		$code = AggregateCli::main(['bin/bc-perf-aggregate', ...$arguments], $stdout, $stderr);

		rewind($stdout);
		rewind($stderr);

		return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
	}
}
