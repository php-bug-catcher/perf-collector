<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Tests\Sample;

use BugCatcher\PerfCollector\Sample\SampleDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../hook/collector.php';

/**
 * The decoder is the only place where the aggregator meets bytes it did not write itself, so it
 * never throws: a torn line, a truncated write, a log somebody edited by hand all come back as
 * null and get counted. One bad line must not cost the window it was in.
 */
final class SampleDecoderTest extends TestCase {

	private SampleDecoder $decoder;

	protected function setUp(): void {
		$this->decoder = new SampleDecoder();
	}

	public function testItDecodesTheDocumentedLine(): void {
		$sample = $this->decoder->decode('{"t":1759400000.123,"d":0.438,"u":0.400,"s":0.012,"m":31457280,"c":200,"sv":"https",'
			. '"h":"www.site.com","p":"/feed/","q":"page=2","x":"GET","i":20789,"w":1,"n":"web-01"}');

		$this->assertNotNull($sample);
		$this->assertSame(1759400000.123, $sample->timestamp);
		$this->assertSame(0.438, $sample->duration);
		$this->assertSame(0.4, $sample->userCpu);
		$this->assertSame(0.012, $sample->systemCpu);
		$this->assertSame(31457280, $sample->memory);
		$this->assertSame(200, $sample->status);
		$this->assertSame('https', $sample->scheme);
		$this->assertSame('www.site.com', $sample->host);
		$this->assertSame('/feed/', $sample->path);
		$this->assertSame('page=2', $sample->query);
		$this->assertSame('GET', $sample->method);
		$this->assertSame(20789, $sample->pid);
		$this->assertSame(1, $sample->weight);
		$this->assertSame('web-01', $sample->serverName);
		$this->assertSame([], $sample->extra);
	}

	/** The hook writes these lines; the decoder reads them. Nobody else is between the two. */
	public function testEveryFieldTheHookWritesSurvivesTheRoundTrip(): void {
		$_SERVER = [
			'REQUEST_URI'    => '/checkout/step/2?coupon=SPRING',
			'HTTP_HOST'      => 'shop.example.com',
			'REQUEST_METHOD' => 'POST',
			'HTTPS'          => 'on',
		];
		$GLOBALS['_bcperf_extra'] = ['sq' => 31, 'st' => 12.5];
		$line                     = bcperf_build_line(1759400000.5, [], 1759400000.9, [], 10);
		unset($GLOBALS['_bcperf_extra']);

		$sample = $this->decoder->decode(rtrim((string) $line, "\n"));

		$this->assertNotNull($sample);
		$this->assertSame(1759400000.5, $sample->timestamp);
		$this->assertEqualsWithDelta(0.4, $sample->duration, 0.000_001);
		$this->assertSame('https', $sample->scheme);
		$this->assertSame('shop.example.com', $sample->host);
		$this->assertSame('/checkout/step/2', $sample->path);
		$this->assertSame('coupon=SPRING', $sample->query);
		$this->assertSame('POST', $sample->method);
		$this->assertSame(10, $sample->weight);
		$this->assertSame(gethostname(), $sample->serverName);
		$this->assertSame(['sq' => 31, 'st' => 12.5], $sample->extra);
	}

	public function testOnlyTheTimestampAndTheDurationAreActuallyRequired(): void {
		$sample = $this->decoder->decode('{"t":1759400000,"d":0.5}');

		$this->assertNotNull($sample);
		$this->assertSame(0.0, $sample->userCpu);
		$this->assertSame(0, $sample->memory);
		$this->assertSame(0, $sample->status);
		$this->assertSame('', $sample->path);
		$this->assertSame('', $sample->serverName);
		$this->assertSame(1, $sample->weight, 'an unsampled line counts once');
	}

	#[DataProvider('unusableLines')]
	public function testALineThatCannotBeTrustedComesBackAsNullRatherThanThrowing(string $line): void {
		$this->assertNull($this->decoder->decode($line));
	}

	public static function unusableLines(): iterable {
		yield 'empty' => [''];
		yield 'whitespace' => ['   '];
		yield 'torn mid-write' => ['{"t":1759400000.123,"d":0.4'];
		yield 'not json at all' => ['Jul 12 00:01:02 web-01 kernel: oops'];
		yield 'a json list' => ['[1,2,3]'];
		yield 'a json scalar' => ['42'];
		yield 'json null' => ['null'];
		yield 'no timestamp' => ['{"d":0.4}'];
		yield 'no duration' => ['{"t":1759400000}'];
		yield 'timestamp is not a number' => ['{"t":"yesterday","d":0.4}'];
		yield 'duration is not a number' => ['{"t":1759400000,"d":"slow"}'];
		yield 'duration is null' => ['{"t":1759400000,"d":null}'];
	}

	/** The hook caps a line at 4096 bytes, so anything bigger did not come from the hook. */
	public function testALineLongerThanTheHookCanWriteIsRefused(): void {
		$padded = '{"t":1759400000,"d":0.4,"p":"/' . str_repeat('x', 4_096) . '"}';

		$this->assertNull($this->decoder->decode($padded));
	}

	public function testNumbersWrittenAsStringsAreStillNumbers(): void {
		$sample = $this->decoder->decode('{"t":"1759400000.5","d":"0.4","m":"1024","c":"404","w":"10"}');

		$this->assertNotNull($sample);
		$this->assertSame(1759400000.5, $sample->timestamp);
		$this->assertSame(1024, $sample->memory);
		$this->assertSame(404, $sample->status);
		$this->assertSame(10, $sample->weight);
	}

	#[DataProvider('nonsenseWeights')]
	public function testAWeightBelowOneWouldErasePerfectlyGoodSamples(string $json, int $expected): void {
		$this->assertSame($expected, $this->decoder->decode('{"t":1,"d":0.1,' . $json . '}')?->weight);
	}

	public static function nonsenseWeights(): iterable {
		yield 'zero' => ['"w":0', 1];
		yield 'negative' => ['"w":-5', 1];
		yield 'not a number' => ['"w":"lots"', 1];
		yield 'fractional' => ['"w":2.7', 2];
	}

	public function testOnlyNumbersSurviveFromTheExtraObject(): void {
		$sample = $this->decoder->decode('{"t":1,"d":0.1,"e":{"sq":12,"st":4.5,"note":"slow","deep":{"a":1}}}');

		$this->assertSame(['sq' => 12, 'st' => 4.5], $sample?->extra);
	}

	public function testAnExtraThatIsNotAnObjectIsJustAbsent(): void {
		$this->assertSame([], $this->decoder->decode('{"t":1,"d":0.1,"e":"nope"}')?->extra);
	}

	public function testAStringWhereAStringBelongsIsNotCoercedFromAnArray(): void {
		$sample = $this->decoder->decode('{"t":1,"d":0.1,"p":["/a","/b"],"h":{"x":1}}');

		$this->assertSame('', $sample?->path);
		$this->assertSame('', $sample?->host);
	}
}
