<?php

/**
 * Coverage tests for ReceiptDeliveryService: preview, receipt log, email
 * delivery, thermal print, invoice-number assignment and the audit log.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\InvoiceSequenceService;
use OCA\Pipelinq\Service\ReceiptDeliveryService;
use OCA\Pipelinq\Service\ReceiptService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Receipt delivery over a mocked register, mailer and invoice sequence.
 *
 * @covers \OCA\Pipelinq\Service\ReceiptDeliveryService
 * @uses   \OCA\Pipelinq\Service\ReceiptService
 * @uses   \OCA\Pipelinq\Service\ReceiptLines
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class ReceiptDeliveryServiceCoverageTest extends TestCase {

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The stored transaction payload, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $transaction = null;

	/**
	 * The stored client payload, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $client = null;

	/**
	 * The stored template payload, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $template = null;

	/**
	 * Line rows by findAll.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * Receipt log rows by findAll (without an invoiceNumber filter).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logs = [];

	/**
	 * Receipt log rows that prove an invoice number.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $proofs = [];

	/**
	 * Schemas whose reads throw.
	 *
	 * @var array<int, string>
	 */
	private array $readFails = [];

	/**
	 * Schemas whose writes throw.
	 *
	 * @var array<int, string>
	 */
	private array $writeFails = [];

	/**
	 * Saved objects: [object, register, schema, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: mixed}>
	 */
	private array $saved = [];

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The l10n.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N $l10n;

	/**
	 * The mailer.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message the mailer creates.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * The invoice sequence.
	 *
	 * @var InvoiceSequenceService&MockObject
	 */
	private InvoiceSequenceService $sequence;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The service under test.
	 *
	 * @var ReceiptDeliveryService
	 */
	private ReceiptDeliveryService $service;

	/**
	 * Wire the service over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg-1',
			'posTransaction_schema' => 's-tx',
			'posTransactionLine_schema' => 's-line',
			'receiptPrintLog_schema' => 's-log',
			'receiptTemplate_schema' => 's-tpl',
			'client_schema' => 's-client',
		];
		$this->transaction = [
			'reference' => 'POS-0001',
			'status' => 'settled',
			'total' => 42.5,
			'subtotal' => 42.5,
			'cashier' => 'pieter',
			'customerEmail' => 'klant@example.nl',
			'confirmedAt' => '2026-10-07T11:40:00+02:00',
		];
		$this->lines = [['transaction' => 'tx-1', 'description' => 'Koffie', 'quantity' => 1, 'unitPrice' => 42.5, 'lineTotal' => 42.5]];

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);

		$this->l10n = $this->createMock(originalClassName: IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn (string $text, $params = []): string => vsprintf($text, (array) $params));

		$this->message = $this->createMock(originalClassName: IMessage::class);
		$this->mailer = $this->createMock(originalClassName: IMailer::class);
		$this->mailer->method('createMessage')->willReturn($this->message);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturn(false);

		$this->sequence = $this->createMock(originalClassName: InvoiceSequenceService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireObjectService();

		$this->service = new ReceiptDeliveryService(
			receiptService: $this->renderer(),
			invoiceSequence: $this->sequence,
			mailer: $this->mailer,
			appConfig: $this->appConfig,
			policy: new PosAccessPolicy(appConfig: $this->appConfig, groupManager: $groups),
			l10n: $this->l10n,
			logger: $this->logger,
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * A real receipt renderer over the same config and l10n.
	 *
	 * @return ReceiptService The renderer.
	 */
	private function renderer(): ReceiptService {
		return new ReceiptService(appConfig: $this->appConfig, l10n: $this->l10n);
	}//end renderer()

	/**
	 * An entity carrying a payload.
	 *
	 * @param string $uuid The entity UUID.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end entity()

	/**
	 * Answer find/findAll/saveObject from the fixture properties.
	 *
	 * @return void
	 */
	private function wireObjectService(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				if (in_array($schema, $this->readFails, true) === true) {
					throw new RuntimeException('read failed');
				}

				$payload = match ($schema) {
					's-tx' => $this->transaction,
					's-client' => $this->client,
					's-tpl' => $this->template,
					default => null,
				};

				if ($payload === null) {
					return null;
				}

				return $this->entity(uuid: (string) $id, payload: $payload);
			}
		);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$schema = $config['filters']['schema'] ?? '';
				if (in_array($schema, $this->readFails, true) === true) {
					throw new RuntimeException('list failed');
				}

				if ($schema === 's-line') {
					return $this->lines;
				}

				if ($schema === 's-log' && isset($config['filters']['invoiceNumber']) === true) {
					return $this->proofs;
				}

				if ($schema === 's-log') {
					return $this->logs;
				}

				return [];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				if (in_array($schema, $this->writeFails, true) === true) {
					throw new RuntimeException('write failed');
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
				if ($schema === 's-log') {
					return $this->entity(uuid: 'log-9', payload: $object);
				}

				return $this->entity(uuid: (string) $uuid, payload: $object);
			}
		);
	}//end wireObjectService()

	/**
	 * The transaction as the service sees it after loading (id, @self, lines).
	 *
	 * @param array<string, mixed> $extra Fields to add or override.
	 *
	 * @return array<string, mixed> The loaded transaction.
	 */
	private function loaded(array $extra = []): array {
		$transaction = $this->entity(uuid: 'tx-1', payload: (array) $this->transaction)->jsonSerialize();
		$transaction['lines'] = $this->lines;

		return array_merge($transaction, $extra);
	}//end loaded()

	/**
	 * The objects saved to one schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return array<int, array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: mixed}> The saves.
	 */
	private function savedTo(string $schema): array {
		return array_values(array_filter($this->saved, fn (array $save): bool => $save[2] === $schema));
	}//end savedTo()

	/**
	 * A preview renders the loaded transaction with its lines and reports the inline customer email.
	 *
	 * @return void
	 */
	public function testPreviewRendersTheLoadedTransaction(): void {
		$preview = $this->service->preview(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$expected = $this->loaded();
		$this->assertSame(expected: $this->renderer()->renderText(transaction: $expected), actual: $preview['text']);
		$this->assertSame(expected: $this->renderer()->renderHtml(transaction: $expected), actual: $preview['html']);
		$this->assertStringContainsString(needle: 'Koffie', haystack: $preview['text']);
		$this->assertFalse(condition: $preview['isInvoice']);
		$this->assertSame(expected: 'POS-0001', actual: $preview['reference']);
		$this->assertSame(expected: 'klant@example.nl', actual: $preview['customerEmail']);
	}//end testPreviewRendersTheLoadedTransaction()

	/**
	 * A preview without a reference falls back to the transaction id.
	 *
	 * @return void
	 */
	public function testPreviewReferenceFallsBackToTheId(): void {
		unset($this->transaction['reference']);

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'admin');

		$this->assertSame(expected: 'tx-1', actual: $preview['reference']);
	}//end testPreviewReferenceFallsBackToTheId()

	/**
	 * A preview flags a sale of EUR 100 or more as an invoice.
	 *
	 * @return void
	 */
	public function testPreviewFlagsAnInvoiceSale(): void {
		$this->transaction['total'] = 100.0;

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertTrue(condition: $preview['isInvoice']);
	}//end testPreviewFlagsAnInvoiceSale()

	/**
	 * A preview renders with the stored template when one is selected.
	 *
	 * @return void
	 */
	public function testPreviewUsesTheSelectedTemplate(): void {
		$this->template = ['name' => 'Smal', 'paperWidth' => 58];

		$preview = $this->service->preview(transactionId: 'tx-1', templateId: 'tpl-1', userId: 'pieter');

		$template = $this->entity(uuid: 'tpl-1', payload: $this->template)->jsonSerialize();
		$this->assertSame(
			expected: $this->renderer()->renderText(transaction: $this->loaded(), template: $template),
			actual: $preview['text']
		);
	}//end testPreviewUsesTheSelectedTemplate()

	/**
	 * A template that cannot be read falls back to the default layout.
	 *
	 * @return void
	 */
	public function testPreviewFallsBackToTheDefaultTemplateWhenItCannotBeRead(): void {
		$this->readFails = ['s-tpl'];

		$preview = $this->service->preview(transactionId: 'tx-1', templateId: 'tpl-1', userId: 'pieter');

		$this->assertSame(expected: $this->renderer()->renderText(transaction: $this->loaded()), actual: $preview['text']);
	}//end testPreviewFallsBackToTheDefaultTemplateWhenItCannotBeRead()

	/**
	 * A blank template id is not looked up at all.
	 *
	 * @return void
	 */
	public function testABlankTemplateIdIsNotLookedUp(): void {
		$this->template = ['name' => 'never read'];
		$this->objectService->expects($this->once())->method('find');

		$this->service->preview(transactionId: 'tx-1', templateId: '   ', userId: 'pieter');
	}//end testABlankTemplateIdIsNotLookedUp()

	/**
	 * A transaction absent from the register is a 404.
	 *
	 * @return void
	 */
	public function testPreviewOfAnAbsentTransactionIsNotFound(): void {
		$this->transaction = null;

		$this->expectException(exception: OCSNotFoundException::class);
		$this->expectExceptionMessage(message: 'Transactie niet gevonden.');
		$this->service->preview(transactionId: 'tx-1', userId: 'pieter');
	}//end testPreviewOfAnAbsentTransactionIsNotFound()

	/**
	 * A transaction read that throws is a 404 too.
	 *
	 * @return void
	 */
	public function testPreviewOfAnUnreadableTransactionIsNotFound(): void {
		$this->readFails = ['s-tx'];

		$this->expectException(exception: OCSNotFoundException::class);
		$this->expectExceptionMessage(message: 'Transactie niet gevonden.');
		$this->service->preview(transactionId: 'tx-1', userId: 'pieter');
	}//end testPreviewOfAnUnreadableTransactionIsNotFound()

	/**
	 * An unconfigured register is a 404 before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterIsNotFound(): void {
		$this->config['register'] = '';
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(exception: OCSNotFoundException::class);
		$this->expectExceptionMessage(message: 'POS register of schema is niet geconfigureerd.');
		$this->service->preview(transactionId: 'tx-1', userId: 'pieter');
	}//end testAnUnconfiguredRegisterIsNotFound()

	/**
	 * Someone who is not the cashier, in the POS group or an admin is refused.
	 *
	 * @return void
	 */
	public function testPreviewRefusesAStranger(): void {
		$this->expectException(exception: OCSForbiddenException::class);
		$this->expectExceptionMessage(message: 'U mag het bonnetje van deze transactie niet inzien.');
		$this->service->preview(transactionId: 'tx-1', userId: 'stranger');
	}//end testPreviewRefusesAStranger()

	/**
	 * Without the inline email, the linked client's email is used.
	 *
	 * @return void
	 */
	public function testCustomerEmailComesFromTheLinkedClient(): void {
		unset($this->transaction['customerEmail']);
		$this->transaction['client'] = 'client-1';
		$this->client = ['email' => 'client@example.nl'];

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: 'client@example.nl', actual: $preview['customerEmail']);
	}//end testCustomerEmailComesFromTheLinkedClient()

	/**
	 * A client without `email` falls back to `emailAddress`.
	 *
	 * @return void
	 */
	public function testCustomerEmailFallsBackToEmailAddress(): void {
		unset($this->transaction['customerEmail']);
		$this->transaction['client'] = 'client-1';
		$this->client = ['emailAddress' => 'alt@example.nl'];

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: 'alt@example.nl', actual: $preview['customerEmail']);
	}//end testCustomerEmailFallsBackToEmailAddress()

	/**
	 * A client that cannot be read gives no email and a warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableClientGivesNoEmail(): void {
		unset($this->transaction['customerEmail']);
		$this->transaction['client'] = 'client-1';
		$this->readFails = ['s-client'];
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: failed to resolve customer email', ['exception' => 'read failed']);

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: '', actual: $preview['customerEmail']);
	}//end testAnUnreadableClientGivesNoEmail()

	/**
	 * A transaction without inline email or client has no customer email.
	 *
	 * @return void
	 */
	public function testNoClientMeansNoEmail(): void {
		unset($this->transaction['customerEmail']);

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: '', actual: $preview['customerEmail']);
	}//end testNoClientMeansNoEmail()

	/**
	 * Lines that cannot be read render as none, with a warning.
	 *
	 * @return void
	 */
	public function testUnreadableLinesRenderAsNone(): void {
		$this->readFails = ['s-line'];
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: failed to load receipt lines', ['exception' => 'list failed']);

		$preview = $this->service->preview(transactionId: 'tx-1', userId: 'pieter');

		$this->assertStringNotContainsString(needle: 'Koffie', haystack: $preview['text']);
		$this->assertSame(
			expected: $this->renderer()->renderText(transaction: $this->loaded(extra: ['lines' => []])),
			actual: $preview['text']
		);
	}//end testUnreadableLinesRenderAsNone()

	/**
	 * The receipt log keeps only this transaction's entries, picks the newest mail and reports an invoice layout.
	 *
	 * @return void
	 */
	public function testReceiptLogPicksTheNewestMailOfThisTransaction(): void {
		$this->transaction['total'] = 150.0;
		$this->logs = [
			['transaction' => 'tx-1', 'action' => 'email', 'emailRecipient' => 'old@example.nl', 'printedAt' => '2026-10-07T10:00:00+02:00'],
			['transaction' => 'tx-1', 'action' => 'email', 'emailRecipient' => 'new@example.nl', 'printedAt' => '2026-10-07T12:00:00+02:00'],
			['transaction' => 'tx-other', 'action' => 'print', 'printerDevice' => 'elsewhere', 'printedAt' => '2026-10-08T12:00:00+02:00'],
			['transaction' => 'tx-1', 'printerDevice' => 'balie', 'printedAt' => '2026-10-07T09:00:00+02:00'],
		];

		$log = $this->service->receiptLog(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: '2026-10-07T12:00:00+02:00', actual: $log['emailedAt']);
		$this->assertSame(expected: 'new@example.nl', actual: $log['emailRecipient']);
		$this->assertSame(expected: 1, actual: $log['printCount']);
		$this->assertSame(expected: 'balie', actual: $log['printerDevice']);
		$this->assertSame(expected: 'invoice', actual: $log['layout']);
	}//end testReceiptLogPicksTheNewestMailOfThisTransaction()

	/**
	 * A receipt log that cannot be read is empty, with a warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableReceiptLogIsEmpty(): void {
		$this->readFails = ['s-log'];
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: failed to read the receipt log', ['exception' => 'list failed']);

		$log = $this->service->receiptLog(transactionId: 'tx-1', userId: 'pieter');

		$this->assertSame(expected: 0, actual: $log['printCount']);
		$this->assertSame(expected: '', actual: $log['printedAt']);
		$this->assertSame(expected: '', actual: $log['emailedAt']);
	}//end testAnUnreadableReceiptLogIsEmpty()

	/**
	 * A successful email goes to the customer with subject, bodies and sender, and is logged.
	 *
	 * @return void
	 */
	public function testEmailReceiptSendsToTheCustomerAndLogsIt(): void {
		$this->config['receipt_email_sender'] = 'kassa@example.nl';
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$expected = $this->loaded();
		$this->message->expects($this->once())->method('setTo')->with(['klant@example.nl']);
		$this->message->expects($this->once())->method('setSubject')->with('Your receipt POS-0001');
		$this->message->expects($this->once())->method('setPlainBody')->with($this->renderer()->renderText(transaction: $expected));
		$this->message->expects($this->once())->method('setHtmlBody')->with($this->renderer()->renderHtml(transaction: $expected));
		$this->message->expects($this->once())->method('setFrom')->with(['kassa@example.nl']);
		$this->mailer->expects($this->once())->method('send')->with($this->message)->willReturn([]);
		$this->sequence->expects($this->never())->method('next');

		$before = time();
		$result = $this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');

		$this->assertSame(expected: ['status' => 'success', 'emailRecipient' => 'klant@example.nl', 'logId' => 'log-9'], actual: $result);
		$logs = $this->savedTo(schema: 's-log');
		$this->assertCount(expectedCount: 1, haystack: $logs);
		[$log, $register] = $logs[0];
		$this->assertSame(expected: 'reg-1', actual: $register);
		$this->assertSame(expected: 'tx-1', actual: $log['transaction']);
		$this->assertSame(expected: '', actual: $log['template']);
		$this->assertSame(expected: 'email', actual: $log['action']);
		$this->assertSame(expected: 'success', actual: $log['status']);
		$this->assertSame(expected: '', actual: $log['invoiceNumber']);
		$this->assertSame(expected: 'klant@example.nl', actual: $log['emailRecipient']);
		$this->assertSame(expected: $this->renderer()->renderText(transaction: $expected), actual: $log['renderedContent']);
		$this->assertNull(actual: $log['errorMessage']);
		$this->assertSame(expected: 'pieter', actual: $log['actor']);
		$printedAt = (new DateTimeImmutable($log['printedAt']))->getTimestamp();
		$this->assertGreaterThanOrEqual(expected: $before - 1, actual: $printedAt);
		$this->assertLessThanOrEqual(expected: time() + 1, actual: $printedAt);
	}//end testEmailReceiptSendsToTheCustomerAndLogsIt()

	/**
	 * An invalid configured sender is not used as From.
	 *
	 * @return void
	 */
	public function testAnInvalidSenderIsNotUsed(): void {
		$this->config['receipt_email_sender'] = 'not-an-address';
		$this->mailer->method('validateMailAddress')->willReturnCallback(fn (string $email): bool => $email !== 'not-an-address');
		$this->mailer->method('send')->willReturn([]);
		$this->message->expects($this->never())->method('setFrom');

		$result = $this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');

		$this->assertSame(expected: 'success', actual: $result['status']);
	}//end testAnInvalidSenderIsNotUsed()

	/**
	 * No configured sender means no From header.
	 *
	 * @return void
	 */
	public function testNoSenderMeansNoFrom(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);
		$this->message->expects($this->never())->method('setFrom');

		$result = $this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');

		$this->assertSame(expected: 'success', actual: $result['status']);
	}//end testNoSenderMeansNoFrom()

	/**
	 * A requested recipient matching the customer (case and whitespace aside) is accepted.
	 *
	 * @return void
	 */
	public function testAMatchingRequestedRecipientIsAccepted(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);
		$this->message->expects($this->once())->method('setTo')->with(['klant@example.nl']);

		$result = $this->service->emailReceipt(
			transactionId: 'tx-1',
			templateId: null,
			requestedRecipient: '  KLANT@example.NL ',
			userId: 'pieter'
		);

		$this->assertSame(expected: 'klant@example.nl', actual: $result['emailRecipient']);
	}//end testAMatchingRequestedRecipientIsAccepted()

	/**
	 * A requested recipient other than the customer is refused before any mail is made.
	 *
	 * @return void
	 */
	public function testAForeignRequestedRecipientIsRefused(): void {
		$this->mailer->expects($this->never())->method('createMessage');

		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'Het bonnetje kan alleen naar het e-mailadres van de gekoppelde klant worden gestuurd.');
		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: 'spam@example.nl', userId: 'pieter');
	}//end testAForeignRequestedRecipientIsRefused()

	/**
	 * A transaction without a customer email cannot be emailed.
	 *
	 * @return void
	 */
	public function testNoCustomerEmailIsRefused(): void {
		unset($this->transaction['customerEmail']);

		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'Deze transactie heeft geen gekoppelde klant met een e-mailadres.');
		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');
	}//end testNoCustomerEmailIsRefused()

	/**
	 * An invalid customer email is refused.
	 *
	 * @return void
	 */
	public function testAnInvalidCustomerEmailIsRefused(): void {
		$this->mailer->method('validateMailAddress')->with('klant@example.nl')->willReturn(false);

		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'Ongeldig e-mailadres van de klant.');
		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');
	}//end testAnInvalidCustomerEmailIsRefused()

	/**
	 * A draft transaction cannot be emailed.
	 *
	 * @return void
	 */
	public function testADraftCannotBeEmailed(): void {
		$this->transaction['status'] = 'draft';

		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'Een bonnetje kan alleen voor een bevestigde, afgerekende of teruggeboekte transactie worden gemaakt.');
		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');
	}//end testADraftCannotBeEmailed()

	/**
	 * A stranger cannot email the receipt.
	 *
	 * @return void
	 */
	public function testAStrangerCannotEmail(): void {
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(exception: OCSForbiddenException::class);
		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: '');
	}//end testAStrangerCannotEmail()

	/**
	 * Failed recipients are reported as a failed send and logged as such.
	 *
	 * @return void
	 */
	public function testFailedRecipientsAreAFailedSend(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn(['klant@example.nl']);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: receipt email send failed (SMTP may be disabled on this host)',
			['exception' => 'Mailer reported failed recipients.']
		);

		$result = $this->service->emailReceipt(transactionId: 'tx-1', templateId: 'tpl-x', requestedRecipient: null, userId: 'pieter');

		$this->assertSame(
			expected: [
				'status' => 'failed',
				'emailRecipient' => 'klant@example.nl',
				'logId' => 'log-9',
				'error' => 'Mail delivery failed (no SMTP relay configured).',
			],
			actual: $result
		);
		$log = $this->savedTo(schema: 's-log')[0][0];
		$this->assertSame(expected: 'failed', actual: $log['status']);
		$this->assertSame(expected: 'Mail delivery failed (no SMTP relay configured).', actual: $log['errorMessage']);
		$this->assertNull(actual: $log['renderedContent']);
		$this->assertSame(expected: 'pieter', actual: $log['actor']);
	}//end testFailedRecipientsAreAFailedSend()

	/**
	 * A transport exception is a failed send.
	 *
	 * @return void
	 */
	public function testATransportErrorIsAFailedSend(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willThrowException(new RuntimeException('no smtp'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: receipt email send failed (SMTP may be disabled on this host)',
			['exception' => 'no smtp']
		);

		$result = $this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');

		$this->assertSame(expected: 'failed', actual: $result['status']);
		$this->assertSame(expected: 'log-9', actual: $result['logId']);
	}//end testATransportErrorIsAFailedSend()

	/**
	 * A log that cannot be written gives an empty log id and an error.
	 *
	 * @return void
	 */
	public function testAnUnwritableLogGivesNoLogId(): void {
		$this->writeFails = ['s-log'];
		$this->logger->expects($this->once())->method('error')
			->with('Pipelinq: failed to write receipt log', ['exception' => 'write failed']);

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: 'success', actual: $result['status']);
		$this->assertSame(expected: '', actual: $result['logId']);
	}//end testAnUnwritableLogGivesNoLogId()

	/**
	 * A print emits the ESC/POS stream and logs the configured printer.
	 *
	 * @return void
	 */
	public function testPrintEmitsEscPosAndLogsThePrinter(): void {
		$this->config['receipt_printer_host'] = '10.0.0.5';
		$this->template = ['name' => 'Standaard'];

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: 'tpl-1', userId: 'admin');

		$template = $this->entity(uuid: 'tpl-1', payload: $this->template)->jsonSerialize();
		$bytes = $this->renderer()->renderEscPos(transaction: $this->loaded(), template: $template);
		$this->assertSame(
			expected: [
				'status' => 'success',
				'escposBase64' => base64_encode($bytes),
				'byteLength' => strlen($bytes),
				'printerDevice' => '10.0.0.5:9100',
				'logId' => 'log-9',
			],
			actual: $result
		);
		$log = $this->savedTo(schema: 's-log')[0][0];
		$this->assertSame(expected: 'print', actual: $log['action']);
		$this->assertSame(expected: 'tpl-1', actual: $log['template']);
		$this->assertSame(expected: '10.0.0.5:9100', actual: $log['printerDevice']);
		$this->assertSame(expected: 'admin', actual: $log['actor']);
		$this->assertSame(
			expected: $this->renderer()->renderText(transaction: $this->loaded(), template: $template),
			actual: $log['renderedContent']
		);
	}//end testPrintEmitsEscPosAndLogsThePrinter()

	/**
	 * A configured port is used with the host.
	 *
	 * @return void
	 */
	public function testPrintUsesTheConfiguredPort(): void {
		$this->config['receipt_printer_host'] = 'printer.local';
		$this->config['receipt_printer_port'] = '9200';

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: 'printer.local:9200', actual: $result['printerDevice']);
	}//end testPrintUsesTheConfiguredPort()

	/**
	 * Without a printer host, there is no printer target.
	 *
	 * @return void
	 */
	public function testPrintWithoutAHostHasNoTarget(): void {
		$this->config['receipt_printer_port'] = '9200';

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: '', actual: $result['printerDevice']);
		$this->assertSame(expected: '', actual: $this->savedTo(schema: 's-log')[0][0]['printerDevice']);
	}//end testPrintWithoutAHostHasNoTarget()

	/**
	 * A refunded transaction can be printed.
	 *
	 * @return void
	 */
	public function testARefundedTransactionCanBePrinted(): void {
		$this->transaction['status'] = 'refunded';

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: 'success', actual: $result['status']);
	}//end testARefundedTransactionCanBePrinted()

	/**
	 * A parked transaction cannot be printed.
	 *
	 * @return void
	 */
	public function testAParkedTransactionCannotBePrinted(): void {
		$this->transaction['status'] = 'parked';
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');
	}//end testAParkedTransactionCannotBePrinted()

	/**
	 * An invoice sale gets a fresh number, persisted without lines or envelope.
	 *
	 * @return void
	 */
	public function testAnInvoiceSaleGetsAPersistedNumber(): void {
		$this->transaction['total'] = 250.0;
		$this->sequence->expects($this->once())->method('next')->willReturn('2026-000042');

		$this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$persisted = $this->savedTo(schema: 's-tx');
		$this->assertCount(expectedCount: 1, haystack: $persisted);
		[$object, $register, , $uuid] = $persisted[0];
		$this->assertSame(expected: 'reg-1', actual: $register);
		$this->assertSame(expected: 'tx-1', actual: $uuid);
		$this->assertSame(expected: '2026-000042', actual: $object['invoiceNumber']);
		$this->assertArrayNotHasKey(key: 'lines', array: $object);
		$this->assertArrayNotHasKey(key: '@self', array: $object);
		$this->assertSame(expected: '2026-000042', actual: $this->savedTo(schema: 's-log')[0][0]['invoiceNumber']);
	}//end testAnInvoiceSaleGetsAPersistedNumber()

	/**
	 * A number a prior log proves was server-issued is reused.
	 *
	 * @return void
	 */
	public function testAServerIssuedNumberIsReused(): void {
		$this->transaction['total'] = 250.0;
		$this->transaction['invoiceNumber'] = '2026-000007';
		$this->proofs = [['transaction' => 'tx-1', 'invoiceNumber' => '2026-000007']];
		$this->sequence->expects($this->never())->method('next');

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: 'success', actual: $result['status']);
		$this->assertSame(expected: [], actual: $this->savedTo(schema: 's-tx'));
		$this->assertSame(expected: '2026-000007', actual: $this->savedTo(schema: 's-log')[0][0]['invoiceNumber']);
	}//end testAServerIssuedNumberIsReused()

	/**
	 * A number no log proves is treated as forged and replaced.
	 *
	 * @return void
	 */
	public function testAnUnprovenNumberIsReplaced(): void {
		$this->transaction['total'] = 250.0;
		$this->transaction['invoiceNumber'] = 'FORGED-1';
		$this->sequence->expects($this->once())->method('next')->willReturn('2026-000043');

		$this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: '2026-000043', actual: $this->savedTo(schema: 's-tx')[0][0]['invoiceNumber']);
	}//end testAnUnprovenNumberIsReplaced()

	/**
	 * When the proof lookup fails, the number is not trusted.
	 *
	 * @return void
	 */
	public function testAFailedProofLookupFailsClosed(): void {
		$this->transaction['total'] = 250.0;
		$this->transaction['invoiceNumber'] = '2026-000007';
		$this->proofs = [['transaction' => 'tx-1']];
		$this->readFails = ['s-log'];
		$this->sequence->expects($this->once())->method('next')->willReturn('2026-000044');

		$this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: '2026-000044', actual: $this->savedTo(schema: 's-log')[0][0]['invoiceNumber']);
	}//end testAFailedProofLookupFailsClosed()

	/**
	 * A number that cannot be persisted is still used on the receipt, with an error logged.
	 *
	 * @return void
	 */
	public function testAnUnpersistableNumberIsStillUsed(): void {
		$this->transaction['total'] = 250.0;
		$this->writeFails = ['s-tx'];
		$this->sequence->method('next')->willReturn('2026-000045');
		$this->logger->expects($this->once())->method('error')
			->with('Pipelinq: failed to persist invoice number', ['exception' => 'write failed']);

		$result = $this->service->printReceipt(transactionId: 'tx-1', templateId: null, userId: 'pieter');

		$this->assertSame(expected: 'log-9', actual: $result['logId']);
		$this->assertSame(expected: '2026-000045', actual: $this->savedTo(schema: 's-log')[0][0]['invoiceNumber']);
	}//end testAnUnpersistableNumberIsStillUsed()

	/**
	 * An emailed invoice carries the allocated number in its body.
	 *
	 * @return void
	 */
	public function testAnEmailedInvoiceCarriesItsNumber(): void {
		$this->transaction['total'] = 250.0;
		$this->sequence->method('next')->willReturn('2026-000046');
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willReturn([]);
		$this->message->expects($this->once())->method('setPlainBody')->with(
			$this->renderer()->renderText(transaction: $this->loaded(extra: ['invoiceNumber' => '2026-000046']))
		);

		$this->service->emailReceipt(transactionId: 'tx-1', templateId: null, requestedRecipient: null, userId: 'pieter');

		$this->assertSame(expected: '2026-000046', actual: $this->savedTo(schema: 's-log')[0][0]['invoiceNumber']);
	}//end testAnEmailedInvoiceCarriesItsNumber()
}//end class
