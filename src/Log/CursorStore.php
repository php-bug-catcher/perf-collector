<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Log;

interface CursorStore {

	/** Null when there is nothing to resume from - a first run, or a log that is gone. */
	public function load(string $logPath): ?Cursor;

	public function save(Cursor $cursor): void;
}
