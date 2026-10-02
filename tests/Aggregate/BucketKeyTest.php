<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Aggregate;

use BugCatcher\PerfCollector\Aggregate\BucketKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketKeyTest extends TestCase {

	public function testTwoKeysForTheSameBucketReadTheSame(): void {
		$this->assertSame(
			(string) new BucketKey(1759400040, 'web-01', 'www.site.com', '/feed/'),
			(string) new BucketKey(1759400040, 'web-01', 'www.site.com', '/feed/'),
		);
	}

	#[DataProvider('differences')]
	public function testAnyDifferenceMakesADifferentBucket(BucketKey $other): void {
		$this->assertNotSame(
			(string) new BucketKey(1759400040, 'web-01', 'www.site.com', '/feed/'),
			(string) $other,
		);
	}

	public static function differences(): iterable {
		yield 'minute' => [new BucketKey(1759400100, 'web-01', 'www.site.com', '/feed/')];
		yield 'machine' => [new BucketKey(1759400040, 'web-02', 'www.site.com', '/feed/')];
		yield 'vhost' => [new BucketKey(1759400040, 'web-01', 'shop.site.com', '/feed/')];
		yield 'path' => [new BucketKey(1759400040, 'web-01', 'www.site.com', '/news/')];
	}

	/** A request URI is attacker-controlled, so the separator has to be one it cannot contain. */
	public function testAPathCannotBeCraftedToCollideWithAnotherBucket(): void {
		$this->assertNotSame(
			(string) new BucketKey(1759400040, 'web-01', 'www.site.com', '/a'),
			(string) new BucketKey(1759400040, 'web-01', 'www.site.com|/a', ''),
		);
	}
}
