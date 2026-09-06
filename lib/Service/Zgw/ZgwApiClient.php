<?php

/**
 * Pipelinq ZgwApiClient.
 *
 * Low-level HTTP transport + JWT minter shared by the typed ZGW resource
 * clients (ZrcClient, ZtcClient, AcClient) and by NrcSubscriptionService.
 * Concentrates three pieces of behaviour:
 *
 *   1. JWT minting per VNG-API-Common (HS256, fresh per request, no caching,
 *      ±60s leeway honoured by the receiving server).
 *   2. Outbound HTTP transport using `OCP\Http\Client\IClientService` so the
 *      bridge inherits the Nextcloud connection pool, proxy support and
 *      certificate trust store.
 *   3. Fault-translation: 401/403 with VNG "JWT verlopen"/"JWT nog niet
 *      geldig" → `ClockSkewException`; 404 → `ZgwResourceNotFoundException`.
 *      Optimistic-locking 412 handling is delegated to the resource clients
 *      (e.g. ZrcClient) since the fresh-fetch step is resource-specific.
 *
 * Client secret retrieval: `$client->secretVaultRef` is a vault URI
 * (`vault://...`); resolution is delegated to `IAppConfig` so the gemeente
 * IT team can use whatever vault backend OpenRegister has been wired with.
 * If the reference is unresolvable the client raises `ZgwException` rather
 * than mint an unsigned token.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base transport for all ZGW component calls.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
 */
class ZgwApiClient {
	/**
	 * Default request timeout in seconds. Tunable via the
	 * `pipelinq.zgw.http_timeout` app config key.
	 */
	private const DEFAULT_TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param IAppConfig $appConfig App config (timeouts + vault resolution).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mint a per-request JWT following VNG-API-Common.
	 *
	 * Payload:
	 *   - iss                 = $client.clientIdentifier
	 *   - client_id           = $client.clientIdentifier
	 *   - user_id             = $client.userId
	 *   - user_representation = $client.userRepresentation
	 *   - iat                 = current Unix time (no skew compensation)
	 *   - exp                 = iat + $expiresIn
	 *
	 * Signed HS256 with the secret resolved from `$client->secretVaultRef`.
	 *
	 * @param array<string, mixed> $client ZgwClient record (assoc array).
	 * @param int $expiresIn JWT lifetime (seconds); default 3600.
	 *
	 * @return string Compact JWT.
	 *
	 * @throws ZgwException When the vault reference cannot be resolved.
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
	 */
	public function mintJwt(array $client, int $expiresIn = 3600): string {
		$secret = $this->resolveClientSecret(reference: (string)($client['secretVaultRef'] ?? ''));
		if ($secret === '') {
			throw new ZgwException(
				sprintf(
					'ZGW: unable to resolve client secret for "%s"',
					(string)($client['clientIdentifier'] ?? '?')
				)
			);
		}

		$now = time();
		$header = ['typ' => 'JWT', 'alg' => 'HS256', 'client_identifier' => (string)$client['clientIdentifier']];

		$payload = [
			'iss' => (string)$client['clientIdentifier'],
			'client_id' => (string)$client['clientIdentifier'],
			'user_id' => (string)($client['userId'] ?? ''),
			'user_representation' => (string)($client['userRepresentation'] ?? ''),
			'iat' => $now,
			'exp' => ($now + max(60, $expiresIn)),
		];

		return self::encodeJwt(header: $header, payload: $payload, secret: $secret);
	}//end mintJwt()

	/**
	 * Send a JSON request to a ZGW component.
	 *
	 * Translates Nextcloud HTTP exceptions into domain errors:
	 *   - 401/403 + VNG "JWT verlopen"/"JWT nog niet geldig" → ClockSkewException
	 *   - 404                                                → ZgwResourceNotFoundException
	 *   - everything else                                    → ZgwException
	 *
	 * Returns the decoded body, the response status code, and the raw header
	 * map. The headers map is keyed lowercase for case-insensitive lookup
	 * (e.g. `etag`, `location`).
	 *
	 * @param string $componentUrl Component base URL (no trailing slash).
	 * @param string $method HTTP method (GET/POST/PATCH/PUT/DELETE).
	 * @param string $path Path appended to the base URL (with leading slash).
	 * @param array<string, mixed> $client ZgwClient record.
	 * @param array<string, mixed>|null $body Optional JSON-encodable body.
	 * @param array<string, string> $extraHeaders Extra headers (e.g. If-Match).
	 * @param array<string, string|int> $query Optional query parameters.
	 *
	 * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
	 *
	 * @throws ClockSkewException
	 * @throws ZgwResourceNotFoundException
	 * @throws ZgwException
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-001
	 */
	public function callComponent(
		string $componentUrl,
		string $method,
		string $path,
		array $client,
		?array $body = null,
		array $extraHeaders = [],
		array $query = [],
	): array {
		$expiresIn = (int)($client['tokenLifespanSeconds'] ?? 3600);
		$jwt = $this->mintJwt(client: $client, expiresIn: $expiresIn);

		$url = rtrim($componentUrl, '/') . '/' . ltrim($path, '/');
		if ($query !== []) {
			$separator = '?';
			if (str_contains($url, '?') === true) {
				$separator = '&';
			}

			$url .= $separator . http_build_query($query);
		}

		$headers = array_merge(
			[
				'Authorization' => 'Bearer ' . $jwt,
				'Accept' => 'application/json',
				'Accept-Crs' => 'EPSG:4326',
				'Content-Crs' => 'EPSG:4326',
			],
			$extraHeaders
		);

		$options = [
			'headers' => $headers,
			'timeout' => $this->getTimeout(),
		];

		if ($body !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['body'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$nextcloudClient = $this->clientService->newClient();

		try {
			$response = match (strtoupper($method)) {
				'GET' => $nextcloudClient->get($url, $options),
				'POST' => $nextcloudClient->post($url, $options),
				'PUT' => $nextcloudClient->put($url, $options),
				'PATCH' => $nextcloudClient->request('PATCH', $url, $options),
				'DELETE' => $nextcloudClient->delete($url, $options),
				default => throw new ZgwException('ZGW: unsupported HTTP method "' . $method . '"'),
			};
		} catch (Throwable $e) {
			$this->translateTransportError(error: $e, url: $url, method: $method);
			// TranslateTransportError always throws; this is unreachable but keeps static analysis happy.
			throw new ZgwException('ZGW: transport error', 0, $e);
		}

		$status = (int)$response->getStatusCode();
		$rawBody = (string)$response->getBody();
		$decoded = [];
		if ($rawBody !== '') {
			$decoded = json_decode($rawBody, true);
		}

		if (is_array($decoded) === false) {
			$decoded = ['raw' => $rawBody];
		}

		$headerMap = self::normaliseHeaders(headers: $response->getHeaders());

		return ['status' => $status, 'body' => $decoded, 'headers' => $headerMap];
	}//end callComponent()

	/**
	 * Resolve a vault reference (`vault://...`) to a raw secret string.
	 *
	 * Falls back to the literal value when the reference is not a `vault://`
	 * URI — useful for dev/test setups where the secret is held in IAppConfig.
	 *
	 * @param string $reference The `secretKluisRef` value.
	 *
	 * @return string The resolved secret (empty string when unresolvable).
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveClientSecret(string $reference): string {
		if ($reference === '') {
			return '';
		}

		if (str_starts_with($reference, 'vault://') === false) {
			return $reference;
		}

		// Map a vault URI such as "vault://zgw/zoetermeer/client-secret" onto
		// the app-config key "pipelinq.zgw.vault.zgw/zoetermeer/client-secret".
		// Real deployments are expected to override this via a vault backend
		// (OpenRegister WebhookService); the IAppConfig fallback keeps the
		// dev story self-contained.
		$path = substr($reference, strlen('vault://'));
		$key = 'zgw.vault.' . $path;

		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end resolveClientSecret()

	/**
	 * Translate a transport-level exception into a domain error.
	 *
	 * @param Throwable $error The underlying exception.
	 * @param string $url Target URL (for the error message).
	 * @param string $method HTTP method (for the error message).
	 *
	 * @return void
	 *
	 * @throws ClockSkewException
	 * @throws ZgwResourceNotFoundException
	 * @throws OptimisticLockException
	 * @throws ZgwException
	 */
	private function translateTransportError(Throwable $error, string $url, string $method): void {
		[$status, $body] = $this->extractErrorContext(error: $error);
		$message = $error->getMessage();

		$this->logger->info(
			'ZGW: HTTP transport error',
			['method' => $method, 'url' => $url, 'status' => $status, 'msg' => $message]
		);

		if ($status === 401 || $status === 403) {
			if (self::looksLikeClockSkew(text: $body) === true || self::looksLikeClockSkew(text: $message) === true) {
				throw new ClockSkewException(
					sprintf('ZGW: JWT clock-skew rejection from %s (status %d)', $url, $status),
					observedTime: time()
				);
			}
		}

		if ($status === 404) {
			throw new ZgwResourceNotFoundException($url);
		}

		if ($status === 412) {
			// The resource client (e.g. ZrcClient) handles the fresh-fetch step;
			// surface a bare OptimisticLockException with empty payloads.
			throw new OptimisticLockException(
				sprintf('ZGW: 412 Precondition Failed on %s %s', $method, $url),
				staleRepresentation: [],
				freshRepresentation: []
			);
		}

		throw new ZgwException(
			sprintf('ZGW: %s %s failed (status %d): %s', $method, $url, $status, $message),
			$status,
			$error
		);
	}//end translateTransportError()

	/**
	 * Extract the HTTP status and response body from a transport exception.
	 *
	 * Many Nextcloud HTTP client exceptions expose the response on a
	 * `getResponse()` accessor (Guzzle-flavoured); we sniff it here so we can
	 * read VNG fault detail strings.
	 *
	 * @param Throwable $error The underlying exception.
	 *
	 * @return array{0:int, 1:string} Tuple of [status, body].
	 */
	private function extractErrorContext(Throwable $error): array {
		// `getCode()` is declared on Throwable, so the method_exists() probe
		// this replaces could never be false.
		$status = (int)$error->getCode();

		$body = '';
		if (method_exists($error, 'getResponse') === true) {
			$resp = $error->getResponse();
			if ($resp !== null && method_exists($resp, 'getStatusCode') === true) {
				$status = (int)$resp->getStatusCode();
			}

			if ($resp !== null && method_exists($resp, 'getBody') === true) {
				$body = (string)$resp->getBody();
			}
		}

		return [$status, $body];
	}//end extractErrorContext()

	/**
	 * Heuristic: does the body/message look like a VNG clock-skew fault?
	 *
	 * @param string $text Body or message text.
	 *
	 * @return bool True when "JWT verlopen" or "JWT nog niet geldig" is detected.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public static function looksLikeClockSkew(string $text): bool {
		if ($text === '') {
			return false;
		}

		$needles = ['JWT verlopen', 'JWT nog niet geldig', 'jwt verlopen', 'jwt nog niet geldig'];
		foreach ($needles as $needle) {
			if (stripos($text, $needle) !== false) {
				return true;
			}
		}

		return false;
	}//end looksLikeClockSkew()

	/**
	 * Lowercase header keys for case-insensitive lookup.
	 *
	 * @param array<string, array<int, string>|string> $headers Raw header map.
	 *
	 * @return array<string, string>
	 */
	private static function normaliseHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $key => $value) {
			if (is_array($value) === true) {
				$out[strtolower((string)$key)] = (string)($value[0] ?? '');
				continue;
			}

			$out[strtolower((string)$key)] = (string)$value;
		}

		return $out;
	}//end normaliseHeaders()

	/**
	 * Encode a JWT (HS256). Extracted as a static helper so unit tests can
	 * call it directly without instantiating the whole client.
	 *
	 * @param array<string, mixed> $header JWT header.
	 * @param array<string, mixed> $payload JWT payload.
	 * @param string $secret HS256 secret.
	 *
	 * @return string Compact JWT.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public static function encodeJwt(array $header, array $payload, string $secret): string {
		$segments = [];
		$segments[] = self::base64UrlEncode(input: (string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$segments[] = self::base64UrlEncode(input: (string)json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signing = implode('.', $segments);
		$signature = hash_hmac('sha256', $signing, $secret, true);
		$segments[] = self::base64UrlEncode(input: $signature);
		return implode('.', $segments);
	}//end encodeJwt()

	/**
	 * Decode a JWT segment (header or payload only).
	 *
	 * Exposed for unit tests asserting payload contents.
	 *
	 * @param string $jwt Compact JWT.
	 *
	 * @return array{header: array<string, mixed>, payload: array<string, mixed>}|null
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public static function inspectJwt(string $jwt): ?array {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return null;
		}

		$header = json_decode((string)self::base64UrlDecode(input: $parts[0]), true);
		$payload = json_decode((string)self::base64UrlDecode(input: $parts[1]), true);
		if (is_array($header) === false || is_array($payload) === false) {
			return null;
		}

		return ['header' => $header, 'payload' => $payload];
	}//end inspectJwt()

	/**
	 * Verify a JWT's HS256 signature.
	 *
	 * @param string $jwt Compact JWT.
	 * @param string $secret Shared secret.
	 *
	 * @return bool True when the signature matches.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public static function verifyJwt(string $jwt, string $secret): bool {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return false;
		}

		$signing = $parts[0] . '.' . $parts[1];
		$expected = hash_hmac('sha256', $signing, $secret, true);
		$actual = self::base64UrlDecode(input: $parts[2]);
		return ($actual !== null && hash_equals($expected, $actual) === true);
	}//end verifyJwt()

	/**
	 * Base64-url encode.
	 *
	 * @param string $input Raw bytes.
	 *
	 * @return string URL-safe base64 (no padding).
	 */
	private static function base64UrlEncode(string $input): string {
		return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64-url decode.
	 *
	 * @param string $input URL-safe base64.
	 *
	 * @return string|null Decoded bytes or null on failure.
	 */
	private static function base64UrlDecode(string $input): ?string {
		$padded = $input . str_repeat('=', (4 - (strlen($input) % 4)) % 4);
		$decoded = base64_decode(strtr($padded, '-_', '+/'), true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;
	}//end base64UrlDecode()

	/**
	 * Effective HTTP timeout (seconds).
	 *
	 * @return int
	 */
	private function getTimeout(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.http_timeout', self::DEFAULT_TIMEOUT);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_TIMEOUT;
	}//end getTimeout()
}//end class
