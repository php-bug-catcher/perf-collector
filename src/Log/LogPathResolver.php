<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Log;

use BugCatcher\PerfCollector\Clock;

/**
 * A rotation pattern points at more than one file for as long as it takes the aggregator to catch
 * up. The run after midnight still owes the lines in yesterday's file while the hook is already
 * writing to today's, so both are resolved, oldest first.
 *
 * `expand()` has to agree with `bcperf_expand_path()` in hook/collector.php to the byte - the hook
 * writes the file this has to find. The duplication is unavoidable: the hook may not autoload, so
 * it cannot call this. `LogPathResolverTest` pins the two against each other.
 */
final readonly class LogPathResolver {

	private const int HOUR = 3_600;
	private const int DAY  = 86_400;

	public function __construct(private Clock $clock) {
	}

	/** @return list<string> the log files that exist right now, oldest first */
	public function resolve(string $pattern): array {
		$now = $this->clock->now();

		$ordered = [];
		foreach ([$now - self::DAY, $now - self::HOUR, $now] as $instant) {
			$path = self::expand($pattern, $instant);
			// Re-inserting after unset moves the path to the end, so a pattern that resolves to
			// the same file for two instants keeps the position of the newest one.
			unset($ordered[$path]);
			$ordered[$path] = true;
		}

		return array_values(array_filter(array_keys($ordered), 'is_file'));
	}

	/** Expands the documented `%Y %m %d %H` rotation placeholders. `%%` is a literal percent. */
	public static function expand(string $pattern, float $time): string {
		if (!str_contains($pattern, '%')) {
			return $pattern;
		}
		$seconds = (int) $time;

		return strtr($pattern, [
			'%Y' => date('Y', $seconds),
			'%m' => date('m', $seconds),
			'%d' => date('d', $seconds),
			'%H' => date('H', $seconds),
			'%%' => '%',
		]);
	}
}
