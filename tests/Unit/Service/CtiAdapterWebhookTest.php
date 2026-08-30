<?php

/**
 * Integration tests for CTI adapter handleInboundWebhook normalisation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-8.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CTI adapter webhook normalisation and signature checks.
 */
class CtiAdapterWebhookTest extends TestCase {
	/**
	 * Test CallVoip normalises an `answered` payload.
	 *
	 * @return void
	 */
	public function testCallVoipNormalisesAnswered(): void {
		$adapter = $this->makeCallVoip();
		$result = $adapter->handleInboundWebhook(
			[
				'event' => 'answered',
				'callId' => 'call-uuid-1',
				'extension' => '101',
				'from' => '+31612345678',
				'to' => '+31303033000',
				'duration' => 327,
			]
		);

		$this->assertSame('answered', $result->eventType);
		$this->assertSame('call-uuid-1', $result->externalCallId);
		$this->assertSame('101', $result->extension);
		$this->assertSame('+31612345678', $result->fromNumber);
		$this->assertSame('inbound', $result->direction);
		$this->assertSame(327, $result->durationSeconds);
	}//end testCallVoipNormalisesAnswered()

	/**
	 * Test CallVoip recording URL surfaces.
	 *
	 * @return void
	 */
	public function testCallVoipExtractsRecording(): void {
		$adapter = $this->makeCallVoip();
		$result = $adapter->handleInboundWebhook(
			[
				'event' => 'ended',
				'callId' => 'call-1',
				'duration' => 60,
				'recording' => [
					'url' => 'https://x.example/r/1',
					'expiresAt' => '2026-09-01T00:00:00Z',
				],
			]
		);

		$this->assertSame('https://x.example/r/1', $result->recordingUrl);
		$this->assertSame('2026-09-01T00:00:00Z', $result->recordingExpiresAt);
	}//end testCallVoipExtractsRecording()

	/**
	 * Test CallVoip signature verification.
	 *
	 * @return void
	 */
	public function testCallVoipSignatureValid(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('secret-shh');

		$client = $this->createMock(IClientService::class);
		$log = $this->createMock(LoggerInterface::class);

		$adapter = new CallVoipAdapter($appConfig, $client, $log);
		$payload = '{"event":"answered","callId":"abc"}';
		$signature = hash_hmac('sha256', $payload, 'secret-shh');

		$this->assertTrue($adapter->verifyWebhookSignature($payload, $signature));
		$this->assertFalse($adapter->verifyWebhookSignature($payload, 'wrong'));
	}//end testCallVoipSignatureValid()

	/**
	 * Test RingCentral normalises a `Disconnected` party state into `ended`.
	 *
	 * @return void
	 */
	public function testRingCentralNormalisesDisconnected(): void {
		$adapter = $this->makeRingCentral();
		$result = $adapter->handleInboundWebhook(
			[
				'body' => [
					'telephonySessionId' => 'rc-session-1',
					'parties' => [
						[
							'direction' => 'Inbound',
							'status' => ['code' => 'Disconnected'],
							'from' => ['phoneNumber' => '+31612000000'],
							'to' => ['phoneNumber' => '+31303030000'],
						],
					],
				],
			]
		);

		$this->assertSame('ended', $result->eventType);
		$this->assertSame('inbound', $result->direction);
		$this->assertSame('rc-session-1', $result->externalCallId);
	}//end testRingCentralNormalisesDisconnected()

	/**
	 * Test Asterisk Hangup is mapped to `ended`.
	 *
	 * @return void
	 */
	public function testAsteriskNormalisesHangup(): void {
		$adapter = $this->makeAsterisk();
		$result = $adapter->handleInboundWebhook(
			[
				'Event' => 'Hangup',
				'Uniqueid' => 'ast-12345.6',
				'Channel' => 'SIP/101-00001234',
				'CallerIDNum' => '+31612345678',
				'Duration' => '00:05:27',
			]
		);

		$this->assertSame('ended', $result->eventType);
		$this->assertSame('ast-12345.6', $result->externalCallId);
		$this->assertSame('101', $result->extension);
		$this->assertSame((5 * 60) + 27, $result->durationSeconds);
	}//end testAsteriskNormalisesHangup()

	/**
	 * Test Asterisk shared-secret validation.
	 *
	 * @return void
	 */
	public function testAsteriskSharedSecretGate(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('s3cret');

		$client = $this->createMock(IClientService::class);
		$log = $this->createMock(LoggerInterface::class);

		$adapter = new AsteriskAdapter($appConfig, $client, $log);

		$this->assertTrue($adapter->verifyWebhookSignature('any', 's3cret'));
		$this->assertFalse($adapter->verifyWebhookSignature('any', 'wrong'));
	}//end testAsteriskSharedSecretGate()

	/**
	 * Build a CallVoip adapter with stub deps.
	 *
	 * @return CallVoipAdapter
	 */
	private function makeCallVoip(): CallVoipAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$client = $this->createMock(IClientService::class);
		$log = $this->createMock(LoggerInterface::class);
		return new CallVoipAdapter($appConfig, $client, $log);
	}//end makeCallVoip()

	/**
	 * Build a RingCentral adapter with stub deps.
	 *
	 * @return RingCentralAdapter
	 */
	private function makeRingCentral(): RingCentralAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$client = $this->createMock(IClientService::class);
		$log = $this->createMock(LoggerInterface::class);
		return new RingCentralAdapter($appConfig, $client, $log);
	}//end makeRingCentral()

	/**
	 * Build an Asterisk adapter with stub deps.
	 *
	 * @return AsteriskAdapter
	 */
	private function makeAsterisk(): AsteriskAdapter {
		$appConfig = $this->createMock(IAppConfig::class);
		$client = $this->createMock(IClientService::class);
		$log = $this->createMock(LoggerInterface::class);
		return new AsteriskAdapter($appConfig, $client, $log);
	}//end makeAsterisk()
}//end class
