<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use OCA\Pipelinq\Service\Berichtenbox\MessageOptions;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCA\Pipelinq\Service\TemplateRenderer;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths and branches of the Berichtenbox state machine: the dispatch
 * pass, the 5-working-day fallback pass, inbound-reply failure handling,
 * crypto-shredding, retry exhaustion and template lookup.
 *
 * @covers \OCA\Pipelinq\Service\BerichtenboxService
 * @uses   \OCA\Pipelinq\Service\EncryptionService
 * @uses   \OCA\Pipelinq\Service\TemplateRenderer
 * @uses   \OCA\Pipelinq\Service\DutchHolidayCalendar
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\MessageOptions
 * @uses   \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Tests the orchestrator.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One test per branch.
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RETRY-012
 */
class BerichtenboxServiceCoverageTest extends TestCase {

	/**
	 * Payloads handed to saveObject, in order, with their schema.
	 *
	 * @var array<int, array{schema: mixed, object: array}>
	 */
	private array $saves = [];

	/**
	 * The default app config.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG = [
		'register' => 'reg-1',
		'berichtenboxMessage_schema' => 'sch-msg',
		'berichtenboxReply_schema' => 'sch-reply',
		'mailboxResolution_schema' => 'sch-mr',
		'tenant_id' => 'tenant-a',
		'pki_cert' => '--cert--',
		'pki_key' => '--key--',
	];

	private ObjectServiceInterface&MockObject $objects;
	private MailboxResolver&MockObject $resolver;
	private LogiusConnector&MockObject $logius;
	private EmailFallbackSender&MockObject $email;
	private DeliveryAuditLogger&MockObject $audit;
	private TicketService&MockObject $tickets;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saves = [];
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->resolver = $this->createMock(MailboxResolver::class);
		$this->logius = $this->createMock(LogiusConnector::class);
		$this->email = $this->createMock(EmailFallbackSender::class);
		$this->audit = $this->createMock(DeliveryAuditLogger::class);
		$this->audit->method('hashPayload')->willReturn('hash');
		$this->tickets = $this->createMock(TicketService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Make saveObject record each payload and hand back an entity.
	 *
	 * @param string|null $failOnSchema Schema whose save throws.
	 *
	 * @return void
	 */
	private function captureSaves(?string $failOnSchema = null): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use ($failOnSchema): ObjectEntityInterface {
				if ($failOnSchema !== null && $schema === $failOnSchema) {
					throw new RuntimeException('save refused');
				}

				$this->saves[] = ['schema' => $schema, 'object' => $object];
				return self::entity($object);
			}
		);
	}//end captureSaves()

	/**
	 * Wrap a payload as an OpenRegister entity.
	 *
	 * @param array $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A real encryption service with a fixed secret.
	 *
	 * @return EncryptionService
	 */
	private function encryption(): EncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('coverage-test-secret');
		return new EncryptionService($config, $this->createMock(LoggerInterface::class));
	}//end encryption()

	/**
	 * Build the service over the doubles.
	 *
	 * @param array<string, string> $overrides App config overrides.
	 *
	 * @return BerichtenboxService
	 */
	private function service(array $overrides = []): BerichtenboxService {
		$config = array_merge(self::CONFIG, $overrides);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new BerichtenboxService(
			$this->container,
			$appConfig,
			$this->encryption(),
			new TemplateRenderer($this->createMock(LoggerInterface::class)),
			$this->resolver,
			$this->logius,
			$this->email,
			$this->audit,
			new DutchHolidayCalendar(),
			$this->tickets,
			$this->logger,
			$this->objects,
		);
	}//end service()

	/**
	 * The saved payloads for one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array>
	 */
	private function savedFor(string $schema): array {
		return array_values(
			array_map(
				static fn (array $s): array => $s['object'],
				array_filter($this->saves, static fn (array $s): bool => $s['schema'] === $schema)
			)
		);
	}//end savedFor()

	/**
	 * The dispatch pass skips back-off rows and non-objects, treats an
	 * unparseable nextRetryAt as due, and survives a failing dispatch.
	 *
	 * @return void
	 */
	public function testDispatchQueuedMessagesFiltersAndSurvivesFailures(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with([
				'filters' => ['register' => 'reg-1', 'schema' => 'sch-msg', 'deliveryStatus' => 'queued'],
				'limit' => 7,
			])
			->willReturn([
				['uuid' => 'later', 'nextRetryAt' => '2999-01-01T00:00:00+00:00'],
				'not-an-object',
				['uuid' => 'garbled', 'nextRetryAt' => 'not a date at all'],
				self::entity(['uuid' => 'due', 'nextRetryAt' => '2000-01-01T00:00:00+00:00']),
			]);
		$this->resolver->expects($this->exactly(2))->method('resolve')
			->willThrowException(new RuntimeException('resolver down'));
		$this->logger->expects($this->exactly(2))->method('error')
			->with(
				'BerichtenboxService dispatch failed.',
				$this->callback(static fn (array $ctx): bool => $ctx['exception'] === 'resolver down'
					&& in_array($ctx['messageId'], ['garbled', 'due'], true))
			);

		$this->assertSame(2, $this->service()->dispatchQueuedMessages(limit: 7));
	}//end testDispatchQueuedMessagesFiltersAndSurvivesFailures()

	/**
	 * A failing lookup makes the dispatch pass a no-op.
	 *
	 * @return void
	 */
	public function testDispatchQueuedMessagesLookupFailureProcessesNothing(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('BerichtenboxService findDispatchable failed.', ['exception' => 'db gone']);
		$this->resolver->expects($this->never())->method('resolve');

		$this->assertSame(0, $this->service()->dispatchQueuedMessages());
	}//end testDispatchQueuedMessagesLookupFailureProcessesNothing()

	/**
	 * Without a message register/schema the pass refuses to run.
	 *
	 * @return void
	 */
	public function testDispatchQueuedMessagesRequiresMessageSchema(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('BerichtenboxMessage register or schema not configured.');

		$this->service(['berichtenboxMessage_schema' => ''])->dispatchQueuedMessages();
	}//end testDispatchQueuedMessagesRequiresMessageSchema()

	/**
	 * A row without a BSN dispatches with an empty BSN; Logius failure at
	 * the retry cap hard-fails the message.
	 *
	 * @return void
	 */
	public function testDispatchOneHardFailsAfterMaxRetries(): void {
		$this->captureSaves();
		$this->resolver->expects($this->once())->method('resolve')->with('', 'tenant-a')
			->willReturn(['mailboxAvailable' => true, 'optedOut' => false]);
		$this->logius->method('sendMessage')->willThrowException(new RuntimeException('logius 503'));
		$this->audit->expects($this->once())->method('logFailed')
			->with('msg-5', 'logius 503', 'hash');

		$this->service()->dispatchOne(['uuid' => 'msg-5', 'body' => 'b', 'retryCount' => 5]);

		$saved = $this->savedFor('sch-msg');
		$this->assertCount(1, $saved);
		$this->assertSame('failed', $saved[0]['deliveryStatus']);
		$this->assertSame('logius 503', $saved[0]['failureReason']);
		$this->assertSame(5, $saved[0]['retryCount']);
	}//end testDispatchOneHardFailsAfterMaxRetries()

	/**
	 * Logius failure below the cap re-queues with the backoff for that attempt.
	 *
	 * @return void
	 */
	public function testDispatchOneRequeuesWithBackoff(): void {
		$this->captureSaves();
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => false]);
		$this->logius->method('sendMessage')->willThrowException(new RuntimeException('timeout'));
		$this->audit->expects($this->once())->method('logFailed')
			->with('msg-r', 'timeout (retry 3/5)', 'hash');

		$before = time();
		$this->service()->dispatchOne(['uuid' => 'msg-r', 'retryCount' => 2]);

		$saved = $this->savedFor('sch-msg')[0];
		$this->assertSame('queued', $saved['deliveryStatus']);
		$this->assertSame(3, $saved['retryCount']);
		$delay = strtotime($saved['nextRetryAt']) - $before;
		$this->assertGreaterThanOrEqual(899, $delay);
		$this->assertLessThanOrEqual(905, $delay);
	}//end testDispatchOneRequeuesWithBackoff()

	/**
	 * An opted-out burger without an email is marked opted-out.
	 *
	 * @return void
	 */
	public function testDispatchOneOptedOutWithoutEmailMarksOptedOut(): void {
		$this->captureSaves();
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => true]);
		$this->logius->expects($this->never())->method('sendMessage');
		$this->email->expects($this->never())->method('send');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'Berichtenbox fallback skipped: no email available.',
				['messageId' => 'msg-o', 'reason' => 'opted-out']
			);
		$this->audit->expects($this->once())->method('logOptedOut')->with('msg-o', 'hash');

		$this->service()->dispatchOne(['uuid' => 'msg-o']);

		$this->assertSame('opted-out', $this->savedFor('sch-msg')[0]['deliveryStatus']);
	}//end testDispatchOneOptedOutWithoutEmailMarksOptedOut()

	/**
	 * No mailbox and no email: logged, nothing saved.
	 *
	 * @return void
	 */
	public function testDispatchOneNoMailboxWithoutEmailSavesNothing(): void {
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => false, 'optedOut' => false]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->audit->expects($this->never())->method('logOptedOut');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'Berichtenbox fallback skipped: no email available.',
				['messageId' => 'msg-n', 'reason' => 'no-mailbox']
			);

		$this->service()->dispatchOne(['uuid' => 'msg-n']);
	}//end testDispatchOneNoMailboxWithoutEmailSavesNothing()

	/**
	 * Opted-out with an email: the fallback is sent and audited as opted-out.
	 *
	 * @return void
	 */
	public function testDispatchOneOptedOutWithEmailSendsFallback(): void {
		$this->captureSaves();
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => true]);
		$this->email->expects($this->once())->method('send')
			->with($this->arrayHasKey('burgerEmail'), 'b@example.nl', false)
			->willReturn(true);
		$this->audit->expects($this->once())->method('logFallback')->with('msg-e', 'opted-out', 'hash');

		$this->service()->dispatchOne(['uuid' => 'msg-e', 'burgerEmail' => 'b@example.nl']);

		$saved = $this->savedFor('sch-msg')[0];
		$this->assertSame('fallback-emailed', $saved['deliveryStatus']);
		$this->assertSame('b@example.nl', $saved['fallbackEmail']);
	}//end testDispatchOneOptedOutWithEmailSendsFallback()

	/**
	 * A fallback refused by the sender leaves the message untouched.
	 *
	 * @return void
	 */
	public function testDispatchOneFallbackRefusedLeavesStatus(): void {
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => false, 'optedOut' => false]);
		$this->email->method('send')->willReturn(false);
		$this->email->expects($this->once())->method('lastRefusal')->willReturn(['reason' => 'opt-out']);
		$this->objects->expects($this->never())->method('saveObject');
		$this->audit->expects($this->never())->method('logFallback');

		$this->service()->dispatchOne(['uuid' => 'msg-x', 'burgerEmail' => 'b@example.nl']);
	}//end testDispatchOneFallbackRefusedLeavesStatus()

	/**
	 * A throwing fallback send goes to the retry path.
	 *
	 * @return void
	 */
	public function testDispatchOneFallbackFailureRetries(): void {
		$this->captureSaves();
		$this->resolver->method('resolve')->willReturn(['mailboxAvailable' => false, 'optedOut' => false]);
		$this->email->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->audit->expects($this->once())->method('logFailed')
			->with('msg-s', 'smtp down (retry 1/5)', 'hash');

		$this->service()->dispatchOne(['uuid' => 'msg-s', 'burgerEmail' => 'b@example.nl']);

		$this->assertSame(1, $this->savedFor('sch-msg')[0]['retryCount']);
	}//end testDispatchOneFallbackFailureRetries()

	/**
	 * A read receipt for an unknown Logius id is refused with a warning.
	 *
	 * @return void
	 */
	public function testHandleReadReceiptUnknownMessage(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with([
				'filters' => ['register' => 'reg-1', 'schema' => 'sch-msg', 'logiusMessageId' => 'lg-404'],
				'limit' => 1,
			])
			->willReturn([]);
		$this->logger->expects($this->once())->method('warning')
			->with('Berichtenbox readReceipt for unknown logiusMessageId.', ['logiusMessageId' => 'lg-404']);
		$this->audit->expects($this->never())->method('logRead');

		$this->assertFalse($this->service()->handleReadReceipt('lg-404', '2026-01-01T00:00:00Z'));
	}//end testHandleReadReceiptUnknownMessage()

	/**
	 * A failing lookup also counts as an unknown message.
	 *
	 * @return void
	 */
	public function testHandleReadReceiptLookupFailure(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertFalse($this->service()->handleReadReceipt('lg-1', '2026-01-01T00:00:00Z'));
	}//end testHandleReadReceiptLookupFailure()

	/**
	 * The 5-day pass sends only due, unread rows with an email, and skips
	 * refused and failing sends.
	 *
	 * @return void
	 */
	public function testProcessFallbackQueueSendsOnlyEligibleRows(): void {
		$this->captureSaves();
		$old = '2020-01-06T09:00:00+00:00';
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg-1', 'schema' => 'sch-msg', 'deliveryStatus' => 'sent']])
			->willReturn([
				42,
				['uuid' => 'read', 'sentToBerichtenboxAt' => $old, 'readAt' => '2020-01-07T00:00:00Z', 'burgerEmail' => 'r@x.nl'],
				['uuid' => 'unsent'],
				['uuid' => 'garbled', 'sentToBerichtenboxAt' => 'not-a-date'],
				['uuid' => 'recent', 'sentToBerichtenboxAt' => '2999-01-01T00:00:00+00:00', 'burgerEmail' => 'n@x.nl'],
				['uuid' => 'no-email', 'sentToBerichtenboxAt' => $old, 'readAt' => null],
				['uuid' => 'refused', 'sentToBerichtenboxAt' => $old, 'burgerEmail' => 'refused@x.nl'],
				['uuid' => 'broken', 'sentToBerichtenboxAt' => $old, 'burgerEmail' => 'broken@x.nl'],
				['uuid' => 'ok', 'sentToBerichtenboxAt' => $old, 'burgerEmail' => 'ok@x.nl', 'body' => 'b'],
			]);
		$this->email->expects($this->exactly(3))->method('send')
			->willReturnCallback(static function (array $m, string $to, bool $notice): bool {
				if ($notice !== true) {
					throw new \LogicException('5-day fallback must append the notice');
				}

				if ($to === 'broken@x.nl') {
					throw new RuntimeException('smtp');
				}

				return $to !== 'refused@x.nl';
			});
		$this->email->method('lastRefusal')->willReturn(['reason' => 'opt-out']);
		$this->logger->expects($this->once())->method('info')
			->with('Berichtenbox 5-day fallback skipped: no burgerEmail.', ['messageId' => 'no-email']);
		$this->logger->expects($this->once())->method('warning')
			->with('5-day fallback email send failed.', ['exception' => 'smtp']);
		$this->audit->expects($this->once())->method('logFallback')->with('ok', '5-day-unread', 'hash');

		$this->assertSame(1, $this->service()->processFallbackQueue());

		$saved = $this->savedFor('sch-msg');
		$this->assertCount(1, $saved);
		$this->assertSame('ok', $saved[0]['uuid']);
		$this->assertSame('fallback-emailed', $saved[0]['deliveryStatus']);
		$this->assertSame('ok@x.nl', $saved[0]['fallbackEmail']);
	}//end testProcessFallbackQueueSendsOnlyEligibleRows()

	/**
	 * A failing lookup makes the fallback pass send nothing.
	 *
	 * @return void
	 */
	public function testProcessFallbackQueueLookupFailure(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')
			->with('BerichtenboxService findUnreadSent failed.', ['exception' => 'db']);
		$this->email->expects($this->never())->method('send');

		$this->assertSame(0, $this->service()->processFallbackQueue());
	}//end testProcessFallbackQueueLookupFailure()

	/**
	 * An inbound reply for an unknown parent is refused.
	 *
	 * @return void
	 */
	public function testHandleInboundReplyUnknownParent(): void {
		$this->objects->expects($this->once())->method('find')
			->with('p-404', $this->anything(), $this->anything(), 'reg-1', 'sch-msg')
			->willThrowException(new RuntimeException('not found'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('parent message not found: p-404');

		$this->service()->handleInboundReply('p-404', 'lr-1', 'hi');
	}//end testHandleInboundReplyUnknownParent()

	/**
	 * A reply that cannot be stored is audited as a processing error and rethrown.
	 *
	 * @return void
	 */
	public function testHandleInboundReplySaveFailureIsAuditedAndRethrown(): void {
		$this->objects->method('find')->willReturn(self::entity(['uuid' => 'p-1']));
		$this->logius->method('newUuidV4')->willReturn('reply-1');
		$this->tickets->expects($this->never())->method('save');
		$this->audit->expects($this->once())->method('logProcessingError')
			->with('reply-1', 'BerichtenboxReply register or schema not configured.', 'hash');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('BerichtenboxReply register or schema not configured.');

		$this->service(['berichtenboxReply_schema' => ''])->handleInboundReply('p-1', 'lr-1', 'hi');
	}//end testHandleInboundReplySaveFailureIsAuditedAndRethrown()

	/**
	 * When the ticket store is not configured the reply is saved with its
	 * processing error, audited, and the error rethrown.
	 *
	 * @return void
	 */
	public function testHandleInboundReplyContactmomentFailureRecordsError(): void {
		$this->captureSaves();
		$this->objects->method('find')->willReturn(self::entity(['uuid' => 'p-1']));
		$this->logius->method('newUuidV4')->willReturn('reply-2');
		$this->tickets->method('isConfigured')->willReturn(false);
		$this->audit->expects($this->once())->method('logProcessingError')
			->with('reply-2', 'Contactmoment register or schema not configured.', 'hash');
		$this->audit->expects($this->never())->method('logReplyReceived');

		try {
			$this->service()->handleInboundReply('p-1', 'lr-2', 'hello');
			$this->fail('Expected the contactmoment failure to be rethrown.');
		} catch (RuntimeException $e) {
			$this->assertSame('Contactmoment register or schema not configured.', $e->getMessage());
		}

		$replies = $this->savedFor('sch-reply');
		$this->assertCount(2, $replies);
		$this->assertSame('Contactmoment register or schema not configured.', $replies[1]['processingError']);
		$this->assertArrayNotHasKey('processingError', $replies[0]);
		$this->assertSame('lr-2', $replies[0]['logiusReplyId']);
	}//end testHandleInboundReplyContactmomentFailureRecordsError()

	/**
	 * A successful reply creates the contactmoment ticket and routes it
	 * through the queue router when one is registered.
	 *
	 * @return void
	 */
	public function testHandleInboundReplyRoutesContactmoment(): void {
		$this->captureSaves();
		$this->objects->method('find')->willReturn(
			self::entity(['uuid' => 'p-1', 'subject' => 'Uw zaak', 'caseId' => 'Z-1'])
		);
		$this->logius->method('newUuidV4')->willReturn('reply-3');
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->expects($this->once())->method('save')
			->with(
				TicketService::TYPE_CONTACTMOMENT,
				[
					'title' => 'Re: Uw zaak',
					'description' => 'thanks',
					'channel' => 'berichtenbox',
					'direction' => 'inbound',
					'outcome' => 'opvolging-nodig',
					'caseId' => 'Z-1',
					'parentMessageId' => 'p-1',
					'berichtenboxReplyId' => 'reply-3',
				],
				null
			)
			->willReturn(self::entity(['id' => 'cm-9']));

		$router = new class {
			/** @var array<int, array> */
			public array $routed = [];

			/**
			 * Record the routed ticket.
			 *
			 * @param array $ticket The ticket.
			 *
			 * @return void
			 */
			public function route(array $ticket): void {
				$this->routed[] = $ticket;
			}
		};
		$this->container->expects($this->once())->method('has')
			->with('OCA\\Pipelinq\\Service\\QueueRoutingService')->willReturn(true);
		$this->container->expects($this->once())->method('get')
			->with('OCA\\Pipelinq\\Service\\QueueRoutingService')->willReturn($router);
		$this->audit->expects($this->once())->method('logReplyReceived')->with('reply-3', 'hash');

		$reply = $this->service()->handleInboundReply('p-1', 'lr-3', 'thanks');

		$this->assertSame('cm-9', $reply['createdInteractionId']);
		$this->assertCount(1, $router->routed);
		$this->assertSame('cm-9', $router->routed[0]['id']);
		$this->assertSame('cm-9', $this->savedFor('sch-reply')[1]['createdInteractionId']);
	}//end testHandleInboundReplyRoutesContactmoment()

	/**
	 * A failing router is logged, not fatal.
	 *
	 * @return void
	 */
	public function testHandleInboundReplyRoutingFailureIsLogged(): void {
		$this->captureSaves();
		$this->objects->method('find')->willReturn(self::entity(['uuid' => 'p-1']));
		$this->logius->method('newUuidV4')->willReturn('reply-4');
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->method('save')->willReturn(self::entity(['uuid' => 'cm-1']));
		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('no router'));
		$this->logger->expects($this->once())->method('warning')
			->with('Berichtenbox reply routing failed.', ['exception' => 'no router']);

		$reply = $this->service()->handleInboundReply('p-1', 'lr-4', 'x');

		$this->assertSame('cm-1', $reply['createdInteractionId']);
	}//end testHandleInboundReplyRoutingFailureIsLogged()

	/**
	 * Crypto-shred overwrites the BSN on every row, skipping unconfigured
	 * schemas, failing lookups, non-objects and failing saves.
	 *
	 * @return void
	 */
	public function testCryptoShredOverwritesBsnAcrossSchemas(): void {
		$hash = $this->encryption()->hashBsn('123456789', 'tenant-a');
		$this->objects->expects($this->exactly(2))->method('findAll')
			->willReturnCallback(static function (array $config) use ($hash): array {
				if ($config['filters']['bsnHash'] !== $hash) {
					throw new \LogicException('shred must filter on the BSN hash');
				}

				if ($config['filters']['schema'] === 'sch-reply') {
					throw new RuntimeException('reply lookup down');
				}

				return [
					['uuid' => 'm-1', 'bsn' => 'cipher-1'],
					'garbage',
					self::entity(['uuid' => 'm-2', 'bsn' => 'cipher-2']),
					['uuid' => 'm-bad', 'bsn' => 'cipher-3'],
				];
			});
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				if ($object['uuid'] === 'm-bad') {
					throw new RuntimeException('locked');
				}

				$this->saves[] = ['schema' => 'sch-msg', 'object' => $object];
				return self::entity($object);
			}
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $msg, array $ctx) use (&$warnings): void {
				$warnings[] = [$msg, $ctx['schemaKey']];
			}
		);

		$total = $this->service(['mailboxResolution_schema' => ''])->cryptoShred('123456789', 'tenant-a');

		$this->assertSame(2, $total);
		$this->assertSame(
			[
				['cryptoShred save failed.', 'berichtenboxMessage_schema'],
				['cryptoShred lookup failed.', 'berichtenboxReply_schema'],
			],
			$warnings
		);
		$saved = $this->savedFor('sch-msg');
		$this->assertSame(['m-1', 'm-2'], array_column($saved, 'uuid'));
		$this->assertNotSame('cipher-1', $saved[0]['bsn']);
		$this->assertSame($saved[0]['bsn'], $saved[1]['bsn']);
	}//end testCryptoShredOverwritesBsnAcrossSchemas()

	/**
	 * A registry template matching case type, status and language is used.
	 *
	 * @return void
	 */
	public function testQueueOutboundMessageUsesRegistryTemplate(): void {
		$this->captureSaves();
		$this->logius->method('newUuidV4')->willReturn('msg-t');
		$this->objects->expects($this->once())->method('findAll')
			->with([
				'filters' => [
					'register' => 'reg-1',
					'schema' => 'sch-tpl',
					'caseType' => 'paspoort',
					'status' => 'afgehandeld',
					'language' => 'en',
				],
				'limit' => 1,
			])
			->willReturn([self::entity(['uuid' => 'tpl-7', 'subject' => 'Case {{caseId}}', 'body' => '<p>{{status}}</p>'])]);

		$saved = $this->service(['berichtenboxTemplate_schema' => 'sch-tpl'])->queueOutboundMessage(
			new CaseStatusMessage('Z-9', null, 'afgehandeld', '123456789'),
			new MessageOptions(null, ['caseType' => 'paspoort', 'language' => 'en'])
		);

		$this->assertSame('tpl-7', $saved['templateId']);
		$this->assertSame('Case Z-9', $saved['subject']);
		$this->assertSame('', $saved['interactionId']);
	}//end testQueueOutboundMessageUsesRegistryTemplate()

	/**
	 * A failing or empty template lookup falls back to the built-in template.
	 *
	 * @return void
	 */
	public function testQueueOutboundMessageFallsBackWhenLookupFailsOrEmpty(): void {
		$this->captureSaves();
		$this->logius->method('newUuidV4')->willReturn('msg-f');
		$calls = 0;
		$this->objects->expects($this->exactly(2))->method('findAll')
			->willReturnCallback(static function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('tpl store down');
				}

				return [];
			});
		$service = $this->service(['berichtenboxTemplate_schema' => 'sch-tpl']);
		$message = new CaseStatusMessage('Z-1', 'cm-1', 'open', '123456789');

		$first = $service->queueOutboundMessage($message);
		$second = $service->queueOutboundMessage($message);

		$this->assertSame('fallback', $first['templateId']);
		$this->assertSame('fallback', $second['templateId']);
		$this->assertStringContainsString('<strong>open</strong>', $first['body']);
		$this->assertStringStartsWith('Status update voor zaak', $first['subject']);
	}//end testQueueOutboundMessageFallsBackWhenLookupFailsOrEmpty()

	/**
	 * Without a template schema the registry is not queried, and without a
	 * tenant id the BSN is hashed under the default tenant.
	 *
	 * @return void
	 */
	public function testQueueOutboundMessageWithoutTemplateStoreOrTenant(): void {
		$this->captureSaves();
		$this->logius->method('newUuidV4')->willReturn('msg-d');
		$this->objects->expects($this->never())->method('findAll');

		$saved = $this->service(['tenant_id' => ''])->queueOutboundMessage(
			new CaseStatusMessage('Z-2', 'cm-2', 'open', '123456789')
		);

		$this->assertSame('fallback', $saved['templateId']);
		$this->assertSame(
			$this->encryption()->hashBsn('123456789', BerichtenboxService::DEFAULT_TENANT_ID),
			$saved['bsnHash']
		);
		$this->assertNotSame($this->encryption()->hashBsn('123456789', 'tenant-a'), $saved['bsnHash']);
	}//end testQueueOutboundMessageWithoutTemplateStoreOrTenant()
}//end class
