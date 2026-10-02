<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector;

use BugCatcher\PerfCollector\Exception\InvalidConfiguration;

/**
 * Keeps two runs off the same log. Cron overlaps: a run that takes longer than a minute meets
 * the next one, and both would read the same lines.
 *
 * Non-blocking on purpose - the second run backs off and exits quietly rather than queueing up
 * behind the first, because a minute later there will be another one anyway. `flock()` and not
 * `symfony/lock`: this package has no dependencies and is not getting any.
 */
final class FileLock {

	/** @var resource|null */
	private $handle = null;

	public function __construct(private readonly string $path) {
	}

	public function __destruct() {
		$this->release();
	}

	public function acquire(): bool {
		if ($this->handle !== null) {
			return true;
		}

		$handle = @fopen($this->path, 'c');
		if ($handle === false) {
			throw new InvalidConfiguration(sprintf('The lock file "%s" cannot be opened.', $this->path));
		}

		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);

			return false;
		}

		$this->handle = $handle;

		return true;
	}

	public function release(): void {
		if ($this->handle === null) {
			return;
		}

		flock($this->handle, LOCK_UN);
		fclose($this->handle);
		$this->handle = null;
	}
}
