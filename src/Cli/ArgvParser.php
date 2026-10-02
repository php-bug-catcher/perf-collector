<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Cli;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;

/**
 * argv in, options out. Deliberately knows nothing about files, curl or the clock, so every
 * command line a cron entry can get wrong is one unit test away.
 */
final readonly class ArgvParser {

	/** option => whether it takes a value */
	private const array OPTIONS = [
		'--log'               => true,
		'--endpoint'          => true,
		'--project'           => true,
		'--token'             => true,
		'--state-dir'         => true,
		'--lock'              => true,
		'--rules'             => true,
		'--max-bytes'         => true,
		'--no-default-rules'  => false,
		'--dry-run'           => false,
		'--help'              => false,
		'-h'                  => false,
	];

	private const string DEFAULT_STATE_DIR = '/bcperf-state';

	/** @param list<string> $argv including the script name */
	public function parse(array $argv): AggregateOptions {
		$given = $this->collect(array_slice($argv, 1));

		if (isset($given['--help']) || isset($given['-h'])) {
			return AggregateOptions::help();
		}

		$stateDir = $this->value($given, '--state-dir') ?? sys_get_temp_dir() . self::DEFAULT_STATE_DIR;

		return new AggregateOptions(
			log:          $this->required($given, '--log'),
			endpoint:     $this->required($given, '--endpoint'),
			project:      $this->required($given, '--project'),
			stateDir:     $stateDir,
			lockFile:     $this->value($given, '--lock') ?? rtrim($stateDir, '/') . '/aggregate.lock',
			token:        $this->value($given, '--token'),
			rules:        $this->value($given, '--rules'),
			defaultRules: !isset($given['--no-default-rules']),
			maxBytes:     $this->maxBytes($given),
			dryRun:       isset($given['--dry-run']),
		);
	}

	/**
	 * @param  list<string>            $arguments
	 * @return array<string,string|true>
	 */
	private function collect(array $arguments): array {
		$given = [];

		for ($i = 0, $count = count($arguments); $i < $count; $i++) {
			[$name, $inlineValue] = array_pad(explode('=', $arguments[$i], 2), 2, null);

			if (!array_key_exists($name, self::OPTIONS)) {
				throw new InvalidConfiguration(sprintf(
					'"%s" is not an option. The ones there are: %s.',
					$arguments[$i],
					implode(', ', array_keys(self::OPTIONS)),
				));
			}

			if (!self::OPTIONS[$name]) {
				$given[$name] = true;

				continue;
			}

			// `--log=/var/log/a` and `--log /var/log/a` are both ordinary ways to write it.
			$value = $inlineValue ?? ($arguments[$i + 1] ?? null);
			if ($value === null) {
				throw new InvalidConfiguration(sprintf('%s needs a value.', $name));
			}
			if ($inlineValue === null) {
				$i++;
			}

			$given[$name] = $value;
		}

		return $given;
	}

	/** @param array<string,string|true> $given */
	private function required(array $given, string $name): string {
		$value = $this->value($given, $name);
		if ($value === null) {
			throw new InvalidConfiguration(sprintf('%s is required.', $name));
		}

		return $value;
	}

	/** @param array<string,string|true> $given */
	private function value(array $given, string $name): ?string {
		$value = $given[$name] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/** @param array<string,string|true> $given */
	private function maxBytes(array $given): int {
		$value = $given['--max-bytes'] ?? null;
		if ($value === null) {
			return AggregateOptions::DEFAULT_MAX_BYTES;
		}

		if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
			throw new InvalidConfiguration('--max-bytes must be a positive number of bytes.');
		}

		return (int) $value;
	}
}
