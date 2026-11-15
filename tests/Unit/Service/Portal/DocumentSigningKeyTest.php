<?php

/**
 * Unit tests for DocumentSigningService key handling and payload checks.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * The signing key is generated and stored once when absent, and a correctly
 * signed token with an unusable payload is refused.
 *
 * @covers \OCA\Pipelinq\Service\Portal\DocumentSigningService
 */
class DocumentSigningKeyTest extends TestCase {
	/**
	 * Base64url without padding, as the service encodes.
	 *
	 * @param string $data The raw bytes.
	 *
	 * @return string The encoded value.
	 */
	private static function b64(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A missing key is generated (64 alphanumerics), stored as sensitive and used to sign.
	 *
	 * @return void
	 */
	public function testMissingKeyIsGeneratedAndStored(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('pipelinq', $this->anything(), '')->willReturn('');
		$appConfig->expects($this->once())->method('setValueString')
			->with('pipelinq', $this->isType('string'), 'generated-key', false, true);

		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->once())->method('generate')
			->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('generated-key');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		$service = new DocumentSigningService($appConfig, $random, $time);
		$token   = $service->generateUrl('o-1', 'invoice', 'a-1', 5)['token'];

		[$encoded, $signature] = explode('.', $token);
		$this->assertSame(self::b64(hash_hmac('sha256', $encoded, 'generated-key', true)), $signature);
	}//end testMissingKeyIsGeneratedAndStored()

	/**
	 * A validly signed token whose payload is not an object with expiresAt is refused.
	 *
	 * @return void
	 */
	public function testSignedTokenWithoutExpiryIsRefused(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('k');
		$appConfig->expects($this->never())->method('setValueString');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		$service = new DocumentSigningService($appConfig, $this->createMock(ISecureRandom::class), $time);

		foreach (['{"objectId":"o-1"}', '"just a string"', 'not json'] as $payload) {
			$encoded = self::b64($payload);
			$token   = $encoded . '.' . self::b64(hash_hmac('sha256', $encoded, 'k', true));
			$this->assertNull($service->validateToken($token), $payload);
		}
	}//end testSignedTokenWithoutExpiryIsRefused()
}//end class
