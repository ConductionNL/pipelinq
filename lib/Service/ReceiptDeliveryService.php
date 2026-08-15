<?php

/**
 * Pipelinq ReceiptDeliveryService.
 *
 * Orchestrates receipt actions on a POS transaction: render a preview, email a
 * receipt to the transaction's customer, and emit an ESC/POS thermal stream.
 * Loads the transaction strictly from this app's own register + posTransaction
 * schema (a transaction in another app/register resolves to a 404, preventing
 * IDOR), assigns a server-authoritative legal invoice number for sales
 * >= EUR 100, and writes an immutable receiptPrintLog audit entry for every
 * action.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for receipt rendering, email delivery and thermal output.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  receipt orchestrator legitimately needs (OR container, renderer, invoice
 *  sequence, mailer, app config, logger); splitting them would add indirection
 *  without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is one cohesive
 *  receipt-issuance workflow (load + scope, invoice-number assignment, email,
 *  thermal print, customer-recipient validation and immutable audit logging)
 *  expressed as many small private helpers; the aggregate complexity reflects
 *  the workflow's breadth, not tangled logic, and splitting it would scatter a
 *  single transactional concern across several classes.
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */
class ReceiptDeliveryService {
	/**
	 * Statuses a transaction must be in before a receipt may be issued.
	 *
	 * A receipt is proof of a completed sale, so only fiscally-final
	 * transactions (confirmed / settled / refunded) qualify; drafts and parked
	 * carts cannot be printed or emailed.
	 *
	 * @var array<int, string>
	 */
	private const RECEIPTABLE = ['confirmed', 'settled', 'refunded'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param ReceiptService $receiptService The receipt renderer.
	 * @param InvoiceSequenceService $invoiceSequence The invoice number allocator.
	 * @param IMailer $mailer The Nextcloud mailer.
	 * @param IAppConfig $appConfig The app config.
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ReceiptService $receiptService,
		private InvoiceSequenceService $invoiceSequence,
		private IMailer $mailer,
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Render a receipt preview for a transaction (no side effects).
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string $userId The acting user UID (must be able to access the transaction).
	 *
	 * @return array<string, mixed> The preview: text, html, isInvoice, invoiceNumber(null for preview).
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.1
	 */
	public function preview(string $transactionId, ?string $templateId = null, string $userId = ''): array {
		$transaction = $this->loadTransactionWithLines(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);

		$text = $this->receiptService->renderText(transaction: $transaction, template: $template);
		$html = $this->receiptService->renderHtml(transaction: $transaction, template: $template);

		return [
			'text' => $text,
			'html' => $html,
			'isInvoice' => $this->receiptService->isInvoiceTransaction(transaction: $transaction),
			'reference' => (string)($transaction['reference'] ?? $transaction['id'] ?? ''),
			'customerEmail' => $this->customerEmail(transaction: $transaction),
		];
	}//end preview()

	/**
	 * Email a receipt to the transaction's customer.
	 *
	 * The recipient is NOT taken from arbitrary client input: it is the email
	 * stored on the transaction's linked customer/client, or — when the request
	 * supplies an address — only accepted if it matches that linked customer
	 * email. This prevents the endpoint being abused to send receipts (spam) to
	 * arbitrary addresses (REQ-PRE-002 / security constraint).
	 *
	 * SMTP delivery itself is environment-dependent; on a host without an SMTP
	 * relay the send throws and is recorded as a failed log entry. The send path
	 * is real — only the transport is environment-gated.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string|null $requestedRecipient Optional recipient the client asked for.
	 * @param string $userId The acting user UID (for the audit log).
	 *
	 * @return array<string, mixed> The result: status, emailRecipient, logId.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSBadRequestException If the transaction is not receiptable or has no customer email.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function emailReceipt(
		string $transactionId,
		?string $templateId,
		?string $requestedRecipient,
		string $userId,
	): array {
		$transaction = $this->loadReceiptableTransaction(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);
		$recipient = $this->resolveCustomerRecipient(transaction: $transaction, requested: $requestedRecipient);

		$transaction = $this->ensureInvoiceNumber(transaction: $transaction);
		$subject = $this->l10n->t('Your receipt') . ' ' . (string)($transaction['reference'] ?? '');
		$text = $this->receiptService->renderText(transaction: $transaction, template: $template);
		$html = $this->receiptService->renderHtml(transaction: $transaction, template: $template);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($text);
			$message->setHtmlBody($html);

			$sender = $this->appConfig->getValueString(Application::APP_ID, 'receipt_email_sender', '');
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender]);
			}

			$failed = $this->mailer->send($message);
			if (count($failed) > 0) {
				throw new RuntimeException('Mailer reported failed recipients.');
			}

			$logId = $this->writeLog(
				transaction: $transaction,
				template: $template,
				action: 'email',
				status: 'success',
				extra: [
					'emailRecipient' => $recipient,
					'renderedContent' => $text,
					'errorMessage' => null,
					'actor' => $userId,
				]
			);

			return ['status' => 'success', 'emailRecipient' => $recipient, 'logId' => $logId];
		} catch (\Throwable $e) {
			// Real send path; transport (SMTP) is environment-gated. Record the
			// failure for audit without leaking internal error detail upstream.
			$this->logger->warning(
				'Pipelinq: receipt email send failed (SMTP may be disabled on this host)',
				['exception' => $e->getMessage()]
			);

			$logId = $this->writeLog(
				transaction: $transaction,
				template: $template,
				action: 'email',
				status: 'failed',
				extra: [
					'emailRecipient' => $recipient,
					'errorMessage' => $this->l10n->t('Mail delivery failed (no SMTP relay configured).'),
					'renderedContent' => null,
					'actor' => $userId,
				]
			);

			return [
				'status' => 'failed',
				'emailRecipient' => $recipient,
				'logId' => $logId,
				'error' => $this->l10n->t('Mail delivery failed (no SMTP relay configured).'),
			];
		}//end try
	}//end emailReceipt()

	/**
	 * Produce the ESC/POS thermal byte stream for a transaction.
	 *
	 * Returns the bytes (base64-encoded for transport) plus the print log id.
	 * Live spooling to a device at a configured IP:port is environment-gated
	 * (no printer on a CI host) — the caller streams the bytes; here we generate
	 * and audit them.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string|null $templateId Optional template UUID.
	 * @param string $userId The acting user UID (for the audit log).
	 *
	 * @return array<string, mixed> The result: status, escposBase64, byteLength, logId.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSBadRequestException If the transaction is not receiptable.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	public function printReceipt(string $transactionId, ?string $templateId, string $userId): array {
		$transaction = $this->loadReceiptableTransaction(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);
		$transaction = $this->ensureInvoiceNumber(transaction: $transaction);

		$bytes = $this->receiptService->renderEscPos(transaction: $transaction, template: $template);
		$device = $this->appConfig->getValueString(Application::APP_ID, 'receipt_printer_host', '');
		$port = $this->appConfig->getValueString(Application::APP_ID, 'receipt_printer_port', '9100');
		$target = '';
		if ($device !== '') {
			$target = $device . ':' . $port;
		}

		$logId = $this->writeLog(
			transaction: $transaction,
			template: $template,
			action: 'print',
			status: 'success',
			extra: [
				'printerDevice' => $target,
				'renderedContent' => $this->receiptService->renderText(transaction: $transaction, template: $template),
				'errorMessage' => null,
				'actor' => $userId,
			]
		);

		return [
			'status' => 'success',
			'escposBase64' => base64_encode($bytes),
			'byteLength' => strlen($bytes),
			'printerDevice' => $target,
			'logId' => $logId,
		];
	}//end printReceipt()

	/**
	 * Resolve and validate the customer recipient for an email receipt.
	 *
	 * The receipt may only go to the transaction's linked customer. A client may
	 * pass an address but it is honoured only if it equals the customer's stored
	 * email — otherwise the request is rejected. This blocks the endpoint being
	 * used to send mail to arbitrary addresses.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 * @param string|null $requested The client-supplied recipient (optional).
	 *
	 * @return string The validated recipient address.
	 *
	 * @throws OCSBadRequestException If no customer email is available or the request mismatches it.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	private function resolveCustomerRecipient(array $transaction, ?string $requested): string {
		$customerEmail = $this->customerEmail(transaction: $transaction);
		if ($customerEmail === '') {
			throw new OCSBadRequestException(
				'Deze transactie heeft geen gekoppelde klant met een e-mailadres.'
			);
		}

		if ($requested !== null && trim($requested) !== '') {
			$requestedNorm = strtolower(trim($requested));
			if ($requestedNorm !== strtolower($customerEmail)) {
				throw new OCSBadRequestException(
					'Het bonnetje kan alleen naar het e-mailadres van de gekoppelde klant worden gestuurd.'
				);
			}
		}

		if ($this->mailer->validateMailAddress($customerEmail) === false) {
			throw new OCSBadRequestException('Ongeldig e-mailadres van de klant.');
		}

		return $customerEmail;
	}//end resolveCustomerRecipient()

	/**
	 * Look up the email of the transaction's linked client/customer.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return string The customer email, or '' when none is linked.
	 */
	private function customerEmail(array $transaction): string {
		// Inline customer email on the transaction (denormalised), if present.
		$inline = (string)($transaction['customerEmail'] ?? '');
		if ($inline !== '') {
			return $inline;
		}

		$clientId = (string)($transaction['client'] ?? '');
		if ($clientId === '') {
			return '';
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'client_schema');
			$client = $this->getObjectService()->find(id: $clientId, register: $register, schema: $schema);
			$client = $this->toArray(object: $client);

			return (string)($client['email'] ?? $client['emailAddress'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to resolve customer email', ['exception' => $e->getMessage()]);

			return '';
		}
	}//end customerEmail()

	/**
	 * Ensure a fiscal invoice number is assigned for a >= EUR 100 sale.
	 *
	 * Allocated once and persisted on the transaction; subsequent receipts reuse
	 * the same number so a reprint never mints a second invoice number for the
	 * same sale. The number is server-allocated and **never accepted from input**:
	 * because the posTransaction object is writable through OpenRegister's generic
	 * object API, a client could pre-seed a forged `invoiceNumber` field. We only
	 * trust an existing value when a prior immutable receiptPrintLog for this
	 * transaction recorded that exact number (proof the server issued it); any
	 * other pre-existing value is treated as forged and overwritten with a fresh
	 * server-allocated number.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return array<string, mixed> The transaction, possibly with invoiceNumber set.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	private function ensureInvoiceNumber(array $transaction): array {
		if ($this->receiptService->isInvoiceTransaction(transaction: $transaction) === false) {
			return $transaction;
		}

		$id = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
		$existing = (string)($transaction['invoiceNumber'] ?? '');
		if ($existing !== '' && $this->isServerIssuedInvoiceNumber(transactionId: $id, number: $existing) === true) {
			return $transaction;
		}

		$number = $this->invoiceSequence->next();
		$transaction['invoiceNumber'] = $number;

		// Persist the allocated number on the transaction so reprints are stable.
		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransaction_schema');
			$id = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
			// Never persist the embedded lines or @self envelope.
			$persist = $transaction;
			unset($persist['lines'], $persist['@self']);
			$this->getObjectService()->saveObject(
				object: $persist,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to persist invoice number', ['exception' => $e->getMessage()]);
		}//end try

		return $transaction;
	}//end ensureInvoiceNumber()

	/**
	 * Whether a given invoice number was previously issued by the server for
	 * this transaction, evidenced by an immutable receiptPrintLog entry.
	 *
	 * The audit log is server-written and append-only, so a match proves the
	 * number is authentic rather than a client-forged value injected onto the
	 * transaction object through the generic OpenRegister write API.
	 *
	 * @param string $transactionId The transaction UUID.
	 * @param string $number The candidate invoice number.
	 *
	 * @return bool Whether a prior log entry recorded this number for this transaction.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	private function isServerIssuedInvoiceNumber(string $transactionId, string $number): bool {
		if ($transactionId === '' || $number === '') {
			return false;
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptPrintLog_schema');
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'transaction' => $transactionId,
						'invoiceNumber' => $number,
					],
				]
			);
		} catch (\Throwable $e) {
			// Fail closed: if we cannot prove the number was server-issued, treat
			// it as unverified and let the caller allocate a fresh one.
			return false;
		}

		return count(($results ?? [])) > 0;
	}//end isServerIssuedInvoiceNumber()

	/**
	 * Write an immutable receiptPrintLog audit entry.
	 *
	 * Each call creates a NEW log object (append-only); entries are never
	 * updated, so the audit trail is immutable (REQ-PRE-005 / task 13.5).
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 * @param array<string, mixed> $template The template used (may be empty).
	 * @param string $action 'print' or 'email'.
	 * @param string $status 'success', 'failed' or 'pending'.
	 * @param array<string, mixed> $extra Action-specific fields.
	 *
	 * @return string The created log id (or '' on failure).
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	private function writeLog(array $transaction, array $template, string $action, string $status, array $extra): string {
		$log = array_merge(
			[
				'transaction' => (string)($transaction['id'] ?? $transaction['uuid'] ?? ''),
				'template' => (string)($template['id'] ?? $template['uuid'] ?? ''),
				'action' => $action,
				'status' => $status,
				'invoiceNumber' => (string)($transaction['invoiceNumber'] ?? ''),
				'printedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			],
			$extra
		);

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptPrintLog_schema');
			$saved = $this->getObjectService()->saveObject(
				object: $log,
				extend: [],
				register: $register,
				schema: $schema
			);
			$saved = $this->toArray(object: $saved);

			return (string)($saved['id'] ?? $saved['uuid'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to write receipt log', ['exception' => $e->getMessage()]);

			return '';
		}//end try
	}//end writeLog()

	/**
	 * Load a transaction (with its lines) scoped to this app's register.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed> The transaction with a `lines` array.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 */
	private function loadTransactionWithLines(string $id): array {
		[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		$transaction = $this->toArray(object: $object);
		$transaction['lines'] = $this->loadLines(transactionId: $id);

		return $transaction;
	}//end loadTransactionWithLines()

	/**
	 * Load a transaction and assert it is in a receiptable status.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed> The transaction with lines.
	 *
	 * @throws OCSNotFoundException If the transaction is not in this app's register.
	 * @throws OCSBadRequestException If the transaction is not confirmed/settled/refunded.
	 */
	private function loadReceiptableTransaction(string $id): array {
		$transaction = $this->loadTransactionWithLines(id: $id);
		$status = (string)($transaction['status'] ?? '');
		if (in_array($status, self::RECEIPTABLE, true) === false) {
			throw new OCSBadRequestException(
				'Een bonnetje kan alleen voor een bevestigde, afgerekende of teruggeboekte transactie worden gemaakt.'
			);
		}

		return $transaction;
	}//end loadReceiptableTransaction()

	/**
	 * Load a transaction's line items.
	 *
	 * @param string $transactionId The parent transaction UUID.
	 *
	 * @return array<int, array<string, mixed>> The line items.
	 */
	private function loadLines(string $transactionId): array {
		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransactionLine_schema');
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'transaction' => $transactionId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to load receipt lines', ['exception' => $e->getMessage()]);

			return [];
		}

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}//end loadLines()

	/**
	 * Load a receipt template by id, scoped to this app's register.
	 *
	 * Returns an empty template (default layout) when no id is supplied or the
	 * template cannot be found, so rendering always succeeds with a sane default.
	 *
	 * @param string|null $id The template UUID, or null.
	 *
	 * @return array<string, mixed> The template, or an empty array.
	 */
	private function loadTemplate(?string $id): array {
		if ($id === null || trim($id) === '') {
			return [];
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptTemplate_schema');
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);

			return $this->toArray(object: $object);
		} catch (\Throwable $e) {
			return [];
		}
	}//end loadTemplate()

	/**
	 * Assert the caller may access a transaction's receipt.
	 *
	 * A receipt exposes the full sale (line items, customer, totals), so it is
	 * scoped to the transaction's own cashier, a POS-group member, or an admin —
	 * the same per-object rule the lifecycle guards enforce. This closes the IDOR
	 * on the receipt endpoints, where any authenticated user could previously
	 * preview/email/print any transaction by UUID.
	 *
	 * @param array<string, mixed> $transaction The loaded transaction.
	 * @param string $userId The acting user UID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.1
	 */
	private function assertCanAccess(array $transaction, string $userId): void {
		if ($this->policy->canAccessTransaction(object: $transaction, userId: $userId) === false) {
			throw new OCSForbiddenException(
				'U mag het bonnetje van deze transactie niet inzien. Alleen de eigen '
				. 'kassamedewerker, een lid van de POS-groep of een beheerder is gemachtigd.'
			);
		}
	}//end assertCanAccess()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 */
	private function resolveSchema(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end resolveSchema()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()
}//end class
