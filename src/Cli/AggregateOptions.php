<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Cli;

/** One parsed command line. Nothing here has touched the filesystem or the network. */
final readonly class AggregateOptions {

	public const int DEFAULT_MAX_BYTES = 16_777_216;

	public function __construct(
		public string $log,
		public string $endpoint,
		public string $project,
		public string $stateDir,
		public string $lockFile,
		public ?string $token = null,
		public ?string $rules = null,
		public bool $defaultRules = true,
		public int $maxBytes = self::DEFAULT_MAX_BYTES,
		public bool $dryRun = false,
		public bool $help = false,
	) {
	}

	public static function help(): self {
		return new self('', '', '', '', '', help: true);
	}
}
