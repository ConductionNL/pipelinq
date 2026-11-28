<?php

/**
 * Unit tests for the vendor-specific send leg of the SMS clients.
 *
 * Pins, per vendor, the exact request each client hands the OpenRegister
 * dispatch leaf (source, payload shape, send path, headers), which key of the
 * answer becomes the external message id, and how a degraded leaf answer is
 * mapped onto the transient / permanent exception contract.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Provider;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\LeafSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Send payloads, message-id keys and degrade mapping per SMS vendor.
 *
 * @covers \OCA\Pipelinq\Service\Provider\TwilioSmsClient
 * @covers \OCA\Pipelinq\Service\Provider\MessageBirdSmsClient
 * @covers \OCA\Pipelinq\Service\Provider\CmComSmsClient
 * @covers \OCA\Pipelinq\Service\Provider\LeafSmsClient
 * @covers \OCA\Pipelinq\Service\Provider\MessageDispatchTrait
 * @uses   \OCA\Pipelinq\Service\Provider\PermanentSmsProviderException
 * @uses   \OCA\Pipelinq\Service\Provider\TransientSmsProviderException
 */
class SmsVendorSendPayloadTest extends TestCase {

	/**
	 * Twilio sends form fields to Messages.json and reads the `sid`.
	 *
	 * @return void
	 */
	public function testTwilioSendsFormFieldsAndReadsSid(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['sid' => 'SM123', 'id' => 'other']];

		$result = $this->client(class: TwilioSmsClient::class, leaf: $leaf, sourceId: 'twilio-sms')
			->send(toNumber: '+31611111111', body: 'Hallo');

		self::assertSame(['externalMessageId' => 'SM123', 'vendor' => 'twilio'], $result);
		self::assertSame(
			[
				[
					'source' => 'twilio-sms',
					'body' => ['From' => '+31600000000', 'To' => '+31611111111', 'Body' => 'Hallo'],
					'path' => 'Messages.json',
					'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
				],
			],
			$leaf->calls
		);
	}//end testTwilioSendsFormFieldsAndReadsSid()

	/**
	 * MessageBird sends originator/recipients without extra headers and reads `id`.
	 *
	 * @return void
	 */
	public function testMessageBirdSendsRecipientsListAndReadsId(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['id' => 'mb-9', 'sid' => 'ignored']];

		$result = $this->client(class: MessageBirdSmsClient::class, leaf: $leaf, sourceId: 'messagebird-sms')
			->send(toNumber: '+31622222222', body: 'Hoi');

		self::assertSame(['externalMessageId' => 'mb-9', 'vendor' => 'messagebird'], $result);
		self::assertSame(
			[
				[
					'source' => 'messagebird-sms',
					'body' => ['originator' => '+31600000000', 'recipients' => ['+31622222222'], 'body' => 'Hoi'],
					'path' => 'messages',
					'headers' => [],
				],
			],
			$leaf->calls
		);
	}//end testMessageBirdSendsRecipientsListAndReadsId()

	/**
	 * CM.com sends from/to-list to `messages` without extra headers.
	 *
	 * @return void
	 */
	public function testCmComSendsToListWithoutHeaders(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['messageId' => 'cm-7']];

		$result = $this->client(class: CmComSmsClient::class, leaf: $leaf, sourceId: 'cm-sms')
			->send(toNumber: '+31633333333', body: 'Dag');

		self::assertSame(['externalMessageId' => 'cm-7', 'vendor' => 'cm-com'], $result);
		self::assertSame(
			[
				[
					'source' => 'cm-sms',
					'body' => ['from' => '+31600000000', 'to' => ['+31633333333'], 'body' => 'Dag'],
					'path' => 'messages',
					'headers' => [],
				],
			],
			$leaf->calls
		);
	}//end testCmComSendsToListWithoutHeaders()

	/**
	 * Without the vendor id key the generic `externalMessageId` is used,
	 * and without either the id is empty.
	 *
	 * @return void
	 */
	public function testMessageIdFallsBackToExternalMessageIdThenEmpty(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['externalMessageId' => 'ext-1']];
		$fallback = $this->client(class: TwilioSmsClient::class, leaf: $leaf, sourceId: 'twilio-sms')
			->send(toNumber: '+31611111111', body: 'x');
		self::assertSame('ext-1', $fallback['externalMessageId']);

		$empty = new MessageDispatchProvider();
		$empty->nextResult = ['status' => 'sent', 'response' => ['other' => 'z']];
		$none = $this->client(class: MessageBirdSmsClient::class, leaf: $empty, sourceId: 'messagebird-sms')
			->send(toNumber: '+31611111111', body: 'x');
		self::assertSame('', $none['externalMessageId']);
	}//end testMessageIdFallsBackToExternalMessageIdThenEmpty()

	/**
	 * A numeric vendor id is returned as a string.
	 *
	 * @return void
	 */
	public function testNumericMessageIdIsCastToString(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['id' => 42]];

		$result = $this->client(class: MessageBirdSmsClient::class, leaf: $leaf, sourceId: 'messagebird-sms')
			->send(toNumber: '+31611111111', body: 'x');

		self::assertSame('42', $result['externalMessageId']);
	}//end testNumericMessageIdIsCastToString()

	/**
	 * Permanent degrade causes become a permanent provider exception and are logged.
	 *
	 * @return void
	 */
	public function testPermanentDegradeCausesThrowPermanent(): void {
		foreach (['openconnector-source-missing', 'provider-auth'] as $cause) {
			$leaf = new MessageDispatchProvider();
			$leaf->nextResult = ['unavailable' => true, 'cause' => $cause];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())
				->method('warning')
				->with('MessageDispatchTrait: dispatch degraded', ['cause' => $cause]);

			try {
				$this->client(class: TwilioSmsClient::class, leaf: $leaf, sourceId: 'twilio-sms', logger: $logger)
					->send(toNumber: '+31611111111', body: 'x');
				self::fail('Expected a permanent provider exception for ' . $cause);
			} catch (PermanentSmsProviderException $e) {
				self::assertSame('messaging source unavailable: ' . $cause, $e->getMessage());
			}
		}
	}//end testPermanentDegradeCausesThrowPermanent()

	/**
	 * Every other degrade cause, including a missing one, is transient.
	 *
	 * @return void
	 */
	public function testOtherDegradeCausesThrowTransient(): void {
		$cases = [
			'openconnector-down' => ['unavailable' => true, 'cause' => 'openconnector-down'],
			'upstream-service-down' => ['unavailable' => true, 'cause' => 'upstream-service-down'],
			'' => ['unavailable' => true],
		];
		foreach ($cases as $cause => $answer) {
			$leaf = new MessageDispatchProvider();
			$leaf->nextResult = $answer;

			try {
				$this->client(class: MessageBirdSmsClient::class, leaf: $leaf, sourceId: 'messagebird-sms')
					->send(toNumber: '+31611111111', body: 'x');
				self::fail('Expected a transient provider exception for "' . $cause . '"');
			} catch (TransientSmsProviderException $e) {
				self::assertSame('messaging source unavailable: ' . $cause, $e->getMessage());
			}
		}
	}//end testOtherDegradeCausesThrowTransient()

	/**
	 * An `unavailable` flag that is not strictly true is not a degrade.
	 *
	 * @return void
	 */
	public function testTruthyNonBooleanUnavailableIsNotADegrade(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['unavailable' => 1, 'cause' => 'provider-auth', 'response' => ['sid' => 'SM1']];

		$result = $this->client(class: TwilioSmsClient::class, leaf: $leaf, sourceId: 'twilio-sms')
			->send(toNumber: '+31611111111', body: 'x');

		self::assertSame('SM1', $result['externalMessageId']);
	}//end testTruthyNonBooleanUnavailableIsNotADegrade()

	/**
	 * Without a source id nothing is dispatched and the error is permanent.
	 *
	 * @return void
	 */
	public function testMissingSourceIdIsPermanentWithoutDispatch(): void {
		foreach ([null, ''] as $sourceId) {
			$container = $this->createMock(ContainerInterface::class);
			$container->expects($this->never())->method('get');
			$client = new TwilioSmsClient(
				container: $container,
				logger: $this->createMock(LoggerInterface::class),
				credentials: [],
				fromNumber: '+31600000000',
				webhookSecret: 's',
				sourceId: $sourceId,
			);

			try {
				$client->send(toNumber: '+31611111111', body: 'x');
				self::fail('Expected a permanent provider exception.');
			} catch (PermanentSmsProviderException $e) {
				self::assertSame('messaging source slug not configured', $e->getMessage());
			}
		}
	}//end testMissingSourceIdIsPermanentWithoutDispatch()

	/**
	 * Build a vendor client wired to the given leaf.
	 *
	 * @param class-string<LeafSmsClient> $class    The client class.
	 * @param object                      $leaf     The dispatch leaf.
	 * @param string                      $sourceId The source slug.
	 * @param LoggerInterface|null        $logger   Optional logger.
	 *
	 * @return LeafSmsClient
	 */
	private function client(string $class, object $leaf, string $sourceId, ?LoggerInterface $logger = null): LeafSmsClient {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(MessageDispatchProvider::class)
			->willReturn($leaf);

		return new $class(
			container: $container,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: $sourceId,
		);
	}//end client()
}//end class
