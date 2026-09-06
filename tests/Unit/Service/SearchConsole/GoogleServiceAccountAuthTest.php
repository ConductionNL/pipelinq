<?php

/**
 * Unit tests for GoogleServiceAccountAuth.
 *
 * Covers:
 * - key parsing accepts a service account and refuses anything else
 * - the assertion carries the RFC 7523 claims and verifies with the public key
 * - the token exchange posts the assertion as a jwt-bearer grant
 * - an answer without a token is an error
 *
 * The private key is generated in the test with openssl, never stored.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use OCA\Pipelinq\Service\SearchConsole\GoogleServiceAccountAuth;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GoogleServiceAccountAuth.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class GoogleServiceAccountAuthTest extends TestCase {

	/**
	 * Fixed clock.
	 *
	 * @var int
	 */
	private const NOW = 1788516000;

	/**
	 * A fresh RSA key pair as PEM.
	 *
	 * @return array{0: string, 1: string} private, public.
	 */
	public static function keyPair(): array {
		$resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertNotFalse($resource);
		$private = '';
		openssl_pkey_export($resource, $private);
		$details = openssl_pkey_get_details($resource);

		return [$private, (string)$details['key']];
	}//end keyPair()

	/**
	 * A service account key JSON around a private key.
	 *
	 * @param string $private The PEM.
	 *
	 * @return string
	 */
	public static function keyJson(string $private): string {
		return (string)json_encode(
			[
				'type' => 'service_account',
				'client_email' => 'pipelinq@example-project.iam.gserviceaccount.com',
				'private_key' => $private,
				'token_uri' => 'https://oauth2.googleapis.com/token',
			]
		);
	}//end keyJson()

	/**
	 * Build the auth helper around a fake HTTP client.
	 *
	 * @param callable|null $onPost Receives (url, options), returns an IResponse.
	 *
	 * @return GoogleServiceAccountAuth
	 */
	private function build(?callable $onPost = null): GoogleServiceAccountAuth {
		$client = $this->createMock(IClient::class);
		if ($onPost !== null) {
			$client->method('post')->willReturnCallback($onPost);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new GoogleServiceAccountAuth($clientService, $time, $this->createMock(LoggerInterface::class));
	}//end build()

	/**
	 * @return void
	 */
	public function testParseKeyAcceptsAServiceAccountAndRefusesTheRest(): void {
		[$private] = self::keyPair();
		$parsed = $this->build()->parseKey(self::keyJson($private));

		$this->assertNotNull($parsed);
		$this->assertSame('pipelinq@example-project.iam.gserviceaccount.com', $parsed['client_email']);
		$this->assertSame('https://oauth2.googleapis.com/token', $parsed['token_uri']);

		$this->assertNull($this->build()->parseKey('not json'));
		$this->assertNull($this->build()->parseKey('{"type":"authorized_user","client_email":"x","private_key":"y"}'));
		$this->assertNull($this->build()->parseKey('{"client_email":"x"}'));

		$noUri = $this->build()->parseKey('{"client_email":"x@y","private_key":"k","token_uri":"http://insecure"}');
		$this->assertSame(GoogleServiceAccountAuth::DEFAULT_TOKEN_URI, $noUri['token_uri']);
	}//end testParseKeyAcceptsAServiceAccountAndRefusesTheRest()

	/**
	 * @return void
	 */
	public function testAssertionCarriesTheClaimsAndVerifiesWithThePublicKey(): void {
		[$private, $public] = self::keyPair();
		$key = $this->build()->parseKey(self::keyJson($private));
		$jwt = $this->build()->buildAssertion(key: $key, scope: 'https://www.googleapis.com/auth/webmasters.readonly');

		$parts = explode('.', $jwt);
		$this->assertCount(3, $parts);
		$decode = static fn (string $part): array => (array)json_decode((string)base64_decode(strtr($part, '-_', '+/')), true);

		$this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $decode($parts[0]));
		$this->assertSame(
			[
				'iss' => 'pipelinq@example-project.iam.gserviceaccount.com',
				'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
				'aud' => 'https://oauth2.googleapis.com/token',
				'iat' => self::NOW,
				'exp' => (self::NOW + 3600),
			],
			$decode($parts[1])
		);

		$signature = base64_decode(strtr($parts[2], '-_', '+/'));
		$this->assertSame(1, openssl_verify(($parts[0] . '.' . $parts[1]), (string)$signature, $public, OPENSSL_ALGO_SHA256));
	}//end testAssertionCarriesTheClaimsAndVerifiesWithThePublicKey()

	/**
	 * @return void
	 */
	public function testAssertionRefusesAKeyThatDoesNotLoad(): void {
		$this->expectException(RuntimeException::class);
		$this->build()->buildAssertion(key: ['client_email' => 'x', 'private_key' => 'garbage', 'token_uri' => 'https://t'], scope: 's');
	}//end testAssertionRefusesAKeyThatDoesNotLoad()

	/**
	 * @return void
	 */
	public function testTokenExchangePostsTheAssertion(): void {
		[$private] = self::keyPair();
		$key = $this->build()->parseKey(self::keyJson($private));
		$captured = [];

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"access_token":"ya29.token","expires_in":3599}');
		$auth = $this->build(
			function (string $url, array $options) use (&$captured, $response): IResponse {
				$captured = ['url' => $url, 'options' => $options];
				return $response;
			}
		);

		$this->assertSame('ya29.token', $auth->accessToken(key: $key, scope: 'scope-x'));
		$this->assertSame('https://oauth2.googleapis.com/token', $captured['url']);
		$this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $captured['options']['body']['grant_type']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $captured['options']['body']['assertion']);
	}//end testTokenExchangePostsTheAssertion()

	/**
	 * @return void
	 */
	public function testAnAnswerWithoutATokenIsAnError(): void {
		[$private] = self::keyPair();
		$key = $this->build()->parseKey(self::keyJson($private));
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"error":"invalid_grant"}');
		$auth = $this->build(static fn (): IResponse => $response);

		$this->expectException(RuntimeException::class);
		$auth->accessToken(key: $key, scope: 's');
	}//end testAnAnswerWithoutATokenIsAnError()
}//end class
