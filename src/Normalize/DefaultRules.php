<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Normalize;

/**
 * What a path has to be reduced to before it can be aggregated at all. Every rule here matches a
 * whole segment - or the stem of one, so that `/image/42.jpg` keeps its extension - and the order
 * matters: a UUID and an MD5 are both made partly of digits, so the numeric rule has to go last
 * or it shreds them first.
 */
final class DefaultRules {

	/** @return list<NormalizationRule> */
	public static function all(): array {
		return [
			new NormalizationRule('~/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=[/.]|$)~i', '/{uuid}'),
			// 16 hex characters is past the point where a path segment is still a word: md5 is
			// 32, sha1 40, sha256 64. `/tag/beef` stays `/tag/beef`.
			new NormalizationRule('~/[0-9a-f]{16,}(?=[/.]|$)~i', '/{hash}'),
			new NormalizationRule('~/\d+(?=[/.]|$)~', '/{id}'),
		];
	}
}
