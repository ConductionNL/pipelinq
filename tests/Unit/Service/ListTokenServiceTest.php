<?php

/**
 * Unit tests for ListTokenService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ListTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the signed links a mailing-list subscriber follows.
 *
 * The signing key is held in a real variable rather than a stub that returns a
 * constant, so a token minted in one call verifies in the next exactly as it
 * does in production: a fake that answered every read with the same string
 * would pass even if the service never persisted the key at all.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
 */
class ListTokenServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ListTokenService
	 */
	private ListTokenService $service;

	/**
	 * The clock the service reads, movable per test.
	 *
	 * @var int
	 */
	private int $now = 1750000000;

	/**
	 * The app-config values the service reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Wire the service over an in-memory app config and a movable clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$random = $this->createMock(ISecureRandom::class);
		$counter = 0;
		$random->method('generate')->willReturnCallback(
			function (int $length) use (&$counter): string {
				$counter++;
				return str_pad('r' . $counter, $length, 'x');
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route, array $args = []): string {
				return 'https://crm.example.org/' . $route . '/' . ($args['token'] ?? '');
			}
		);

		$this->service = new ListTokenService(
			appConfig: $appConfig,
			time: $time,
			secureRandom: $random,
			urlGenerator: $urls,
		);
	}//end setUp()

	/**
	 * A confirmation token round-trips with its subscription and nonce.
	 *
	 * @return void
	 */
	public function testConfirmTokenRoundTrip(): void {
		$token = $this->service->signConfirmToken(subscriptionId: 'sub-1', nonce: 'nonce-1');
		$payload = $this->service->verify(token: $token, purpose: ListTokenService::PURPOSE_CONFIRM);

		$this->assertIsArray($payload);
		$this->assertSame('sub-1', $payload['s']);
		$this->assertSame('nonce-1', $payload['n']);
	}//end testConfirmTokenRoundTrip()

	/**
	 * A token edited after signing does not verify.
	 *
	 * @return void
	 */
	public function testTamperedPayloadIsRefused(): void {
		$token = $this->service->signConfirmToken(subscriptionId: 'sub-1', nonce: 'nonce-1');
		[$encoded, $signature] = explode('.', $token);

		$decoded = json_decode((string)base64_decode(strtr($encoded, '-_', '+/'), true), true);
		$decoded['s'] = 'sub-2';
		$forged = rtrim(strtr(base64_encode((string)json_encode($decoded)), '+/', '-_'), '=');

		$this->assertNull(
			$this->service->verify(token: ($forged . '.' . $signature), purpose: ListTokenService::PURPOSE_CONFIRM)
		);
	}//end testTamperedPayloadIsRefused()

	/**
	 * A token minted for one purpose cannot be spent on another.
	 *
	 * This is the property that stops an unsubscribe link, which is printed
	 * into every message and therefore widely held, from being replayed as a
	 * confirmation.
	 *
	 * @return void
	 */
	public function testTokenIsRefusedForAnotherPurpose(): void {
		$token = $this->service->signUnsubscribeToken(subscriptionId: 'sub-1', contactId: 'contact-1');

		$this->assertIsArray(
			$this->service->verify(token: $token, purpose: ListTokenService::PURPOSE_UNSUBSCRIBE)
		);
		$this->assertNull(
			$this->service->verify(token: $token, purpose: ListTokenService::PURPOSE_CONFIRM)
		);
	}//end testTokenIsRefusedForAnotherPurpose()

	/**
	 * A confirmation token stops working after its window.
	 *
	 * @return void
	 */
	public function testExpiredConfirmTokenIsRefused(): void {
		$token = $this->service->signConfirmToken(subscriptionId: 'sub-1', nonce: 'nonce-1');

		$this->now += (8 * 86400);

		$this->assertNull(
			$this->service->verify(token: $token, purpose: ListTokenService::PURPOSE_CONFIRM)
		);
	}//end testExpiredConfirmTokenIsRefused()

	/**
	 * An unsubscribe token outlives a confirmation one, because it sits in a
	 * mail archive and has to keep working years later.
	 *
	 * @return void
	 */
	public function testUnsubscribeTokenOutlivesTheConfirmationWindow(): void {
		$token = $this->service->signUnsubscribeToken(subscriptionId: 'sub-1', contactId: 'contact-1');

		$this->now += (365 * 86400);

		$this->assertIsArray(
			$this->service->verify(token: $token, purpose: ListTokenService::PURPOSE_UNSUBSCRIBE)
		);
	}//end testUnsubscribeTokenOutlivesTheConfirmationWindow()

	/**
	 * A malformed token is refused rather than raising.
	 *
	 * @return void
	 */
	public function testMalformedTokenIsRefused(): void {
		foreach (['', 'nodot', 'a.b.c', '.sig', 'payload.'] as $candidate) {
			$this->assertNull(
				$this->service->verify(token: $candidate, purpose: ListTokenService::PURPOSE_CONFIRM),
				sprintf('"%s" should not verify', $candidate)
			);
		}
	}//end testMalformedTokenIsRefused()

	/**
	 * The digest is a stable SHA-256 of the nonce, and empty for an empty one.
	 *
	 * @return void
	 */
	public function testDigestIsStableAndEmptyForAnEmptyNonce(): void {
		$this->assertSame(hash('sha256', 'nonce-1'), $this->service->digest(nonce: 'nonce-1'));
		$this->assertSame($this->service->digest(nonce: 'n'), $this->service->digest(nonce: 'n'));
		$this->assertSame('', $this->service->digest(nonce: ''));
	}//end testDigestIsStableAndEmptyForAnEmptyNonce()

	/**
	 * The address hash is keyed, so it cannot be reversed by hashing the whole
	 * IPv4 space the way a bare SHA-256 could.
	 *
	 * @return void
	 */
	public function testAddressHashIsKeyedNotPlain(): void {
		$hashed = $this->service->hashAddress(address: '198.51.100.7');

		$this->assertNotSame('', $hashed);
		$this->assertNotSame(hash('sha256', '198.51.100.7'), $hashed);
		$this->assertSame($hashed, $this->service->hashAddress(address: '198.51.100.7'));
		$this->assertSame('', $this->service->hashAddress(address: ''));
	}//end testAddressHashIsKeyedNotPlain()

	/**
	 * The signing key is minted once and reused, not re-minted per call.
	 *
	 * A re-minted key would invalidate every link the previous call issued,
	 * which is invisible in a single-call test and catastrophic in a mailbox.
	 *
	 * @return void
	 */
	public function testSigningKeyIsMintedOnceAndPersisted(): void {
		$first = $this->service->signConfirmToken(subscriptionId: 'sub-1', nonce: 'n');
		$this->assertArrayHasKey('lists.token_secret', $this->config);
		$key = $this->config['lists.token_secret'];

		$this->service->signConfirmToken(subscriptionId: 'sub-2', nonce: 'n');

		$this->assertSame($key, $this->config['lists.token_secret']);
		$this->assertIsArray(
			$this->service->verify(token: $first, purpose: ListTokenService::PURPOSE_CONFIRM)
		);
	}//end testSigningKeyIsMintedOnceAndPersisted()

	/**
	 * Each link builder names its own route, so a confirmation link can never
	 * be built pointing at the unsubscribe endpoint.
	 *
	 * @return void
	 */
	public function testLinkBuildersNameTheirOwnRoutes(): void {
		$this->assertStringContainsString(
			'pipelinq.listPublic.confirm',
			$this->service->confirmUrl(token: 'tok')
		);
		$this->assertStringContainsString(
			'pipelinq.listPublic.unsubscribePage',
			$this->service->unsubscribeUrl(token: 'tok')
		);
		$this->assertStringContainsString(
			'pipelinq.listPublic.preferences',
			$this->service->preferencesUrl(token: 'tok')
		);
	}//end testLinkBuildersNameTheirOwnRoutes()
}//end class
