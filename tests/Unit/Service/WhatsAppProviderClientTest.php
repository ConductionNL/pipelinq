<?php

/**
 * Unit tests for WhatsAppProviderClient.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WhatsAppProviderClient.
 */
class WhatsAppProviderClientTest extends TestCase {

	/**
	 * The client under test.
	 *
	 * @var WhatsAppProviderClient
	 */
	private WhatsAppProviderClient $client;

	/**
	 * Build the client.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = new WhatsAppProviderClient(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A signature is accepted with and without its `sha256=` prefix, and refused when it differs.
	 *
	 * @return void
	 */
	public function testVerifySignatureComparesTheDigest(): void {
		$provider = ['webhookSecret' => 'secret'];
		$digest = hash_hmac('sha256', '{"a":1}', 'secret');

		$this->assertTrue($this->client->verifySignature(channelProvider: $provider, rawBody: '{"a":1}', signature: 'sha256=' . $digest));
		$this->assertTrue($this->client->verifySignature(channelProvider: $provider, rawBody: '{"a":1}', signature: $digest));
		$this->assertFalse($this->client->verifySignature(channelProvider: $provider, rawBody: '{"a":2}', signature: $digest));
	}//end testVerifySignatureComparesTheDigest()

	/**
	 * Without a secret or a signature nothing is authentic.
	 *
	 * @return void
	 */
	public function testVerifySignatureRefusesWithoutSecretOrSignature(): void {
		$this->assertFalse($this->client->verifySignature(channelProvider: [], rawBody: 'x', signature: 'sha256=abc'));
		$this->assertFalse($this->client->verifySignature(channelProvider: ['webhookSecret' => 'secret'], rawBody: 'x', signature: ''));
	}//end testVerifySignatureRefusesWithoutSecretOrSignature()

	/**
	 * A free-form send without a configured source is a permanent failure.
	 *
	 * @return void
	 */
	public function testSendFreeFormWithoutSourceIsPermanent(): void {
		$this->expectException(PermanentSmsProviderException::class);

		$this->client->sendFreeForm(channelProvider: [], phoneNumber: '+31600000000', body: 'Hallo', mediaIds: ['m-1']);
	}//end testSendFreeFormWithoutSourceIsPermanent()
}//end class
