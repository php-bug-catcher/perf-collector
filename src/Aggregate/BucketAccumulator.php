<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Aggregate;

use BugCatcher\PerfCollector\Histogram\DurationHistogram;
use BugCatcher\PerfCollector\Sample\Sample;

/**
 * Everything the server needs to know about one route in one minute on one machine.
 *
 * A sample taken 1-in-10 stands for ten requests, which is why the rate is written into every
 * line: sums and counts scale by it, maxima do not - a maximum is a measurement of one request
 * and multiplying it would be a lie.
 */
final class BucketAccumulator {

	private int $hits = 0;
	private float $sumDuration = 0.0;
	private float $sumUser = 0.0;
	private float $sumSystem = 0.0;
	private float $maxDuration = 0.0;
	private int $sumMemory = 0;
	private int $maxMemory = 0;
	private int $clientErrors = 0;
	private int $serverErrors = 0;
	private DurationHistogram $histogram;

	/** @var array<string,int|float> */
	private array $extra = [];

	public function __construct(public readonly BucketKey $key) {
		$this->histogram = new DurationHistogram();
	}

	public function add(Sample $sample): void {
		$weight = $sample->weight;

		$this->hits        += $weight;
		$this->sumDuration += $sample->duration * $weight;
		$this->sumUser     += $sample->userCpu * $weight;
		$this->sumSystem   += $sample->systemCpu * $weight;
		$this->sumMemory   += $sample->memory * $weight;
		$this->maxDuration  = max($this->maxDuration, $sample->duration);
		$this->maxMemory    = max($this->maxMemory, $sample->memory);

		if ($sample->isClientError()) {
			$this->clientErrors += $weight;
		} elseif ($sample->isServerError()) {
			$this->serverErrors += $weight;
		}

		$this->histogram->record($sample->duration, $weight);

		foreach ($sample->extra as $name => $value) {
			$this->extra[$name] = ($this->extra[$name] ?? 0) + $value * $weight;
		}
	}

	/** @return array<string,mixed> one row of `POST /api/perf_buckets` */
	public function toPayloadRow(): array {
		return [
			'bucketAt'     => gmdate('Y-m-d\TH:i:s\Z', $this->key->minute),
			'serverName'   => $this->key->serverName,
			'host'         => $this->key->host,
			'path'         => $this->key->path,
			'hits'         => $this->hits,
			'sumDuration'  => round($this->sumDuration, 6),
			'sumUser'      => round($this->sumUser, 6),
			'sumSys'       => round($this->sumSystem, 6),
			'maxDuration'  => round($this->maxDuration, 6),
			'sumMem'       => $this->sumMemory,
			'maxMem'       => $this->maxMemory,
			'clientErrors' => $this->clientErrors,
			'serverErrors' => $this->serverErrors,
			'histogram'    => $this->histogram->toArray(),
			'extra'        => $this->extra,
		];
	}
}
