<?php

declare(strict_types=1);

namespace BugCatcher\PerfCollector\Ship;

/**
 * Posts one batch to a Bug Catcher server.
 *
 * `request()` is a protected seam rather than an interface - the bug-catcher-curl-reporter idiom.
 * The same hook the tests capture with is the one an installation overrides to add a proxy,
 * client certificates or an authentication scheme nobody here has heard of. That is also why the
 * class is not final.
 */
class HttpShipper {

	private const string INGEST_PATH = '/api/perf_buckets';

	public function __construct(
		private readonly string $endpoint,
		private readonly ?string $token = null,
		private readonly int $connectTimeout = 3,
		private readonly int $timeout = 30,
	) {
	}

	/** Only a 2xx means the server has the batch and the cursor may move. */
	public static function accepted(int $status): bool {
		return $status >= 200 && $status < 300;
	}

	/** @return array{int,string} the HTTP status - 0 when the request never got one - and the body */
	public function ship(string $body): array {
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
		];
		if ($this->token !== null && $this->token !== '') {
			$headers[] = 'Authorization: Bearer ' . $this->token;
		}

		return $this->request(rtrim($this->endpoint, '/') . self::INGEST_PATH, $body, $headers);
	}

	/**
	 * @param  list<string>   $headers
	 * @return array{int,string}
	 */
	protected function request(string $url, string $body, array $headers): array {
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
			CURLOPT_TIMEOUT        => $this->timeout,
		]);

		$response = curl_exec($curl);
		$status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error    = curl_error($curl);
		// No curl_close(): deprecated in 8.5 and a no-op since 8.0. The handle is freed with $curl.

		// A connection that never happened has no status; the caller treats 0 as "not shipped"
		// and leaves the cursor where it is, so the next run retries the same window.
		return [$status, is_string($response) ? $response : $error];
	}
}
