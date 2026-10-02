<?php

/**
 * Bug Catcher performance collector - the auto_prepend_file hook.
 *
 * This file is loaded by PHP before the application's autoloader, so it may not require anything
 * and lives outside PSR-4 on purpose. It runs in every single request, which is why all it does
 * is measure and append one line: normalisation, histograms, grouping and HTTP all belong to
 * bin/bc-perf-aggregate, which runs from cron and not from a request.
 *
 * Budget: 100 us on the way in, 200 us in shutdown, excluding the write itself. The functions are
 * declared separately from the top-level code both to keep that budget honest - they are the unit
 * the benchmark measures - and because a monitoring hook nobody can test is a liability.
 *
 * Nothing in here may surface in the application: the shutdown handler is wrapped in
 * `try/catch (\Throwable)` with an empty body, because a monitoring hook that can take down the
 * application it monitors is worse than no monitoring.
 */

if (!function_exists('bcperf_setting')) {
	/**
	 * Configuration comes from the environment or from php.ini, because at this point there is no
	 * container, no .env parser and no autoloader to read anything with. `get_cfg_var()` rather
	 * than `ini_get()`: bcperf.* are not directives PHP knows about, and ini_get() only answers
	 * for registered ones.
	 */
	function bcperf_setting(string $name, string $default = ''): string {
		$value = getenv('BCPERF_' . $name);
		if ($value === false || $value === '') {
			$value = get_cfg_var('bcperf.' . strtolower($name));
		}

		return is_string($value) && $value !== '' ? $value : $default;
	}
}

if (!function_exists('bcperf_enabled')) {
	function bcperf_enabled(): bool {
		if (PHP_SAPI === 'cli' && bcperf_setting('CLI') !== '1') {
			return false;
		}

		return bcperf_setting('LOG') !== '';
	}
}

if (!function_exists('bcperf_boot')) {
	/**
	 * The whole entry path. Returns the shutdown handler to register, or null when this request is
	 * not being recorded - a non-sampled request therefore costs two settings lookups and one
	 * mt_rand(), and never registers anything.
	 */
	function bcperf_boot(): ?Closure {
		if (!bcperf_enabled()) {
			return null;
		}

		$rate = (int) bcperf_setting('SAMPLE_RATE', '1');
		if ($rate < 1) {
			$rate = 1;
		}
		if ($rate > 1 && mt_rand(1, $rate) !== 1) {
			return null;
		}

		$log     = bcperf_setting('LOG');
		$started = microtime(true);
		$rusage  = getrusage();

		return static function () use ($log, $started, $rusage, $rate): void {
			try {
				$line = bcperf_build_line($started, $rusage, microtime(true), getrusage(), $rate);
				if ($line !== null) {
					bcperf_write($log, $line, $started);
				}
			} catch (\Throwable) {
			}
		};
	}
}

if (!function_exists('bcperf_build_line')) {
	/**
	 * One request as one JSON line. The keys are short because this is written once per request
	 * and the line has to stay under 4096 bytes.
	 *
	 * @param array<string,int> $before getrusage() taken on the way in
	 * @param array<string,int> $after  getrusage() taken in shutdown
	 */
	function bcperf_build_line(float $startedAt, array $before, float $endedAt, array $after, int $weight): ?string {
		$uri      = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
		$queryAt  = strpos($uri, '?');
		$path     = $queryAt === false ? $uri : substr($uri, 0, $queryAt);
		$query    = $queryAt === false ? (string) ($_SERVER['QUERY_STRING'] ?? '') : substr($uri, $queryAt + 1);

		$row = [
			't'  => round($startedAt, 3),
			'd'  => round($endedAt - $startedAt, 6),
			'u'  => round(bcperf_cpu_delta($before, $after, 'utime'), 6),
			's'  => round(bcperf_cpu_delta($before, $after, 'stime'), 6),
			'm'  => memory_get_peak_usage(true),
			'c'  => (int) http_response_code(),
			'sv' => bcperf_scheme(),
			'h'  => substr((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''), 0, 255),
			'p'  => substr($path, 0, 1024),
			'q'  => substr($query, 0, 1024),
			'x'  => substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 16),
			'i'  => (int) getmypid(),
			'w'  => $weight,
			'n'  => (string) gethostname(),
		];

		$extra = bcperf_extra();
		if ($extra !== []) {
			$row['e'] = $extra;
		}

		// Under 4096 bytes an appending write is atomic on a local filesystem, which is what lets
		// several PHP workers share one log without a lock of our own. Shed the expendable fields
		// rather than hand the aggregator a line it cannot parse.
		$line = bcperf_encode($row);
		if ($line !== null) {
			return $line;
		}

		unset($row['e']);
		$line = bcperf_encode($row);
		if ($line !== null) {
			return $line;
		}

		$row['q'] = '';
		$row['p'] = substr($row['p'], 0, 512);

		return bcperf_encode($row);
	}
}

if (!function_exists('bcperf_encode')) {
	function bcperf_encode(array $row): ?string {
		// PRESERVE_ZERO_FRACTION keeps a zero duration a float: the aggregator decodes into typed
		// properties and an int where it expects a float is a decoding error, not a measurement.
		$line = json_encode(
			$row,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
		);

		return $line !== false && strlen($line) < 4096 ? $line . "\n" : null;
	}
}

if (!function_exists('bcperf_cpu_delta')) {
	/**
	 * @param array<string,int> $before
	 * @param array<string,int> $after
	 */
	function bcperf_cpu_delta(array $before, array $after, string $which): float {
		return ($after["ru_{$which}.tv_sec"] ?? 0) - ($before["ru_{$which}.tv_sec"] ?? 0)
			+ (($after["ru_{$which}.tv_usec"] ?? 0) - ($before["ru_{$which}.tv_usec"] ?? 0)) / 1000000;
	}
}

if (!function_exists('bcperf_scheme')) {
	function bcperf_scheme(): string {
		if (isset($_SERVER['REQUEST_SCHEME'])) {
			return (string) $_SERVER['REQUEST_SCHEME'];
		}
		$https = $_SERVER['HTTPS'] ?? '';

		return $https !== '' && $https !== 'off' ? 'https' : 'http';
	}
}

if (!function_exists('bcperf_extra')) {
	/**
	 * An application that can tell us more fills $GLOBALS['_bcperf_extra'] - a Doctrine query
	 * count, WordPress' $wpdb, a custom timer. Only numbers survive: the aggregator sums these
	 * per bucket and has nothing to do with a string.
	 *
	 * @return array<string,int|float>
	 */
	function bcperf_extra(): array {
		$extra = $GLOBALS['_bcperf_extra'] ?? null;
		if (!is_array($extra)) {
			return [];
		}

		$numbers = [];
		foreach ($extra as $key => $value) {
			if (is_int($value)) {
				$numbers[substr((string) $key, 0, 32)] = $value;
			} elseif (is_float($value)) {
				$numbers[substr((string) $key, 0, 32)] = round($value, 6);
			}
		}

		return $numbers;
	}
}

if (!function_exists('bcperf_expand_path')) {
	/**
	 * Daily or hourly rotation, written the way strftime() spells it because that is what the
	 * documentation has always said - expanded through date() because strftime() is deprecated
	 * and gone in PHP 9.
	 */
	function bcperf_expand_path(string $pattern, float $time): string {
		if (strpos($pattern, '%') === false) {
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

if (!function_exists('bcperf_write')) {
	function bcperf_write(string $path, string $line, float $time): void {
		// Suppressed on purpose: an unwritable log is the operator's problem to notice in the
		// aggregator, not a warning in the monitored application's output or error log. A
		// try/catch does not cover this - file_put_contents warns, it does not throw.
		@file_put_contents(bcperf_expand_path($path, $time), $line, FILE_APPEND | LOCK_EX);
	}
}

// A second prepend of the same file would otherwise register a second handler and write the
// request twice; the function declarations above are idempotent on their own.
if (!defined('BCPERF_ACTIVE')) {
	define('BCPERF_ACTIVE', true);

	$bcperf_shutdown = bcperf_boot();
	if ($bcperf_shutdown !== null) {
		register_shutdown_function($bcperf_shutdown);
	}
	unset($bcperf_shutdown);
}
