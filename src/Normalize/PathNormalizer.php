<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Normalize;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use JsonException;

/**
 * Turns `/user/4711` into `/user/{id}`. Without this, aggregation shatters into millions of
 * one-hit patterns and there is nothing left to chart.
 *
 * Extension is by composition - hand it the rules you want, in the order you want them - never
 * by subclassing.
 */
final readonly class PathNormalizer {

	/** @param list<NormalizationRule> $rules */
	public function __construct(private array $rules) {
	}

	/** Extra rules run before the shipped ones, so they can claim a segment first. */
	public static function withDefaults(NormalizationRule ...$extra): self {
		return new self([...$extra, ...DefaultRules::all()]);
	}

	/**
	 * A JSON list of `{"pattern": "~…~", "replacement": "…"}`. By default the file adds to the
	 * shipped rules; `withDefaults: false` means the file is the whole list.
	 */
	public static function fromRulesFile(string $path, bool $withDefaults = true): self {
		$contents = @file_get_contents($path);
		if ($contents === false) {
			throw new InvalidConfiguration(sprintf('The rules file "%s" cannot be read.', $path));
		}

		try {
			$decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidConfiguration(sprintf('The rules file "%s" is not valid JSON: %s', $path, $e->getMessage()), 0, $e);
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			throw new InvalidConfiguration(sprintf('The rules file "%s" must hold a JSON list of rules.', $path));
		}

		$rules = [];
		foreach ($decoded as $index => $rule) {
			if (!is_array($rule) || !is_string($rule['pattern'] ?? null) || !is_string($rule['replacement'] ?? null)) {
				throw new InvalidConfiguration(sprintf(
					'In "%s", rule %d must be an object with a string "pattern" and a string "replacement".',
					$path,
					$index,
				));
			}
			$rules[] = new NormalizationRule($rule['pattern'], $rule['replacement']);
		}

		return $withDefaults ? self::withDefaults(...$rules) : new self($rules);
	}

	public function normalize(string $path): string {
		foreach ($this->rules as $rule) {
			$path = $rule->apply($path);
		}

		return $path;
	}
}
