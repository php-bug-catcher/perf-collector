<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Ship;

use BugCatcher\PerfCollector\Aggregate\BucketAccumulator;
use BugCatcher\PerfCollector\Aggregate\BucketKey;
use BugCatcher\PerfCollector\Sample\Sample;
use BugCatcher\PerfCollector\Ship\BatchPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class BatchPayloadBuilderTest extends TestCase {

	public function testTheBodyNamesTheProjectAndCarriesTheRows(): void {
		$body = $this->build('myapp', [$this->bucket('/feed/'), $this->bucket('/news/')]);

		$this->assertSame(['projectCode', 'rows'], array_keys($body));
		$this->assertSame('myapp', $body['projectCode']);
		$this->assertCount(2, $body['rows']);
	}

	/** Keyed by bucket key in PHP, but a JSON object where the server expects an array breaks it. */
	public function testTheRowsAreAJsonListAndNotAnObject(): void {
		$json = (new BatchPayloadBuilder())->build('myapp', [
			'whatever-key' => $this->bucket('/feed/'),
			'another-one'  => $this->bucket('/news/'),
		]);

		$this->assertStringContainsString('"rows":[{', $json);
	}

	public function testARowIsExactlyWhatTheAccumulatorProduced(): void {
		$bucket = $this->bucket('/feed/');

		$body = $this->build('myapp', [$bucket]);

		$this->assertSame($bucket->toPayloadRow(), $body['rows'][0]);
	}

	public function testAnEmptyRunStillProducesAValidBody(): void {
		$body = $this->build('myapp', []);

		$this->assertSame([], $body['rows']);
	}

	public function testSlashesInPathsAreNotEscapedIntoNoise(): void {
		$json = (new BatchPayloadBuilder())->build('myapp', [$this->bucket('/catalogue/{id}/reviews')]);

		$this->assertStringContainsString('"/catalogue/{id}/reviews"', $json);
	}

	/** @param list<BucketAccumulator>|array<string,BucketAccumulator> $buckets */
	private function build(string $project, array $buckets): array {
		return json_decode((new BatchPayloadBuilder())->build($project, $buckets), true, 512, JSON_THROW_ON_ERROR);
	}

	private function bucket(string $path): BucketAccumulator {
		$accumulator = new BucketAccumulator(new BucketKey(1759397640, 'web-01', 'www.site.com', $path));
		$accumulator->add(new Sample(
			timestamp: 1759397640.0,
			duration: 0.4,
			userCpu: 0.3,
			systemCpu: 0.01,
			memory: 1024,
			status: 200,
			scheme: 'https',
			host: 'www.site.com',
			path: $path,
			query: '',
			method: 'GET',
			pid: 1,
			weight: 1,
			serverName: 'web-01',
		));

		return $accumulator;
	}
}
