<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests;

use BugCatcher\PerfCollector\Ship\HttpShipper;
use Closure;

/**
 * The seam HttpShipper exposes for exactly this, used exactly as an installation would use it.
 */
final class RecordingShipper extends HttpShipper {

	/** @var list<string> */
	public array $bodies = [];
	public int $status = 201;
	/** Fires while the batch is "in flight", which is when the hook keeps appending. */
	public ?Closure $onShip = null;

	protected function request(string $url, string $body, array $headers): array {
		$this->bodies[] = $body;
		($this->onShip ?? static fn() => null)();

		return [$this->status, ''];
	}
}
