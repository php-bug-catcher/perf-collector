<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Aggregate;

use Stringable;

/**
 * What makes one bucket one bucket. `serverName` is the machine and `host` is the vhost, kept
 * apart deliberately: it is the difference between "checkout is slow" and "checkout is slow on
 * web-03", and only the second one tells you where to look.
 */
final readonly class BucketKey implements Stringable {

	/** A request URI is attacker-controlled, so the separator is one it cannot contain. */
	private const string SEPARATOR = "\x1f";

	public function __construct(
		public int $minute,
		public string $serverName,
		public string $host,
		public string $path,
	) {
	}

	public function __toString(): string {
		return $this->minute . self::SEPARATOR . $this->serverName . self::SEPARATOR . $this->host . self::SEPARATOR . $this->path;
	}
}
