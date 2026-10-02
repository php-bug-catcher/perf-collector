<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Normalize;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;

/**
 * One PCRE pattern and what to put in place of it. The pattern carries its own delimiters and
 * modifiers, because the point of this class is that an installation can write any regex it
 * likes without the collector having an opinion about it.
 */
final readonly class NormalizationRule {

	public function __construct(
		public string $pattern,
		public string $replacement,
	) {
		// Compiled here rather than on the first path: a typo in a rules file should stop the
		// cron job with an explanation, not quietly stop normalising halfway through a run.
		$failure = self::compilationFailure($this->pattern);
		if ($failure !== null) {
			throw new InvalidConfiguration(sprintf('"%s" is not a usable regular expression: %s', $this->pattern, $failure));
		}
	}

	public function apply(string $path): string {
		return (string) preg_replace($this->pattern, $this->replacement, $path);
	}

	/** PCRE reports a compilation problem as a warning, which is the only place the detail is. */
	private static function compilationFailure(string $pattern): ?string {
		$warning = null;
		set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
			$warning = preg_replace('~^preg_match\(\): ~', '', $message);

			return true;
		});

		try {
			$compiled = preg_match($pattern, '') !== false;
		} finally {
			restore_error_handler();
		}

		return $compiled ? null : ($warning ?? 'the pattern was rejected by PCRE.');
	}
}
