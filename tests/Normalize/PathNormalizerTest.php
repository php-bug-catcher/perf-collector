<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Normalize;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\Normalize\NormalizationRule;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Without normalisation aggregation shatters into millions of one-hit patterns and the whole
 * design falls over, so these cases are the design, not an implementation detail.
 */
final class PathNormalizerTest extends TestCase {

	#[DataProvider('paths')]
	public function testTheDefaultRulesCollapseHighCardinalitySegments(string $path, string $expected): void {
		$this->assertSame($expected, PathNormalizer::withDefaults()->normalize($path));
	}

	public static function paths(): iterable {
		yield 'numeric id' => ['/user/4711', '/user/{id}'];
		yield 'several ids' => ['/user/4711/orders/8', '/user/{id}/orders/{id}'];
		yield 'id before an extension' => ['/image/42.jpg', '/image/{id}.jpg'];
		yield 'trailing slash survives' => ['/user/42/', '/user/{id}/'];
		yield 'uuid' => ['/doc/550e8400-e29b-41d4-a716-446655440000', '/doc/{uuid}'];
		yield 'uppercase uuid' => ['/doc/550E8400-E29B-41D4-A716-446655440000', '/doc/{uuid}'];
		yield 'md5' => ['/cache/d41d8cd98f00b204e9800998ecf8427e', '/cache/{hash}'];
		yield 'sha1' => ['/blob/da39a3ee5e6b4b0d3255bfef95601890afd80709', '/blob/{hash}'];
		yield 'sha256' => ['/blob/' . str_repeat('ab', 32), '/blob/{hash}'];
		yield 'hash before an extension' => ['/cache/d41d8cd98f00b204e9800998ecf8427e.css', '/cache/{hash}.css'];
		yield 'static path is untouched' => ['/about/our-team', '/about/our-team'];
		yield 'short hex is a word, not a hash' => ['/tag/beef', '/tag/beef'];
		yield 'root' => ['/', '/'];
		yield 'empty' => ['', ''];
		yield 'a date path is still high cardinality' => ['/2026/03/09/hello', '/{id}/{id}/{id}/hello'];
	}

	public function testAUuidIsNotShreddedByTheNumericRule(): void {
		$this->assertSame(
			'/doc/{uuid}/revision/{id}',
			PathNormalizer::withDefaults()->normalize('/doc/11111111-2222-3333-4444-555555555555/revision/7'),
		);
	}

	/**
	 * The aggregator normalises once per sample, but a rules file that produced a different answer
	 * on a second pass would mean two bucket keys for one route.
	 */
	#[DataProvider('paths')]
	public function testNormalisingAnAlreadyNormalisedPathChangesNothing(string $path): void {
		$normalizer = PathNormalizer::withDefaults();
		$once       = $normalizer->normalize($path);

		$this->assertSame($once, $normalizer->normalize($once));
	}

	public function testRulesAreAppliedInTheOrderTheyAreGiven(): void {
		$normalizer = new PathNormalizer([
			new NormalizationRule('~/v\d+(?=/|$)~', '/{version}'),
			new NormalizationRule('~/\d+(?=/|$)~', '/{id}'),
		]);

		$this->assertSame('/api/{version}/user/{id}', $normalizer->normalize('/api/v2/user/9'));
	}

	public function testAnExtraRuleRunsBeforeTheDefaultsSoItCanClaimASegmentFirst(): void {
		$normalizer = PathNormalizer::withDefaults(new NormalizationRule('~/v\d+(?=/|$)~', '/{version}'));

		$this->assertSame('/api/{version}/user/{id}', $normalizer->normalize('/api/v2/user/9'));
	}

	public function testNoRulesAtAllIsTheIdentity(): void {
		$this->assertSame('/user/4711', (new PathNormalizer([]))->normalize('/user/4711'));
	}

	public function testARulesFileReplacesWhatTheDefaultsWouldHaveDone(): void {
		$file = $this->rulesFile([['pattern' => '~/\d+(?=/|$)~', 'replacement' => '/N']]);

		$normalizer = PathNormalizer::fromRulesFile($file, withDefaults: false);

		$this->assertSame('/user/N', $normalizer->normalize('/user/4711'));
		$this->assertSame('/doc/550e8400-e29b-41d4-a716-446655440000', $normalizer->normalize('/doc/550e8400-e29b-41d4-a716-446655440000'));
	}

	public function testARulesFileExtendsTheDefaultsByDefault(): void {
		$file = $this->rulesFile([['pattern' => '~/v\d+(?=/|$)~', 'replacement' => '/{version}']]);

		$normalizer = PathNormalizer::fromRulesFile($file);

		$this->assertSame('/api/{version}/user/{id}', $normalizer->normalize('/api/v2/user/9'));
	}

	#[DataProvider('brokenRulesFiles')]
	public function testABrokenRulesFileIsRefusedWithAnExplanation(string $contents, string $expectedMessage): void {
		$file = sys_get_temp_dir() . '/bcperf-rules-' . bin2hex(random_bytes(6)) . '.json';
		file_put_contents($file, $contents);

		try {
			$this->expectException(InvalidConfiguration::class);
			$this->expectExceptionMessageMatches($expectedMessage);
			PathNormalizer::fromRulesFile($file);
		} finally {
			@unlink($file);
		}
	}

	public static function brokenRulesFiles(): iterable {
		yield 'not json' => ['{', '~valid JSON~'];
		yield 'not a list' => ['{"pattern":"~a~","replacement":"b"}', '~list of rules~'];
		yield 'entry is not an object' => ['["~a~"]', '~rule 0~'];
		yield 'missing replacement' => ['[{"pattern":"~a~"}]', '~rule 0~'];
		yield 'missing pattern' => ['[{"replacement":"b"}]', '~rule 0~'];
		yield 'pattern is not a string' => ['[{"pattern":7,"replacement":"b"}]', '~rule 0~'];
		yield 'broken regex' => ['[{"pattern":"~(~","replacement":"b"}]', '~~'];
	}

	public function testAMissingRulesFileIsRefused(): void {
		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessageMatches('~cannot be read~');

		PathNormalizer::fromRulesFile('/no/such/rules.json');
	}

	/** @param list<array<string,string>> $rules */
	private function rulesFile(array $rules): string {
		$path = sys_get_temp_dir() . '/bcperf-rules-' . bin2hex(random_bytes(6)) . '.json';
		file_put_contents($path, json_encode($rules, JSON_THROW_ON_ERROR));
		register_shutdown_function(static function () use ($path): void {
			@unlink($path);
		});

		return $path;
	}
}
