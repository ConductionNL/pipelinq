<?php

/**
 * Unit tests for BerichtenboxService — the bridge state machine.
 *
 * Focused on the highest-value paths: queueOutboundMessage encryption +
 * audit; dispatchOne success-to-Logius, no-mailbox-fallback-email, and
 * Logius failure → retry; handleReadReceipt; handleInboundReply.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-003
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-receipt-005
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-inbound-006
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-retry-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BerichtenboxService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Tests the orchestrator.
 */
class BerichtenboxServiceTest extends TestCase {
	/**
	 * Captured saveObject payloads keyed by schema.
	 *
	 * @var array<int, array>
	 */
	private array $savedMessages = [];

	/**
	 * Build a real EncryptionService with a deterministic secret.
	 *
	 * @return EncryptionService
	 */
	private function realEncryption(): EncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('berichtenbox-service-test-secret');
		return new EncryptionService(
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end realEncryption()

	/**
	 * Configure $appConfig stub returns.
	 *
	 * @return IAppConfig
	 */
	private function appConfigStub(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg-1',
					'berichtenboxMessage_schema' => 'sch-msg',
					'berichtenboxReply_schema' => 'sch-reply',
					'berichtenboxTemplate_schema' => 'sch-tpl',
					'ticket_schema' => 'sch-ticket',
					'mailboxResolution_schema' => 'sch-mr',
					'tenant_id' => 'tenant-a',
					'tenant_display_name' => 'Gemeente Amsterdam',
					// A provisioned tenant HAS PKI-overheid material. Leaving
					// these at '' made every dispatch test exercise the
					// unsigned-request path without saying so.
					'pki_cert' => '--pki-cert-pem--',
					'pki_key' => '--pki-key-pem--',
					default => $default,
				};
			}
		);
		return $appConfig;
	}//end appConfigStub()

	/**
	 * Stub the unified ticket resolver (unify-ticket-supertype): the inbound
	 * reply now writes a `ticket` with `ticketType: contactmoment` instead of a
	 * `contactmoment_schema` object.
	 *
	 * @return TicketService
	 */
	private function ticketServiceStub(): TicketService {
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(true);
		$ticketService->method('getRegisterId')->willReturn('reg-1');
		$ticketService->method('getSchemaId')->willReturn('sch-ticket');
		return $ticketService;
	}//end ticketServiceStub()

	/**
	 * Build an ObjectService that captures every save in $savedMessages.
	 *
	 * @return ObjectService
	 */
	private function captureObjectService(): ObjectService {
		$this->savedMessages = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('saveObject')->willReturnCallback(
			function (...$args) {
				foreach ($args as $arg) {
					if (is_array($arg) === true) {
						$this->savedMessages[] = $arg;
						return $arg;
					}
				}
				return [];
			}
		);
		$service->method('findAll')->willReturn([]);
		return $service;
	}//end captureObjectService()

	/**
	 * Build the SUT.
	 *
	 * @param ObjectService $objectService Object service.
	 * @param MailboxResolver $resolver Resolver.
	 * @param LogiusConnector $logius Connector.
	 * @param EmailFallbackSender $email Email fallback.
	 * @param DeliveryAuditLogger $audit Audit logger.
	 *
	 * @return BerichtenboxService
	 */
	private function buildService(
		ObjectService $objectService,
		MailboxResolver $resolver,
		LogiusConnector $logius,
		EmailFallbackSender $email,
		DeliveryAuditLogger $audit,
		?IAppConfig $appConfig = null,
	): BerichtenboxService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$container->method('has')->willReturn(false);

		return new BerichtenboxService(
			$container,
			($appConfig ?? $this->appConfigStub()),
			$this->realEncryption(),
			new TemplateRenderer($this->createMock(LoggerInterface::class)),
			$resolver,
			$logius,
			$email,
			$audit,
			new DutchHolidayCalendar(),
			$this->ticketServiceStub(),
			$this->createMock(LoggerInterface::class)
		);
	}//end buildService()

	/**
	 * queueOutboundMessage encrypts BSN and writes a queued row.
	 *
	 * @return void
	 */
	public function testQueueOutboundMessageEncryptsBsnAndLogs(): void {
		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->once())
			->method('logQueued');
		$audit->method('hashPayload')->willReturn('hash');
		$audit->method('calculateRetentionUntil')->willReturn(new \DateTimeImmutable('+10 years'));

		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('newUuidV4')->willReturn('11111111-1111-4111-8111-111111111111');

		$resolver = $this->createMock(MailboxResolver::class);
		$email = $this->createMock(EmailFallbackSender::class);
		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$email,
			$audit
		);

		$result = $service->queueOutboundMessage(
			'Z-2026-1',
			'cm-1',
			'afgehandeld',
			'123456789',
			null,
			['caseType' => 'paspoort']
		);

		$this->assertIsArray($result);
		// BSN never persisted as plaintext.
		foreach ($this->savedMessages as $row) {
			if (isset($row['bsn']) === true) {
				$this->assertNotSame('123456789', $row['bsn']);
			}
		}
		// bsnHash is HMAC hex (64 chars).
		$found = array_filter($this->savedMessages, fn ($r) => isset($r['bsnHash']) === true);
		$this->assertNotEmpty($found);
		$firstRow = array_values($found)[0];
		$this->assertSame(64, strlen($firstRow['bsnHash']));
		$this->assertSame('queued', $firstRow['deliveryStatus']);
	}//end testQueueOutboundMessageEncryptsBsnAndLogs()

	/**
	 * dispatchOne with available mailbox calls Logius and marks sent.
	 *
	 * @return void
	 */
	public function testDispatchOneToBerichtenbox(): void {
		$resolver = $this->createMock(MailboxResolver::class);
		$resolver->method('resolve')->willReturn([
			'mailboxAvailable' => true,
			'optedOut' => false,
			'resolvedAt' => '2026-06-01T00:00:00Z',
			'expiresAt' => '2026-06-02T00:00:00Z',
			'bsnHash' => str_repeat('a', 64),
			'source' => 'cache',
		]);
		$logius = $this->createMock(LogiusConnector::class);
		$logius->expects($this->once())
			->method('sendMessage')
			->willReturn(['logiusMessageId' => 'logius-99', 'raw' => []]);

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->once())->method('logSent');
		$audit->method('hashPayload')->willReturn('hash');

		$email = $this->createMock(EmailFallbackSender::class);
		$email->expects($this->never())->method('send');

		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$email,
			$audit
		);

		$encryption = $this->realEncryption();
		$cipher = $encryption->encrypt('123456789', 'tenant-a');

		$service->dispatchOne([
			'uuid' => 'msg-1',
			'bsn' => $cipher,
			'bsnHash' => $encryption->hashBsn('123456789', 'tenant-a'),
			'subject' => 'X',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
		]);

		// Last saveMessages row should be sent.
		$sentRows = array_filter($this->savedMessages, fn ($r) => ($r['deliveryStatus'] ?? '') === 'sent');
		$this->assertNotEmpty($sentRows);
		$this->assertSame('logius-99', array_values($sentRows)[0]['logiusMessageId']);
	}//end testDispatchOneToBerichtenbox()

	/**
	 * dispatchOne fails CLOSED when the tenant PKI-overheid cert/key is absent.
	 *
	 * An empty `pki_key` does not stop the dispatch by itself: LogiusConnector
	 * ::signRequest() falls back to `base64(sha256(body))`, a keyless digest,
	 * and the message would still leave the instance carrying a plaintext BSN
	 * with request signing silently deactivated. The dispatch must be refused
	 * and routed to the retry/fail path instead.
	 *
	 * @return void
	 */
	public function testDispatchOneRefusesWhenPkiMaterialMissing(): void {
		$resolver = $this->createMock(MailboxResolver::class);
		$resolver->method('resolve')->willReturn([
			'mailboxAvailable' => true,
			'optedOut' => false,
			'resolvedAt' => '2026-06-01T00:00:00Z',
			'expiresAt' => '2026-06-02T00:00:00Z',
			'bsnHash' => str_repeat('a', 64),
			'source' => 'cache',
		]);

		// The connector must never be reached on an unprovisioned tenant.
		$logius = $this->createMock(LogiusConnector::class);
		$logius->expects($this->never())->method('sendMessage');

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->never())->method('logSent');
		$audit->method('hashPayload')->willReturn('hash');

		$email = $this->createMock(EmailFallbackSender::class);

		// Same stub, minus the PKI material.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg-1',
					'berichtenboxMessage_schema' => 'sch-msg',
					'tenant_id' => 'tenant-a',
					'pki_cert', 'pki_key' => '',
					default => $default,
				};
			}
		);

		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$email,
			$audit,
			$appConfig
		);

		$encryption = $this->realEncryption();

		$service->dispatchOne([
			'uuid' => 'msg-nopki',
			'bsn' => $encryption->encrypt('123456789', 'tenant-a'),
			'bsnHash' => $encryption->hashBsn('123456789', 'tenant-a'),
			'subject' => 'X',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
		]);

		$sentRows = array_filter($this->savedMessages, fn ($r) => ($r['deliveryStatus'] ?? '') === 'sent');
		$this->assertEmpty($sentRows, 'No message may be marked sent without tenant PKI material.');
	}//end testDispatchOneRefusesWhenPkiMaterialMissing()

	/**
	 * dispatchOne with no mailbox + email available → fallback path.
	 *
	 * @return void
	 */
	public function testDispatchOneFallbackEmailWhenNoMailbox(): void {
		$resolver = $this->createMock(MailboxResolver::class);
		$resolver->method('resolve')->willReturn([
			'mailboxAvailable' => false,
			'optedOut' => false,
			'resolvedAt' => '2026-06-01T00:00:00Z',
			'expiresAt' => '2026-06-02T00:00:00Z',
			'bsnHash' => str_repeat('a', 64),
			'source' => 'logius',
		]);
		$logius = $this->createMock(LogiusConnector::class);
		$logius->expects($this->never())->method('sendMessage');

		$email = $this->createMock(EmailFallbackSender::class);
		$email->expects($this->once())
			->method('send')
			->with($this->isType('array'), 'burger@example.nl', false)
			->willReturn(true);

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->once())->method('logFallback');
		$audit->method('hashPayload')->willReturn('hash');

		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$email,
			$audit
		);

		$encryption = $this->realEncryption();
		$cipher = $encryption->encrypt('123456789', 'tenant-a');

		$service->dispatchOne([
			'uuid' => 'msg-2',
			'bsn' => $cipher,
			'bsnHash' => $encryption->hashBsn('123456789', 'tenant-a'),
			'burgerEmail' => 'burger@example.nl',
			'subject' => 'X',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
		]);

		$fbRows = array_filter($this->savedMessages, fn ($r) => ($r['deliveryStatus'] ?? '') === 'fallback-emailed');
		$this->assertNotEmpty($fbRows);
		$this->assertSame('burger@example.nl', array_values($fbRows)[0]['fallbackEmail']);
	}//end testDispatchOneFallbackEmailWhenNoMailbox()

	/**
	 * dispatchOne with Logius failure re-queues with backoff up to MAX_RETRIES.
	 *
	 * @return void
	 */
	public function testDispatchOneRetryOnLogiusFailure(): void {
		$resolver = $this->createMock(MailboxResolver::class);
		$resolver->method('resolve')->willReturn([
			'mailboxAvailable' => true,
			'optedOut' => false,
			'resolvedAt' => '2026-06-01T00:00:00Z',
			'expiresAt' => '2026-06-02T00:00:00Z',
			'bsnHash' => str_repeat('a', 64),
			'source' => 'cache',
		]);
		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('sendMessage')->willThrowException(new \RuntimeException('rate limit'));

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->once())->method('logFailed');
		$audit->method('hashPayload')->willReturn('hash');

		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$this->createMock(EmailFallbackSender::class),
			$audit
		);

		$encryption = $this->realEncryption();
		$cipher = $encryption->encrypt('123456789', 'tenant-a');

		$service->dispatchOne([
			'uuid' => 'msg-3',
			'bsn' => $cipher,
			'bsnHash' => $encryption->hashBsn('123456789', 'tenant-a'),
			'subject' => 'X',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
		]);

		// Should be re-queued with retryCount=1 + nextRetryAt set.
		$queuedRows = array_filter(
			$this->savedMessages,
			fn ($r) => (($r['deliveryStatus'] ?? '') === 'queued' && ($r['retryCount'] ?? 0) === 1)
		);
		$this->assertNotEmpty($queuedRows);
		$row = array_values($queuedRows)[0];
		$this->assertNotEmpty($row['nextRetryAt'] ?? '');
	}//end testDispatchOneRetryOnLogiusFailure()

	/**
	 * dispatchOne with retryCount at MAX_RETRIES marks failed (no further retry).
	 *
	 * @return void
	 */
	public function testDispatchOneHardFailureAfterMaxRetries(): void {
		$resolver = $this->createMock(MailboxResolver::class);
		$resolver->method('resolve')->willReturn([
			'mailboxAvailable' => true,
			'optedOut' => false,
			'resolvedAt' => '2026-06-01T00:00:00Z',
			'expiresAt' => '2026-06-02T00:00:00Z',
			'bsnHash' => str_repeat('a', 64),
			'source' => 'cache',
		]);
		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('sendMessage')->willThrowException(new \RuntimeException('still failing'));

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->method('hashPayload')->willReturn('hash');

		$service = $this->buildService(
			$this->captureObjectService(),
			$resolver,
			$logius,
			$this->createMock(EmailFallbackSender::class),
			$audit
		);

		$encryption = $this->realEncryption();
		$cipher = $encryption->encrypt('123456789', 'tenant-a');

		$service->dispatchOne([
			'uuid' => 'msg-4',
			'bsn' => $cipher,
			'bsnHash' => $encryption->hashBsn('123456789', 'tenant-a'),
			'subject' => 'X',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'queued',
			'retryCount' => BerichtenboxService::MAX_RETRIES,
		]);

		$failedRows = array_filter(
			$this->savedMessages,
			fn ($r) => ($r['deliveryStatus'] ?? '') === 'failed'
		);
		$this->assertNotEmpty($failedRows);
	}//end testDispatchOneHardFailureAfterMaxRetries()

	/**
	 * handleReadReceipt sets readAt + flips status.
	 *
	 * @return void
	 */
	public function testHandleReadReceipt(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([[
			'uuid' => 'msg-5',
			'logiusMessageId' => 'logius-77',
			'body' => '<p>x</p>',
			'deliveryStatus' => 'sent',
		]]);
		$captured = [];
		$objectService->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$captured) {
				foreach ($args as $arg) {
					if (is_array($arg) === true) {
						$captured[] = $arg;
						return $arg;
					}
				}
				return [];
			}
		);

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->expects($this->once())->method('logRead');
		$audit->method('hashPayload')->willReturn('hash');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$container->method('has')->willReturn(false);

		$service = new BerichtenboxService(
			$container,
			$this->appConfigStub(),
			$this->realEncryption(),
			new TemplateRenderer($this->createMock(LoggerInterface::class)),
			$this->createMock(MailboxResolver::class),
			$this->createMock(LogiusConnector::class),
			$this->createMock(EmailFallbackSender::class),
			$audit,
			new DutchHolidayCalendar(),
			$this->ticketServiceStub(),
			$this->createMock(LoggerInterface::class)
		);

		$updated = $service->handleReadReceipt('logius-77', '2026-06-01T12:00:00Z');
		$this->assertTrue($updated);
		$readRows = array_filter($captured, fn ($r) => ($r['deliveryStatus'] ?? '') === 'read');
		$this->assertNotEmpty($readRows);
	}//end testHandleReadReceipt()
}//end class
