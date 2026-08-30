<?php

/**
 * Unit tests for DocumentSigningService — signed-URL integrity + TTL.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the document signing service.
 */
class DocumentSigningServiceTest extends TestCase {
	/**
	 * "now".
	 *
	 * @var int
	 */
	private int $now = 7000000;

	/**
	 * Build a service with a fixed signing key + controllable clock.
	 *
	 * @return DocumentSigningService The service.
	 */
	private function service(): DocumentSigningService {
		$store = ['portal_document_signing_key' => 'fixed-signing-key-1234567890'];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($store[$key] ?? $default)
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('unused');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new DocumentSigningService($appConfig, $random, $time);
	}//end service()

	/**
	 * A freshly signed token validates and yields the original payload.
	 *
	 * @return void
	 */
	public function testValidTokenRoundTrips(): void {
		$service = $this->service();
		$signed = $service->generateUrl('inv-1', 'invoice', 'acc-1', 5);

		$payload = $service->validateToken($signed['token']);
		$this->assertIsArray($payload);
		$this->assertSame('inv-1', $payload['objectId']);
		$this->assertSame('invoice', $payload['objectType']);
		$this->assertSame('acc-1', $payload['accountId']);
	}//end testValidTokenRoundTrips()

	/**
	 * A tampered token (flipped payload) fails the signature check → null.
	 *
	 * @return void
	 */
	public function testTamperedTokenRejected(): void {
		$service = $this->service();
		$signed = $service->generateUrl('inv-1', 'invoice', 'acc-1', 5);

		[$encoded, $sig] = explode('.', $signed['token']);
		$forged = $encoded . 'x.' . $sig;
		$this->assertNull($service->validateToken($forged));
	}//end testTamperedTokenRejected()

	/**
	 * A token whose signature is replaced is rejected.
	 *
	 * @return void
	 */
	public function testForgedSignatureRejected(): void {
		$service = $this->service();
		$signed = $service->generateUrl('inv-1', 'invoice', 'acc-1', 5);

		[$encoded] = explode('.', $signed['token']);
		$this->assertNull($service->validateToken($encoded . '.deadbeef'));
	}//end testForgedSignatureRejected()

	/**
	 * An expired token returns the 'expired' marker (→ 410), not null.
	 *
	 * @return void
	 */
	public function testExpiredTokenMarked(): void {
		$service = $this->service();
		$signed = $service->generateUrl('inv-1', 'invoice', 'acc-1', 5);

		// Advance past the 5-minute TTL.
		$this->now += (6 * 60);
		$this->assertSame('expired', $service->validateToken($signed['token']));
	}//end testExpiredTokenMarked()

	/**
	 * Garbage with no separator is rejected.
	 *
	 * @return void
	 */
	public function testGarbageTokenRejected(): void {
		$this->assertNull($this->service()->validateToken('not-a-token'));
	}//end testGarbageTokenRejected()
}//end class
