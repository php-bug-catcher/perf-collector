<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Sample;

/**
 * One request, as the hook measured it. The short keys on the wire become readable names here -
 * this is the last place the format matters and every stage after it reads properties.
 */
final readonly class Sample {

	/** @param array<string,int|float> $extra */
	public function __construct(
		public float $timestamp,
		public float $duration,
		public float $userCpu,
		public float $systemCpu,
		public int $memory,
		public int $status,
		public string $scheme,
		public string $host,
		public string $path,
		public string $query,
		public string $method,
		public int $pid,
		public int $weight,
		public string $serverName,
		public array $extra = [],
	) {
	}

	/** The minute this request started in, which is the minute it is aggregated into. */
	public function minute(): int {
		return intdiv((int) $this->timestamp, 60) * 60;
	}

	public function isClientError(): bool {
		return $this->status >= 400 && $this->status < 500;
	}

	public function isServerError(): bool {
		return $this->status >= 500 && $this->status < 600;
	}
}
