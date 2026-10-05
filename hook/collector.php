<?php

/**
 * Bug Catcher performance collector - the hook.
 *
 * Two ways in, and they have to report the same request: `auto_prepend_file` in php.ini, or a
 * `require` of this file in the application's entry point for an installation that has no php.ini
 * to edit. That second mode is why the duration is measured from REQUEST_TIME_FLOAT rather than
 * from the moment this file ran, and why {@see bcperf_inline_setting()} exists at all.
 *
 * Prepended, this file is loaded before the application's autoloader; required, it has to behave
 * as if it were. So it may not require anything, and it lives outside PSR-4 on purpose. It runs in
 * every single request, which is why all it does is measure and append one line: normalisation,
 * histograms, grouping and HTTP all belong to bin/bc-perf-aggregate, which runs from cron and not
 * from a request.
 *
 * Budget: 100 us on the way in, 200 us in shutdown, excluding the write itself. The functions are
 * declared separately from the top-level code both to keep that budget honest - they are the unit
 * the benchmark measures - and because a monitoring hook nobody can test is a liability.
 *
 * Nothing in here may surface in the application: the shutdown handler is wrapped in
 * `try/catch (\Throwable)` with an empty body, because a monitoring hook that can take down the
 * application it monitors is worse than no monitoring.
 */

if (!function_exists('bcperf_inline_setting')) {
	/**
	 * The channel for an installation with no php.ini and no process environment: the entry point
	 * fills $GLOBALS['_bcperf_config'] immediately before it requires this file. Last of the three
	 * sources deliberately, so an operator who does have env or php.ini can override a value the
	 * application ships without editing the application.
	 */
	function bcperf_inline_setting(string $name): string {
		$config = $GLOBALS['_bcperf_config'] ?? null;
		if (!is_array($config)) {
			return '';
		}

		// Lower case like the php.ini keys; the upper case form is taken as well, because a hook
		// left inert by a mistyped key is exactly the silent failure this mode has to avoid.
		$value = $config[strtolower($name)] ?? $config[$name] ?? null;

		// Numbers and bools pass, because `'cli' => true` and `'sample_rate' => 10` is what one
		// actually writes in a PHP array; anything else is ignored rather than fatal.
		if (is_bool($value)) {
			return $value ? '1' : '';
		}

		return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
	}
}

if (!function_exists('bcperf_setting')) {
	/**
	 * Configuration comes from the environment, from php.ini or from $GLOBALS['_bcperf_config'],
	 * because at this point there is no container, no .env parser and no autoloader to read
	 * anything with. `get_cfg_var()` rather than `ini_get()`: bcperf.* are not directives PHP
	 * knows about, and ini_get() only answers for registered ones.
	 */
	function bcperf_setting(string $name, string $default = ''): string {
		$value = getenv('BCPERF_' . $name);
		if ($value === false || $value === '') {
			$value = get_cfg_var('bcperf.' . strtolower($name));
		}
		if (!is_string($value) || $value === '') {
			$value = bcperf_inline_setting($name);
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

		$log = bcperf_setting('LOG');

		// From the start of the request, not from the moment this file got its turn. That is what
		// makes the two installation modes measure the same thing: whether an auto_prepend_file
		// brought us in or a `require` on the first line of index.php did, the number is the same
		// and where exactly that require sits stops mattering. The fallback is for a SAPI or a
		// variables_order that leaves $_SERVER unpopulated.
		$started = isset($_SERVER['REQUEST_TIME_FLOAT'])
			? (float) $_SERVER['REQUEST_TIME_FLOAT']
			: microtime(true);

		// CPU, on the other hand, is measured from here: there is no getrusage() of the past to
		// subtract from. PHP's own startup therefore reads as wallclock the request spent waiting,
		// equally in both modes.
		$rusage = bcperf_rusage();

		return static function () use ($log, $started, $rusage, $rate): void {
			try {
				$line = bcperf_build_line($started, $rusage, microtime(true), bcperf_rusage(), $rate);
				if ($line !== null) {
					bcperf_write($log, $line, $started);
				}
			} catch (\Throwable) {
			}
		};
	}
}

if (!function_exists('bcperf_rusage')) {
	/**
	 * CPU accounting where the platform has it, and null where it does not.
	 *
	 * `getrusage()` is POSIX and PHP does not define it on Windows at all. The call this replaces
	 * sat outside the shutdown handler's try/catch and ran at the top of every request, so on IIS
	 * it was not "no CPU numbers" - it was `Call to undefined function getrusage()` in front of
	 * the application, in every single request. A monitoring hook that can take down what it
	 * monitors is worse than no monitoring, and that is the contract this file opens with.
	 *
	 * Null rather than an array of zeroes, deliberately: {@see bcperf_build_line()} then leaves
	 * `u` and `s` out of the line entirely. Zeroes would be indistinguishable from a request that
	 * burned no CPU - which does not exist - and the server subtracts CPU from wallclock to get
	 * the waiting band, so it would report the whole of every request as time spent waiting.
	 *
	 * @return array<string,int>|null
	 */
	function bcperf_rusage(): ?array {
		static $available = null;

		if ($available === null) {
			$available = function_exists('getrusage');
		}

		return $available ? getrusage() : null;
	}
}

if (!function_exists('bcperf_cli_path')) {
	/**
	 * What to call a command-line run, which has no request URI to be called after.
	 *
	 * The script alone is not enough: `execute.php` is the single entry point of a hundred cron
	 * tasks and the only thing telling them apart is the argument naming the one to run. Without
	 * that argument every task on the machine aggregates into one path, and a dashboard that
	 * cannot separate `SyncAllPayments` from `ParseEmail` says nothing about either.
	 *
	 * Deliberately narrow about what it takes:
	 *
	 * - The **basename** of the script, not the path it was invoked by. The same task is written
	 *   `C:\app\execute.php` in one scheduled task and `c:/app/execute.php` in the next, and as a
	 *   path those are two different strings for one script - so the directory, the drive letter
	 *   and the slashes all go.
	 * - **One** argument, the first that is not an option: `-isps 1,6` says how a task was asked
	 *   to run, not which task it was, and folding every argument in would mean a path per
	 *   invocation. A path per invocation is what `perf.rollup_path_cap` exists to throw away.
	 *
	 * Backslashes in it become slashes, so a namespaced class name reads as - and normalises
	 * like - the path it is standing in for.
	 *
	 * Both of those limits are limits of `argv`, not judgements about what is worth recording.
	 * Nothing here knows which option takes a value - so `php bin/console --env prod app:sync`
	 * reads `prod` as the job - nor that `app:imp` is an abbreviation of something, nor that one
	 * of those tokens is called `password`. An application that has booted a framework knows all
	 * three, and `php-bug-catcher/perf-collector-bundle` therefore names a Symfony console run
	 * from the resolved command plus **every** positional argument, and sets
	 * {@see $_SERVER['REQUEST_URI']} to it. Which is believed over anything worked out here - see
	 * {@see bcperf_build_line()}.
	 */
	function bcperf_cli_path(): string {
		$argv   = (array) ($_SERVER['argv'] ?? []);
		$script = strtr((string) ($argv[0] ?? $_SERVER['SCRIPT_NAME'] ?? ''), '\\', '/');
		$path   = '/' . substr($script, strrpos($script, '/') === false ? 0 : strrpos($script, '/') + 1);

		for ($i = 1, $count = count($argv); $i < $count; $i++) {
			$argument = (string) $argv[$i];

			if ($argument === '' || $argument === '--' || $argument[0] === '-') {
				continue;
			}

			return $path . '/' . ltrim(strtr($argument, '\\', '/'), '/');
		}

		return $path;
	}
}

if (!function_exists('bcperf_build_line')) {
	/**
	 * One request as one JSON line. The keys are short because this is written once per request
	 * and the line has to stay under 4096 bytes.
	 *
	 * @param array<string,int>|null $before getrusage() taken on the way in, null where the
	 *     platform has no getrusage() - see {@see bcperf_rusage()}
	 * @param array<string,int>|null $after  the same, taken in shutdown
	 */
	function bcperf_build_line(float $startedAt, ?array $before, float $endedAt, ?array $after, int $weight): ?string {
		// A request is named by its URI; a command-line run has none and is named after the command
		// - see {@see bcperf_cli_path()}. REQUEST_URI is still preferred where it exists, because a
		// CLI worker that sets one is telling us what it is standing in for.
		$uri      = $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? bcperf_cli_path() : $_SERVER['SCRIPT_NAME'] ?? '');
		$queryAt  = strpos($uri, '?');
		$path     = $queryAt === false ? $uri : substr($uri, 0, $queryAt);
		$query    = $queryAt === false ? (string) ($_SERVER['QUERY_STRING'] ?? '') : substr($uri, $queryAt + 1);

		// Absent, not zero, where the platform cannot measure CPU. The aggregator sums what it is
		// given and the server reads wallclock minus CPU as time spent waiting, so a zero here
		// would turn every Windows request into one that waited for its whole duration. Spread
		// in place rather than appended, because the order of these keys is pinned.
		$cpu = $before !== null && $after !== null
			? [
				'u' => round(bcperf_cpu_delta($before, $after, 'utime'), 6),
				's' => round(bcperf_cpu_delta($before, $after, 'stime'), 6),
			]
			: [];

		$row = [
			't'  => round($startedAt, 3),
			'd'  => round($endedAt - $startedAt, 6),
			...$cpu,
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

// A second load of the same file would otherwise register a second handler and write the request
// twice; the function declarations above are idempotent on their own. The guard closes only once
// the hook is actually switched on, though: otherwise a machine-wide auto_prepend_file with no
// configuration of its own would lock out the `require` in index.php that brings the configuration
// with it. Sampling happens behind the guard, so a request the sample rate declined stays declined
// however many times the file is loaded.
if (!defined('BCPERF_ACTIVE') && bcperf_enabled()) {
	define('BCPERF_ACTIVE', true);

	$bcperf_shutdown = bcperf_boot();
	if ($bcperf_shutdown !== null) {
		register_shutdown_function($bcperf_shutdown);
	}
	unset($bcperf_shutdown);
}
