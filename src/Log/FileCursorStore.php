<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Log;

use BugCatcher\PerfCollector\Directory;
use BugCatcher\PerfCollector\Exception\InvalidConfiguration;

/**
 * One small JSON file per log, in the state directory. Rotation is detected on the way out of
 * `load()` rather than by the caller: a changed inode, or a file shorter than the offset we
 * stored, both mean the bytes we were pointing at are gone and reading has to start over.
 */
final readonly class FileCursorStore implements CursorStore {

	public function __construct(private string $stateDir) {
	}

	public function load(string $logPath): ?Cursor {
		$inode = @fileinode($logPath);
		if ($inode === false) {
			return null;
		}

		$state = @file_get_contents($this->stateFile($logPath));
		if ($state === false) {
			return null;
		}

		$decoded = json_decode($state, true);
		if (!is_array($decoded) || !is_int($decoded['inode'] ?? null) || !is_int($decoded['offset'] ?? null)) {
			// A torn or hand-edited state file costs one re-read of the log, which the server
			// deduplicates anyway. Refusing to run would cost every window from here on.
			return null;
		}

		$rotated = $decoded['inode'] !== $inode || (int) @filesize($logPath) < $decoded['offset'];

		return new Cursor($logPath, $inode, $rotated ? 0 : $decoded['offset']);
	}

	public function save(Cursor $cursor): void {
		Directory::ensure($this->stateDir);

		$target = $this->stateFile($cursor->path);
		// Written whole and then renamed: a crash halfway through must not leave behind a state
		// file that parses into a plausible but wrong offset.
		$temporary = $target . '.' . getmypid() . '.tmp';
		$written   = @file_put_contents($temporary, json_encode([
			'path'   => $cursor->path,
			'inode'  => $cursor->inode,
			'offset' => $cursor->offset,
		], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

		if ($written === false || !@rename($temporary, $target)) {
			@unlink($temporary);

			throw new InvalidConfiguration(sprintf('The cursor for "%s" cannot be written to "%s".', $cursor->path, $this->stateDir));
		}
	}

	/** The hash keys the file; the readable prefix is there so an operator can see what it is. */
	private function stateFile(string $logPath): string {
		$name = preg_replace('~[^A-Za-z0-9._-]+~', '-', basename($logPath)) ?? 'log';

		return sprintf('%s/%s-%s.json', rtrim($this->stateDir, '/'), $name, substr(sha1($logPath), 0, 12));
	}
}
