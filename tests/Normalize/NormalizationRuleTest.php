<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Normalize;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\Normalize\NormalizationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormalizationRuleTest extends TestCase {

	public function testItReplacesEveryMatch(): void {
		$rule = new NormalizationRule('~/\d+(?=[/.]|$)~', '/{id}');

		$this->assertSame('/user/{id}/orders/{id}', $rule->apply('/user/4711/orders/8'));
	}

	public function testItLeavesANonMatchingPathAlone(): void {
		$rule = new NormalizationRule('~/\d+(?=[/.]|$)~', '/{id}');

		$this->assertSame('/about/team', $rule->apply('/about/team'));
	}

	#[DataProvider('brokenPatterns')]
	public function testABrokenPatternIsRejectedWhenTheRuleIsBuiltNotWhenItIsUsed(string $pattern): void {
		$this->expectException(InvalidConfiguration::class);

		new NormalizationRule($pattern, '/{id}');
	}

	public static function brokenPatterns(): iterable {
		yield 'unterminated group' => ['~/(\d+~'];
		yield 'no delimiters' => ['\d+'];
		yield 'empty' => [''];
		yield 'unknown modifier' => ['~\d~Z'];
	}

	public function testTheMessageNamesThePatternSoTheOperatorCanFindIt(): void {
		$this->expectExceptionMessageMatches('~/\(\\\\d\+~');

		new NormalizationRule('~/(\d+~', '/{id}');
	}
}
