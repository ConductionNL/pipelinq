<?php

/**
 * Pipelinq GoogleServiceAccountAuth.
 *
 * Turns a Google service account key (the JSON file the admin downloads
 * from the Cloud console) into a short-lived access token, the way RFC
 * 7523 describes it: an RS256-signed JWT assertion posted to the key's
 * token endpoint. No OAuth consent flow, no refresh token, nothing stored
 * beyond the key itself. The admin makes this work by adding the service
 * account's email address as a user on the Search Console property.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * GoogleServiceAccountAuth: key parsing, assertion signing, token exchange.
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class GoogleServiceAccountAuth {

	/**
	 * Token endpoint used when the key does not name one.
	 *
	 * @var string
	 */
	public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * Lifetime of an assertion in seconds (Google accepts at most 3600).
	 *
	 * @var int
	 */
	public const ASSERTION_TTL = 3600;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param ITimeFactory $time Time factory for `iat`/`exp`.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function __construct(
		private IClientService $clientService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse and validate a service account key JSON.
	 *
	 * @param string $json The key file contents.
	 *
	 * @return array<string, string>|null `client_email`, `private_key`, `token_uri`, or null when unusable.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function parseKey(string $json): ?array {
		$decoded = json_decode(trim($json), true);
		if (is_array($decoded) === false) {
			return null;
		}

		$email = (string)($decoded['client_email'] ?? '');
		$privateKey = (string)($decoded['private_key'] ?? '');
		if ($email === '' || $privateKey === '' || (string)($decoded['type'] ?? 'service_account') !== 'service_account') {
			return null;
		}

		return ['client_email' => $email, 'private_key' => $privateKey, 'token_uri' => $this->tokenUriOf(key: $decoded)];
	}//end parseKey()

	/**
	 * The key's token endpoint when it is an https URL; Google's default otherwise.
	 *
	 * @param array<string, mixed> $key The decoded key.
	 *
	 * @return string
	 */
	private function tokenUriOf(array $key): string {
		$tokenUri = (string)($key['token_uri'] ?? '');
		if ($tokenUri === '' || preg_match('#^https://#', $tokenUri) !== 1) {
			return self::DEFAULT_TOKEN_URI;
		}

		return $tokenUri;
	}//end tokenUriOf()

	/**
	 * Build the RS256 JWT assertion for a key and scope.
	 *
	 * @param array<string, string> $key A parsed key (see `parseKey()`).
	 * @param string $scope The OAuth scope, space-separated when several.
	 * @param int|null $now Issue time; defaults to the time factory.
	 *
	 * @return string The compact JWT.
	 *
	 * @throws RuntimeException When the private key does not load or signing fails.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function buildAssertion(array $key, string $scope, ?int $now = null): string {
		$issuedAt = ($now ?? $this->time->getTime());
		$header = self::base64Url(data: (string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims = ['iss' => $key['client_email'], 'scope' => $scope, 'aud' => $key['token_uri']];
		$claims += ['iat' => $issuedAt, 'exp' => ($issuedAt + self::ASSERTION_TTL)];
		$signingInput = ($header . '.' . self::base64Url(data: (string)json_encode($claims)));

		return ($signingInput . '.' . self::base64Url(data: $this->signature(signingInput: $signingInput, privateKeyPem: $key['private_key'])));
	}//end buildAssertion()

	/**
	 * The RS256 signature of the signing input with the service account's key.
	 *
	 * @param string $signingInput  The header and claims.
	 * @param string $privateKeyPem The private key.
	 *
	 * @return string The raw signature.
	 *
	 * @throws RuntimeException When the key does not load or signing fails.
	 */
	private function signature(string $signingInput, string $privateKeyPem): string {
		$privateKey = openssl_pkey_get_private($privateKeyPem);
		if ($privateKey === false) {
			throw new RuntimeException('Search Console: the service account private key does not load');
		}

		$signature = '';
		$signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
		if ($signed === false || $signature === '') {
			throw new RuntimeException('Search Console: signing the assertion failed');
		}

		return $signature;
	}//end signature()

	/**
	 * Exchange an assertion for an access token.
	 *
	 * @param array<string, string> $key A parsed key.
	 * @param string $scope The OAuth scope.
	 *
	 * @return string The bearer token.
	 *
	 * @throws RuntimeException When Google refuses or answers without a token.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function accessToken(array $key, string $scope): string {
		$assertion = $this->buildAssertion(key: $key, scope: $scope);

		return $this->tokenIn(body: $this->exchange(tokenUri: $key['token_uri'], assertion: $assertion)->getBody());
	}//end accessToken()

	/**
	 * Post the assertion to the token endpoint; a transport failure is logged
	 * and rethrown with its cause.
	 *
	 * @param string $tokenUri  The token endpoint.
	 * @param string $assertion The signed assertion.
	 *
	 * @return IResponse
	 *
	 * @throws RuntimeException When the exchange fails.
	 */
	private function exchange(string $tokenUri, string $assertion): IResponse {
		try {
			return $this->clientService->newClient()->post(
				$tokenUri,
				[
					'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
					'timeout' => 20,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Search Console: token exchange failed', ['exception' => $e->getMessage()]);
			throw new RuntimeException('Search Console: token exchange failed', 0, $e);
		}
	}//end exchange()

	/**
	 * The access token in the endpoint's answer.
	 *
	 * @param mixed $body The answer's body.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the answer carries no access token.
	 */
	private function tokenIn($body): string {
		$decoded = json_decode((string)$body, true);
		$token = '';
		if (is_array($decoded) === true) {
			$token = (string)($decoded['access_token'] ?? '');
		}

		if ($token === '') {
			throw new RuntimeException('Search Console: token exchange answered without an access token');
		}

		return $token;
	}//end tokenIn()

	/**
	 * Base64url-encode (RFC 4648 section 5, no padding).
	 *
	 * @param string $data The raw data.
	 *
	 * @return string The encoded string.
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public static function base64Url(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64Url()
}//end class
