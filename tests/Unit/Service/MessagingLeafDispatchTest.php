<?php

/**
 * Unit tests for the messaging transport re-point onto the OpenRegister
 * MessageDispatchProvider leaf (pipelinq-messaging-via-or-leaf).
 *
 * Covers all four outbound messaging clients (Twilio, MessageBird, CM.com,
 * WhatsApp): a send routes through the leaf with the right source slug +
 * vendor-shaped body + path, the provider message id is read from the leaf
 * `response`, a degraded `{ unavailable, cause }` result maps to the same
 * Transient/Permanent provider-exception the adapter failover relies on, and
 * a container-miss (leaf not deployed) degrades to the same permanent error
 * as before the re-point.
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
 * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Messaging transport-leg re-point coverage.
 *
 * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#4.1
 */
class MessagingLeafDispatchTest extends TestCase {
	/**
	 * The shared dispatch-leaf stub.
	 *
	 * @var MessageDispatchProvider
	 */
	private MessageDispatchProvider $leaf;

	/**
	 * A container that resolves the leaf class to the stub.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Build a leaf stub + a container wired to it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->leaf = new MessageDispatchProvider();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === MessageDispatchProvider::class) {
					return $this->leaf;
				}

				throw new \RuntimeException('not found: ' . $id);
			}
		);
	}//end setUp()

	/**
	 * Twilio send routes through the leaf with the right source/body/path,
	 * and reads the sid from the leaf response.
	 *
	 * @return void
	 */
	public function testTwilioSendRoutesThroughLeaf(): void {
		$this->leaf->nextResult = [
			'status' => 'sent',
			'source' => 'twilio-sms',
			'response' => ['sid' => 'SM123'],
		];

		$client = new TwilioSmsClient(
			container: $this->container,
			logger: $this->logger,
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: 'twilio-sms',
		);

		$result = $client->send(toNumber: '+31611111111', body: 'hi');

		$this->assertSame('SM123', $result['externalMessageId']);
		$this->assertSame('twilio', $result['vendor']);
		$this->assertCount(1, $this->leaf->calls);
		$call = $this->leaf->calls[0];
		$this->assertSame('twilio-sms', $call['source']);
		$this->assertSame('Messages.json', $call['path']);
		$this->assertSame('+31600000000', $call['body']['From']);
		$this->assertSame('+31611111111', $call['body']['To']);
		$this->assertSame('hi', $call['body']['Body']);
	}//end testTwilioSendRoutesThroughLeaf()

	/**
	 * MessageBird send routes through the leaf and reads the id.
	 *
	 * @return void
	 */
	public function testMessageBirdSendRoutesThroughLeaf(): void {
		$this->leaf->nextResult = [
			'status' => 'sent',
			'response' => ['id' => 'mb-9'],
		];

		$client = new MessageBirdSmsClient(
			container: $this->container,
			logger: $this->logger,
			credentials: [],
			fromNumber: 'Sender',
			webhookSecret: 's',
			sourceId: 'messagebird-sms',
		);

		$result = $client->send(toNumber: '+31611111111', body: 'hi');

		$this->assertSame('mb-9', $result['externalMessageId']);
		$this->assertSame('messagebird-sms', $this->leaf->calls[0]['source']);
		$this->assertSame('messages', $this->leaf->calls[0]['path']);
		$this->assertSame(['+31611111111'], $this->leaf->calls[0]['body']['recipients']);
	}//end testMessageBirdSendRoutesThroughLeaf()

	/**
	 * CM.com send routes through the leaf and reads the messageId.
	 *
	 * @return void
	 */
	public function testCmComSendRoutesThroughLeaf(): void {
		$this->leaf->nextResult = [
			'status' => 'sent',
			'response' => ['messageId' => 'cm-7'],
		];

		$client = new CmComSmsClient(
			container: $this->container,
			logger: $this->logger,
			credentials: [],
			fromNumber: 'Acct',
			webhookSecret: 's',
			sourceId: 'cmcom-sms',
		);

		$result = $client->send(toNumber: '+31611111111', body: 'hi');

		$this->assertSame('cm-7', $result['externalMessageId']);
		$this->assertSame('cmcom-sms', $this->leaf->calls[0]['source']);
		$this->assertSame('messages', $this->leaf->calls[0]['path']);
	}//end testCmComSendRoutesThroughLeaf()

	/**
	 * WhatsApp template send routes through the leaf and extracts the wamid.
	 *
	 * @return void
	 */
	public function testWhatsAppTemplateRoutesThroughLeaf(): void {
		$this->leaf->nextResult = [
			'status' => 'sent',
			'response' => ['messages' => [['id' => 'wamid.ABC']]],
		];

		$client = new WhatsAppProviderClient($this->container, $this->logger);

		$result = $client->sendTemplate(
			channelProvider: ['sourceId' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
			phoneNumber: '+31611111111',
			templateName: 'welcome',
			language: 'nl',
			parameters: ['Jan'],
		);

		$this->assertSame('wamid.ABC', $result['externalMessageId']);
		$this->assertSame('whatsapp-cloud-api', $this->leaf->calls[0]['source']);
		$this->assertSame('messages', $this->leaf->calls[0]['path']);
		$this->assertSame('whatsapp', $this->leaf->calls[0]['body']['messaging_product']);
		$this->assertSame('template', $this->leaf->calls[0]['body']['type']);
	}//end testWhatsAppTemplateRoutesThroughLeaf()

	/**
	 * A source-missing degrade maps to a permanent error (no failover).
	 *
	 * @return void
	 */
	public function testSourceMissingDegradeIsPermanent(): void {
		$this->leaf->nextResult = ['unavailable' => true, 'cause' => 'openconnector-source-missing'];

		$client = $this->makeTwilio();

		$this->expectException(PermanentSmsProviderException::class);
		$client->send(toNumber: '+31611111111', body: 'hi');
	}//end testSourceMissingDegradeIsPermanent()

	/**
	 * A provider-auth degrade maps to a permanent error (no failover).
	 *
	 * @return void
	 */
	public function testProviderAuthDegradeIsPermanent(): void {
		$this->leaf->nextResult = ['unavailable' => true, 'cause' => 'provider-auth'];

		$this->expectException(PermanentSmsProviderException::class);
		$this->makeTwilio()->send(toNumber: '+31611111111', body: 'hi');
	}//end testProviderAuthDegradeIsPermanent()

	/**
	 * An upstream-down degrade maps to a transient error (failover).
	 *
	 * @return void
	 */
	public function testUpstreamDownDegradeIsTransient(): void {
		$this->leaf->nextResult = ['unavailable' => true, 'cause' => 'upstream-service-down'];

		$this->expectException(TransientSmsProviderException::class);
		$this->makeTwilio()->send(toNumber: '+31611111111', body: 'hi');
	}//end testUpstreamDownDegradeIsTransient()

	/**
	 * An openconnector-down degrade maps to a transient error (failover).
	 *
	 * @return void
	 */
	public function testOpenConnectorDownDegradeIsTransient(): void {
		$this->leaf->nextResult = ['unavailable' => true, 'cause' => 'openconnector-down'];

		$this->expectException(TransientSmsProviderException::class);
		$this->makeTwilio()->send(toNumber: '+31611111111', body: 'hi');
	}//end testOpenConnectorDownDegradeIsTransient()

	/**
	 * An unknown degrade cause maps to a transient error (safe failover).
	 *
	 * @return void
	 */
	public function testUnknownCauseDegradeIsTransient(): void {
		$this->leaf->nextResult = ['unavailable' => true, 'cause' => 'something-new'];

		$this->expectException(TransientSmsProviderException::class);
		$this->makeTwilio()->send(toNumber: '+31611111111', body: 'hi');
	}//end testUnknownCauseDegradeIsTransient()

	/**
	 * When the leaf is not resolvable (container miss / not deployed) the
	 * client raises the same permanent error as today's missing-transport
	 * guard, so the failover loop is unchanged.
	 *
	 * @return void
	 */
	public function testLeafAbsentIsPermanent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no leaf'));

		$client = new TwilioSmsClient(
			container: $container,
			logger: $this->logger,
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: 'twilio-sms',
		);

		$this->expectException(PermanentSmsProviderException::class);
		$client->send(toNumber: '+31611111111', body: 'hi');
	}//end testLeafAbsentIsPermanent()

	/**
	 * An unconfigured source slug raises a permanent error before any leaf
	 * call (no failover, matching today's "source not configured").
	 *
	 * @return void
	 */
	public function testEmptySourceIsPermanent(): void {
		$client = new TwilioSmsClient(
			container: $this->container,
			logger: $this->logger,
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: null,
		);

		$this->expectException(PermanentSmsProviderException::class);
		$client->send(toNumber: '+31611111111', body: 'hi');
	}//end testEmptySourceIsPermanent()

	/**
	 * Build a Twilio client wired to the shared container + leaf.
	 *
	 * @return TwilioSmsClient
	 */
	private function makeTwilio(): TwilioSmsClient {
		return new TwilioSmsClient(
			container: $this->container,
			logger: $this->logger,
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: 'twilio-sms',
		);
	}//end makeTwilio()
}//end class
