<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Log;

/**
 * How far into one log file the last run got. The inode is part of it because the path alone
 * cannot tell a rotated file from the one that was read yesterday.
 */
final readonly class Cursor {

	public function __construct(
		public string $path,
		public int $inode,
		public int $offset,
	) {
	}

	public function movedTo(int $offset): self {
		return new self($this->path, $this->inode, $offset);
	}
}
