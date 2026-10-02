<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Normalize;

use BugCatcher\PerfCollector\Normalize\DefaultRules;
use BugCatcher\PerfCollector\Normalize\NormalizationRule;
use PHPUnit\Framework\TestCase;

final class DefaultRulesTest extends TestCase {

	public function testItShipsTheThreeDocumentedPlaceholders(): void {
		$replacements = array_map(
			static fn(NormalizationRule $rule): string => $rule->replacement,
			DefaultRules::all(),
		);

		$this->assertSame(['/{uuid}', '/{hash}', '/{id}'], $replacements);
	}

	/**
	 * A uuid and an md5 are both made of digits, so the numeric rule has to come last or it eats
	 * them first. The order of this list is the behaviour.
	 */
	public function testTheNumericRuleComesLast(): void {
		$rules = DefaultRules::all();

		$this->assertSame('/{id}', end($rules)->replacement);
	}
}
