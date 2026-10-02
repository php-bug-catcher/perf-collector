<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Sample;

/**
 * One log line into one Sample, or null.
 *
 * This is the only place in the aggregator that meets bytes it did not write itself, so it never
 * throws. A torn write, a log somebody edited by hand, a line from some other program sharing
 * the file - all of them come back as null and get counted. One bad line must not cost the
 * window it was in.
 *
 * Only the timestamp and the duration are genuinely required; a sample without them is not a
 * measurement. Everything else degrades to a default, because a request with no `REQUEST_METHOD`
 * is still a request that took 400 ms.
 */
final readonly class SampleDecoder {

	/** The hook keeps a line under 4096 bytes, so anything larger did not come from the hook. */
	private const int MAX_LINE_BYTES = 4_096;

	public function decode(string $line): ?Sample {
		if ($line === '' || strlen($line) > self::MAX_LINE_BYTES) {
			return null;
		}

		$row = json_decode($line, true);
		if (!is_array($row) || array_is_list($row)) {
			return null;
		}

		if (!is_numeric($row['t'] ?? null) || !is_numeric($row['d'] ?? null)) {
			return null;
		}

		return new Sample(
			timestamp:  (float) $row['t'],
			duration:   (float) $row['d'],
			userCpu:    self::float($row, 'u'),
			systemCpu:  self::float($row, 's'),
			memory:     self::int($row, 'm'),
			status:     self::int($row, 'c'),
			scheme:     self::string($row, 'sv'),
			host:       self::string($row, 'h'),
			path:       self::string($row, 'p'),
			query:      self::string($row, 'q'),
			method:     self::string($row, 'x'),
			pid:        self::int($row, 'i'),
			// A weight below one would erase perfectly good samples instead of scaling them up.
			weight:     max(1, self::int($row, 'w')),
			serverName: self::string($row, 'n'),
			extra:      self::extra($row),
		);
	}

	/** @param array<mixed> $row */
	private static function float(array $row, string $key): float {
		return is_numeric($row[$key] ?? null) ? (float) $row[$key] : 0.0;
	}

	/** @param array<mixed> $row */
	private static function int(array $row, string $key): int {
		return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
	}

	/** @param array<mixed> $row */
	private static function string(array $row, string $key): string {
		$value = $row[$key] ?? null;

		return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
	}

	/**
	 * @param  array<mixed>            $row
	 * @return array<string,int|float>
	 */
	private static function extra(array $row): array {
		$extra = $row['e'] ?? null;
		if (!is_array($extra)) {
			return [];
		}

		$numbers = [];
		foreach ($extra as $key => $value) {
			if (is_int($value) || is_float($value)) {
				$numbers[(string) $key] = $value;
			}
		}

		return $numbers;
	}
}
