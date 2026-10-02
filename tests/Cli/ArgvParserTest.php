<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Cli;

use BugCatcher\PerfCollector\Cli\AggregateOptions;
use BugCatcher\PerfCollector\Cli\ArgvParser;
use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase {

	public function testTheThreeThingsNobodyCanGuessAreRequired(): void {
		$options = $this->parse(['--log=/var/log/bcperf.jsonl', '--endpoint=https://bc.example.com', '--project=myapp']);

		$this->assertSame('/var/log/bcperf.jsonl', $options->log);
		$this->assertSame('https://bc.example.com', $options->endpoint);
		$this->assertSame('myapp', $options->project);
	}

	#[DataProvider('requiredOptions')]
	public function testAMissingRequiredOptionSaysWhichOne(string $missing, array $given): void {
		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessageMatches('~' . preg_quote($missing, '~') . '~');

		$this->parse($given);
	}

	public static function requiredOptions(): iterable {
		yield '--log' => ['--log', ['--endpoint=https://bc.example.com', '--project=myapp']];
		yield '--endpoint' => ['--endpoint', ['--log=/var/log/a.jsonl', '--project=myapp']];
		yield '--project' => ['--project', ['--log=/var/log/a.jsonl', '--endpoint=https://bc.example.com']];
	}

	public function testAValueMayBeSeparatedByASpaceInsteadOfAnEqualsSign(): void {
		$options = $this->parse(['--log', '/var/log/bcperf.jsonl', '--endpoint', 'https://bc.example.com', '--project', 'myapp']);

		$this->assertSame('/var/log/bcperf.jsonl', $options->log);
		$this->assertSame('myapp', $options->project);
	}

	public function testAnOptionLeftWithoutItsValueIsRefused(): void {
		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessageMatches('~--project~');

		$this->parse(['--log=/a', '--endpoint=https://b', '--project']);
	}

	public function testWhatIsNotGivenFallsBackToSomethingSensible(): void {
		$options = $this->minimal();

		$this->assertNull($options->token);
		$this->assertNull($options->rules);
		$this->assertTrue($options->defaultRules);
		$this->assertFalse($options->dryRun);
		$this->assertFalse($options->help);
		$this->assertSame(16_777_216, $options->maxBytes);
		$this->assertNotSame('', $options->stateDir);
	}

	public function testTheLockLivesWithTheStateUnlessItIsAskedToLiveElsewhere(): void {
		$this->assertSame('/var/state/aggregate.lock', $this->parse([
			'--log=/a', '--endpoint=https://b', '--project=c', '--state-dir=/var/state',
		])->lockFile);

		$this->assertSame('/run/bcperf.lock', $this->parse([
			'--log=/a', '--endpoint=https://b', '--project=c', '--state-dir=/var/state', '--lock=/run/bcperf.lock',
		])->lockFile);
	}

	public function testTheFlagsAreFlags(): void {
		$options = $this->parse([
			'--log=/a', '--endpoint=https://b', '--project=c', '--dry-run', '--no-default-rules',
		]);

		$this->assertTrue($options->dryRun);
		$this->assertFalse($options->defaultRules);
	}

	#[DataProvider('helpFlags')]
	public function testAskingForHelpDoesNotRequireAnythingElse(string $flag): void {
		$this->assertTrue($this->parse([$flag])->help);
	}

	public static function helpFlags(): iterable {
		yield 'long' => ['--help'];
		yield 'short' => ['-h'];
	}

	public function testTheByteBudgetCanBeChanged(): void {
		$this->assertSame(1_048_576, $this->parse([
			'--log=/a', '--endpoint=https://b', '--project=c', '--max-bytes=1048576',
		])->maxBytes);
	}

	#[DataProvider('nonsenseBudgets')]
	public function testAByteBudgetThatIsNotAPositiveNumberIsRefused(string $value): void {
		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessageMatches('~--max-bytes~');

		$this->parse(['--log=/a', '--endpoint=https://b', '--project=c', '--max-bytes=' . $value]);
	}

	public static function nonsenseBudgets(): iterable {
		yield 'zero' => ['0'];
		yield 'negative' => ['-1'];
		yield 'words' => ['plenty'];
		yield 'empty' => [''];
	}

	public function testAnUnknownOptionIsRefusedWithTheListOfRealOnes(): void {
		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessageMatches('~--endpoint~');

		$this->parse(['--log=/a', '--endpoint=https://b', '--project=c', '--verbose']);
	}

	public function testAStrayArgumentIsRefusedRatherThanIgnored(): void {
		$this->expectException(InvalidConfiguration::class);

		$this->parse(['--log=/a', '--endpoint=https://b', '--project=c', 'oops']);
	}

	/** @param list<string> $arguments */
	private function parse(array $arguments): AggregateOptions {
		return (new ArgvParser())->parse(['bin/bc-perf-aggregate', ...$arguments]);
	}

	private function minimal(): AggregateOptions {
		return $this->parse(['--log=/a', '--endpoint=https://b', '--project=c']);
	}
}
