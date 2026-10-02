<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Ship;

use BugCatcher\PerfCollector\Ship\HttpShipper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `request()` is a protected seam rather than an interface, the way bug-catcher-curl-reporter
 * does it: the same hook the tests use is the one an installation overrides to add auth headers,
 * a proxy or mTLS.
 */
final class HttpShipperTest extends TestCase {

	public function testItPostsToTheIngestEndpoint(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com');

		$shipper->ship('{"projectCode":"myapp","rows":[]}');

		$this->assertSame('https://bugcatcher.example.com/api/perf_buckets', $shipper->url);
	}

	public function testATrailingSlashOnTheEndpointDoesNotDoubleUp(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com/');

		$shipper->ship('{}');

		$this->assertSame('https://bugcatcher.example.com/api/perf_buckets', $shipper->url);
	}

	public function testTheBodyGoesOutUntouched(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com');

		$shipper->ship('{"projectCode":"myapp","rows":[{"path":"/feed/"}]}');

		$this->assertSame('{"projectCode":"myapp","rows":[{"path":"/feed/"}]}', $shipper->body);
	}

	public function testItAnnouncesAndAcceptsJson(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com');

		$shipper->ship('{}');

		$this->assertContains('Content-Type: application/json', $shipper->headers);
		$this->assertContains('Accept: application/json', $shipper->headers);
	}

	public function testATokenIsSentAsABearerCredential(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com', 's3cret');

		$shipper->ship('{}');

		$this->assertContains('Authorization: Bearer s3cret', $shipper->headers);
	}

	public function testNoTokenMeansNoAuthorizationHeaderAtAll(): void {
		$shipper = new CapturingShipper('https://bugcatcher.example.com');

		$shipper->ship('{}');

		$this->assertSame([], preg_grep('~^Authorization~', $shipper->headers));
	}

	public function testTheStatusAndTheResponseComeBackToTheCaller(): void {
		$shipper           = new CapturingShipper('https://bugcatcher.example.com');
		$shipper->response = [422, '{"detail":"path: too long"}'];

		[$status, $response] = $shipper->ship('{}');

		$this->assertSame(422, $status);
		$this->assertSame('{"detail":"path: too long"}', $response);
	}

	#[DataProvider('outcomes')]
	public function testOnlyA2xxCountsAsShipped(int $status, bool $accepted): void {
		$this->assertSame($accepted, HttpShipper::accepted($status));
	}

	public static function outcomes(): iterable {
		yield 'created' => [201, true];
		yield 'ok' => [200, true];
		yield 'no content' => [204, true];
		yield 'moved' => [301, false];
		yield 'unprocessable' => [422, false];
		yield 'not found' => [404, false];
		yield 'server error' => [500, false];
		yield 'curl never connected' => [0, false];
	}
}

final class CapturingShipper extends HttpShipper {

	public string $url = '';
	public string $body = '';
	/** @var list<string> */
	public array $headers = [];
	/** @var array{int,string} */
	public array $response = [201, ''];

	protected function request(string $url, string $body, array $headers): array {
		$this->url     = $url;
		$this->body    = $body;
		$this->headers = $headers;

		return $this->response;
	}
}
