<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Log;

use Generator;

/**
 * Streams complete lines from an offset. The hook is appending to the same file, so a run lands
 * in the middle of a write sooner or later: reading stops at the last newline and the partial
 * line stays in the file, where the next run picks it up whole.
 *
 * The generator's return value is the new offset - `$stream->getReturn()` once it is drained -
 * so memory stays bounded by the chunk size rather than by the size of the log.
 */
final readonly class JsonLinesReader {

	/** The hook caps a line at 4096 bytes; anything past this is corruption, not a measurement. */
	private const int MAX_LINE_BYTES = 65_536;

	public function __construct(private int $chunkSize = 262_144) {
	}

	/** @return Generator<int,string,void,int> yields lines, returns the offset reached */
	public function read(string $path, int $offset): Generator {
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return $offset;
		}

		try {
			if ($offset > 0 && fseek($handle, $offset) !== 0) {
				return $offset;
			}

			$buffer    = '';
			$consumed  = $offset;
			$discarding = false;

			while (($chunk = fread($handle, $this->chunkSize)) !== false && $chunk !== '') {
				$buffer   .= $chunk;
				$lastBreak = strrpos($buffer, "\n");

				if ($lastBreak === false) {
					// No newline in a buffer this big means the line cannot have come from the
					// hook. Drop it rather than let a corrupt log exhaust the cron job's memory.
					if (strlen($buffer) > self::MAX_LINE_BYTES) {
						$consumed  += strlen($buffer);
						$buffer     = '';
						$discarding = true;
					}

					continue;
				}

				$complete  = substr($buffer, 0, $lastBreak + 1);
				$buffer    = substr($buffer, $lastBreak + 1);
				$consumed += strlen($complete);

				if ($discarding) {
					// The first newline ends the oversized line; what precedes it is its tail.
					$complete   = substr($complete, (int) strpos($complete, "\n") + 1);
					$discarding = false;
				}

				foreach (explode("\n", rtrim($complete, "\n")) as $line) {
					if ($line !== '') {
						yield $line;
					}
				}
			}

			return $consumed;
		} finally {
			fclose($handle);
		}
	}
}
