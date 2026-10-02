<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Cli;

use BugCatcher\PerfCollector\Aggregate\SampleGrouper;
use BugCatcher\PerfCollector\Aggregator;
use BugCatcher\PerfCollector\Directory;
use BugCatcher\PerfCollector\Exception\InvalidConfiguration;
use BugCatcher\PerfCollector\FileLock;
use BugCatcher\PerfCollector\Log\FileCursorStore;
use BugCatcher\PerfCollector\Log\JsonLinesReader;
use BugCatcher\PerfCollector\Log\LogPathResolver;
use BugCatcher\PerfCollector\Normalize\PathNormalizer;
use BugCatcher\PerfCollector\Sample\SampleDecoder;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use BugCatcher\PerfCollector\Ship\HttpShipper;
use BugCatcher\PerfCollector\SystemClock;

/**
 * The composition root behind `bin/bc-perf-aggregate`: builds the object graph and turns what
 * comes back into an exit code. Nothing else in the package constructs anything.
 */
final class AggregateCli {

	public const int SUCCESS = 0;
	/** The batch did not reach the server. The cursor has not moved; cron will try again. */
	public const int NOT_SHIPPED = 1;
	/** The command line or a rules file is wrong. Trying again will not help. */
	public const int MISCONFIGURED = 2;

	/**
	 * @param list<string>   $argv
	 * @param resource|null $stdout
	 * @param resource|null $stderr
	 */
	public static function main(array $argv, $stdout = null, $stderr = null): int {
		$stdout ??= fopen('php://stdout', 'wb');
		$stderr ??= fopen('php://stderr', 'wb');

		try {
			$options = (new ArgvParser())->parse($argv);

			if ($options->help) {
				fwrite($stdout, self::usage());

				return self::SUCCESS;
			}

			$result = self::aggregator($options)->run();
		} catch (InvalidConfiguration $e) {
			fwrite($stderr, $e->getMessage() . PHP_EOL);

			return self::MISCONFIGURED;
		}

		if (!$result->shipped && $result->bucketsShipped > 0 && !$options->dryRun) {
			fwrite($stderr, $result->summary() . PHP_EOL);

			return self::NOT_SHIPPED;
		}

		fwrite($stdout, $result->summary() . PHP_EOL);

		return self::SUCCESS;
	}

	private static function aggregator(AggregateOptions $options): Aggregator {
		Directory::ensure($options->stateDir);

		return new Aggregator(
			paths:          new LogPathResolver(new SystemClock()),
			cursors:        new FileCursorStore($options->stateDir),
			reader:         new JsonLinesReader(),
			decoder:        new SampleDecoder(),
			grouper:        new SampleGrouper(self::normalizer($options)),
			payloads:       new BatchPayloadBuilder(),
			shipper:        new HttpShipper($options->endpoint, $options->token),
			lock:           new FileLock($options->lockFile),
			logPattern:     $options->log,
			projectCode:    $options->project,
			maxBytesPerRun: $options->maxBytes,
			dryRun:         $options->dryRun,
		);
	}

	private static function normalizer(AggregateOptions $options): PathNormalizer {
		return $options->rules === null
			? PathNormalizer::withDefaults()
			: PathNormalizer::fromRulesFile($options->rules, $options->defaultRules);
	}

	private static function usage(): string {
		return <<<TEXT
			bc-perf-aggregate - roll a local performance log up and ship it to Bug Catcher.

			Usage:
			  bc-perf-aggregate --log=PATH --endpoint=URL --project=CODE [options]

			Required:
			  --log=PATH          The log the hook writes. Accepts %Y %m %d %H for rotation.
			  --endpoint=URL      Base URL of the Bug Catcher server.
			  --project=CODE      The project code the samples belong to.

			Options:
			  --token=TOKEN       Sent as a bearer credential.
			  --state-dir=PATH    Where read cursors are kept. Default: the system temp directory.
			  --lock=PATH         Lock file. Default: aggregate.lock inside the state directory.
			  --rules=PATH        JSON list of extra path normalisation rules.
			  --no-default-rules  Use only the rules in --rules, not the shipped ones as well.
			  --max-bytes=N       Most bytes to read in one run. Default: 16777216. A backlog is
			                      drained over several runs rather than one impossible request.
			  --dry-run           Read and group, report, ship nothing, move nothing.
			  -h, --help          This text.

			Exit codes: 0 done (or another run holds the lock), 1 not shipped, 2 misconfigured.

			TEXT;
	}
}
