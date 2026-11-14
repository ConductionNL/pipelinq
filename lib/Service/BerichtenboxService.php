<?php

/**
 * Pipelinq BerichtenboxService.
 *
 * Core message lifecycle orchestration for the Burgerportaal /
 * MijnOverheid Berichtenbox bridge. Owns the state machine
 * queued → (sent | fallback-emailed | failed | opted-out) → read
 * and the inbound reply handler that turns a Logius reply into a
 * new Contactmoment on the parent zaak.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 * @spec openspec/specs/berichtenbox/spec.md#REQ-MAILBOX-003
 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RECEIPT-005
 * @spec openspec/specs/berichtenbox/spec.md#REQ-INBOUND-006
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RETRY-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\BerichtenboxServiceInterface;
use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use OCA\Pipelinq\Service\Berichtenbox\MessageOptions;
use OCA\Pipelinq\Util\OptionalPayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Berichtenbox message lifecycle service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Orchestration root — by
 *  design ties together every collaborator (resolver, connector, logger,
 *  template renderer, fallback sender). Splitting would just shuffle
 *  coupling between two halves of a state machine.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is the state
 *  machine for the bridge; the requirement count drives the method count.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The state machine spans the
 *  full outbound/inbound/fallback/retry lifecycle; splitting would just shuffle
 *  the transitions across files.
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class BerichtenboxService implements BerichtenboxServiceInterface {
	use OptionalPayloadToArray;

	/**
	 * Maximum dispatch retry attempts (REQ-RETRY-012).
	 *
	 * @var int
	 */
	public const MAX_RETRIES = 5;

	/**
	 * Exponential-backoff delays (seconds) per retry (REQ-RETRY-012).
	 *
	 * @var array<int, int>
	 */
	public const RETRY_BACKOFF_SECONDS = [60, 300, 900, 3600, 14400];

	/**
	 * Default tenant id when none is configured.
	 *
	 * @var string
	 */
	public const DEFAULT_TENANT_ID = 'default';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param EncryptionService $encryption Encryption helper.
	 * @param TemplateRenderer $templateRenderer Template renderer.
	 * @param MailboxResolver $mailboxResolver Mailbox resolver.
	 * @param LogiusConnector $logius Logius API wrapper.
	 * @param EmailFallbackSender $emailFallback Email fallback sender.
	 * @param DeliveryAuditLogger $auditLogger Audit logger.
	 * @param DutchHolidayCalendar $holidayCalendar Working-day helper.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param LoggerInterface $logger NC logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Orchestration root wires every
	 *  bridge collaborator via constructor injection; a parameter object would only
	 *  hide the DI graph.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly EncryptionService $encryption,
		private readonly TemplateRenderer $templateRenderer,
		private readonly MailboxResolver $mailboxResolver,
		private readonly LogiusConnector $logius,
		private readonly EmailFallbackSender $emailFallback,
		private readonly DeliveryAuditLogger $auditLogger,
		private readonly DutchHolidayCalendar $holidayCalendar,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Queue an outbound message for a zaak status transition.
	 *
	 * @param CaseStatusMessage $message The case, its contact moment, the new status and the BSN.
	 * @param MessageOptions    $options An explicit template, extra variables and attachments.
	 *
	 * @return array The persisted BerichtenboxMessage object.
	 *
	 * @throws RuntimeException On validation / persistence failure.
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function queueOutboundMessage(CaseStatusMessage $message, MessageOptions $options = new MessageOptions()): array {
		$tenantId  = $this->resolveTenantId();
		$template  = $this->templateFor(message: $message, options: $options);
		$variables = $options->variablesOver(
			defaults: $message->variables(
				gemeente: $this->appConfig->getValueString(AppIdentity::APP_ID, 'tenant_display_name', 'Gemeente'),
				messageId: $this->logius->newUuidV4()
			)
		);

		$rendered    = $this->templateRenderer->render($template, $variables);
		$messageUuid = $variables['messageId'];
		$bsnHash     = $this->encryption->hashBsn($message->bsn, $tenantId);

		$saved = $this->saveMessage(
			payload: [
				'uuid' => $messageUuid,
				'bsn' => $this->encryption->encrypt($message->bsn, $tenantId),
				'bsnHash' => $bsnHash,
				'interactionId' => ($message->interactionId ?? ''),
				'caseId' => $message->caseId,
				'subject' => $rendered['subject'],
				'body' => $rendered['body'],
				'templateId' => (string)($template['id'] ?? $template['uuid'] ?? 'inline'),
				'attachments' => $options->attachments,
				'deliveryStatus' => 'queued',
				'retryCount' => 0,
				'mailboxAvailable' => null,
			]
		);
		$this->auditLogger->logQueued(
			messageId: $messageUuid,
			payloadHash: $this->auditLogger->hashPayload($rendered['body']),
			retentionUntil: $this->auditLogger->calculateRetentionUntil($options->caseType())
		);

		return $saved;
	}//end queueOutboundMessage()

	/**
	 * The explicit template, else the registry's template for the case type,
	 * status and language, else the fallback template.
	 *
	 * @param CaseStatusMessage $message The message.
	 * @param MessageOptions    $options Its options.
	 *
	 * @return array The template.
	 */
	private function templateFor(CaseStatusMessage $message, MessageOptions $options): array {
		return ($options->templateOverride ?? $this->findTemplate(message: $message, options: $options) ?? $this->fallbackTemplate());
	}//end templateFor()

	/**
	 * Dispatch all queued messages whose nextRetryAt is past (or unset).
	 *
	 * @param int $limit Maximum messages per run (default 100).
	 *
	 * @return int Number of messages processed.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function dispatchQueuedMessages(int $limit = 100): int {
		$messages = $this->findDispatchableMessages(limit: $limit);
		$processed = 0;
		foreach ($messages as $message) {
			try {
				$this->dispatchOne(message: $message);
			} catch (\Throwable $e) {
				$this->logger->error(
					'BerichtenboxService dispatch failed.',
					[
						'exception' => $e->getMessage(),
						'messageId' => (string)($message['uuid'] ?? ($message['id'] ?? '')),
					]
				);
			}

			$processed++;
		}

		return $processed;
	}//end dispatchQueuedMessages()

	/**
	 * Dispatch a single queued message.
	 *
	 * @param array $message OR object array form.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function dispatchOne(array $message): void {
		$tenantId = $this->resolveTenantId();
		$messageId = (string)($message['uuid'] ?? $message['id'] ?? '');
		$bsnPlain = $this->decryptBsn(row: $message, tenantId: $tenantId);
		$bodyHash = $this->auditLogger->hashPayload((string)($message['body'] ?? ''));

		$resolution = $this->mailboxResolver->resolve($bsnPlain, $tenantId);

		if ($resolution['mailboxAvailable'] === true && $resolution['optedOut'] === false) {
			$this->dispatchToBerichtenbox(
				message: $message,
				messageId: $messageId,
				bsnPlain: $bsnPlain,
				bodyHash: $bodyHash
			);
			return;
		}

		// Opted-out or no-mailbox → fallback path.
		$this->dispatchFallback(
			message: $message,
			messageId: $messageId,
			bodyHash: $bodyHash,
			optedOut: $resolution['optedOut']
		);
	}//end dispatchOne()

	/**
	 * Mark a delivered message as read in response to a Logius webhook.
	 *
	 * @param string $logiusMessageId Logius-assigned id.
	 * @param string $readAtIso Read timestamp (ISO 8601).
	 *
	 * @return bool True iff a matching message was found and updated.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handleReadReceipt(string $logiusMessageId, string $readAtIso): bool {
		$message = $this->findMessageByLogiusId(logiusMessageId: $logiusMessageId);
		if ($message === null) {
			$this->logger->warning(
				'Berichtenbox readReceipt for unknown logiusMessageId.',
				['logiusMessageId' => $logiusMessageId]
			);
			return false;
		}

		$message['readAt'] = $readAtIso;
		$message['deliveryStatus'] = 'read';
		$this->saveMessage(payload: $message);

		$this->auditLogger->logRead(
			messageId: (string)($message['uuid'] ?? $message['id'] ?? ''),
			payloadHash: $this->auditLogger->hashPayload((string)($message['body'] ?? ''))
		);
		return true;
	}//end handleReadReceipt()

	/**
	 * Run the daily 5-working-day fallback pass.
	 *
	 * @return int Number of fallback emails sent.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function processFallbackQueue(): int {
		$unread = $this->findUnreadSentMessages();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$sent = 0;
		foreach ($unread as $message) {
			if ($this->sendFiveDayFallback(message: $message, now: $now) === true) {
				$sent++;
			}
		}

		return $sent;
	}//end processFallbackQueue()

	/**
	 * Send the 5-day fallback email for one unread message when it is due.
	 *
	 * @param array             $message The unread sent message.
	 * @param DateTimeImmutable $now     The pass time.
	 *
	 * @return bool True when a fallback email was sent.
	 */
	private function sendFiveDayFallback(array $message, DateTimeImmutable $now): bool {
		if ($this->isFiveDayFallbackDue(message: $message, now: $now) === false) {
			return false;
		}

		$burgerEmail = $this->fiveDayFallbackEmail(message: $message);
		if ($burgerEmail === '') {
			return false;
		}

		if ($this->trySendFiveDayFallback(message: $message, burgerEmail: $burgerEmail) === false) {
			return false;
		}

		$this->markFallbackEmailed(message: $message, burgerEmail: $burgerEmail, now: $now);

		$this->auditLogger->logFallback(
			messageId: (string)($message['uuid'] ?? ''),
			reason: '5-day-unread',
			payloadHash: $this->auditLogger->hashPayload((string)($message['body'] ?? ''))
		);
		return true;
	}//end sendFiveDayFallback()

	/**
	 * Whether a sent message has been unread for 5 working days.
	 *
	 * @param array             $message The sent message.
	 * @param DateTimeImmutable $now     The pass time.
	 *
	 * @return bool
	 */
	private function isFiveDayFallbackDue(array $message, DateTimeImmutable $now): bool {
		$sentToBerichtenboxAt = (string)($message['sentToBerichtenboxAt'] ?? '');
		if ($sentToBerichtenboxAt === '') {
			return false;
		}

		try {
			$sentDate = new DateTimeImmutable($sentToBerichtenboxAt);
		} catch (\Throwable) {
			return false;
		}

		$eligibleAt = $this->holidayCalendar->addWorkingDays($sentDate, 5);
		return ($eligibleAt > $now) === false;
	}//end isFiveDayFallbackDue()

	/**
	 * The burger email for the 5-day fallback; logs and returns '' when there is none.
	 *
	 * @param array $message The sent message.
	 *
	 * @return string
	 */
	private function fiveDayFallbackEmail(array $message): string {
		$burgerEmail = (string)($message['burgerEmail'] ?? '');
		if ($burgerEmail === '') {
			$this->logger->info(
				'Berichtenbox 5-day fallback skipped: no burgerEmail.',
				['messageId' => (string)($message['uuid'] ?? '')]
			);
		}

		return $burgerEmail;
	}//end fiveDayFallbackEmail()

	/**
	 * Send the 5-day fallback email; false when refused or failed.
	 *
	 * @param array  $message     The sent message.
	 * @param string $burgerEmail The fallback address.
	 *
	 * @return bool True when the email went out.
	 */
	private function trySendFiveDayFallback(array $message, string $burgerEmail): bool {
		try {
			$accepted = $this->emailFallback->send($message, $burgerEmail, true);
			if ($accepted === false && $this->emailFallback->lastRefusal() !== null) {
				// Integriq refused it (opt-out-before-send): logged by the
				// sender, and the message status stays as it is.
				return false;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'5-day fallback email send failed.',
				['exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end trySendFiveDayFallback()

	/**
	 * Record a sent fallback email on the message and save it.
	 *
	 * @param array             $message     The message.
	 * @param string            $burgerEmail The fallback address.
	 * @param DateTimeImmutable $now         The send time.
	 *
	 * @return void
	 */
	private function markFallbackEmailed(array $message, string $burgerEmail, DateTimeImmutable $now): void {
		$message['deliveryStatus'] = 'fallback-emailed';
		$message['fallbackTriggeredAt'] = $now->format(DATE_ATOM);
		$message['fallbackEmail'] = $burgerEmail;
		$message['fallbackSentAt'] = $now->format(DATE_ATOM);
		$this->saveMessage(payload: $message);
	}//end markFallbackEmailed()

	/**
	 * Process an inbound reply from Logius — creates BerichtenboxReply,
	 * a new Contactmoment on the parent zaak, and routes it via
	 * skill-routing.
	 *
	 * @param string $parentMessageId Parent BerichtenboxMessage uuid.
	 * @param string $logiusReplyId Logius reply id.
	 * @param string $bodyText Plain-text reply.
	 * @param array $attachments Attachment list (BBK shape).
	 *
	 * @return array The created BerichtenboxReply payload.
	 *
	 * @throws RuntimeException If the parent cannot be found.
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function handleInboundReply(
		string $parentMessageId,
		string $logiusReplyId,
		string $bodyText,
		array $attachments = [],
	): array {
		$parent = $this->findMessageByUuid(uuid: $parentMessageId);
		if ($parent === null) {
			throw new RuntimeException(
				'Berichtenbox inbound reply: parent message not found: ' . $parentMessageId
			);
		}

		$reply = $this->newReply(parent: $parent, parentMessageId: $parentMessageId, logiusReplyId: $logiusReplyId);
		$reply['bodyText'] = $bodyText;
		$reply['attachments'] = $attachments;

		$reply = $this->persistNewReply(reply: $reply, bodyText: $bodyText);

		$reply = $this->linkReplyToContactmoment(parent: $parent, reply: $reply, bodyText: $bodyText);

		$this->auditLogger->logReplyReceived(
			replyId: $reply['uuid'],
			payloadHash: $this->auditLogger->hashPayload($bodyText)
		);

		return $reply;
	}//end handleInboundReply()

	/**
	 * Start a BerichtenboxReply for a parent message, re-encrypting the parent's BSN.
	 *
	 * @param array  $parent          The parent BerichtenboxMessage.
	 * @param string $parentMessageId Parent BerichtenboxMessage uuid.
	 * @param string $logiusReplyId   Logius reply id.
	 *
	 * @return array The reply without its body and attachments.
	 */
	private function newReply(array $parent, string $parentMessageId, string $logiusReplyId): array {
		$tenantId = $this->resolveTenantId();
		$bsnPlain = $this->decryptBsn(row: $parent, tenantId: $tenantId);
		$bsnHash = $this->encryption->hashBsn($bsnPlain, $tenantId);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		return [
			'uuid' => $this->logius->newUuidV4(),
			'parentMessageId' => $parentMessageId,
			'logiusReplyId' => $logiusReplyId,
			'receivedAt' => $now->format(DATE_ATOM),
			'bsn' => $this->encryption->encrypt($bsnPlain, $tenantId),
			'bsnHash' => $bsnHash,
		];
	}//end newReply()

	/**
	 * Save a new reply; a failed save is audited and rethrown.
	 *
	 * @param array  $reply    The reply.
	 * @param string $bodyText Plain-text reply.
	 *
	 * @return array The saved reply.
	 *
	 * @throws \Throwable When the save fails.
	 */
	private function persistNewReply(array $reply, string $bodyText): array {
		try {
			return $this->saveReply(payload: $reply);
		} catch (\Throwable $e) {
			$this->auditLogger->logProcessingError(
				messageId: $reply['uuid'],
				reason: $e->getMessage(),
				payloadHash: $this->auditLogger->hashPayload($bodyText)
			);
			throw $e;
		}
	}//end persistNewReply()

	/**
	 * Create the Contactmoment for a saved reply and record it on the reply.
	 *
	 * @param array  $parent   The parent BerichtenboxMessage.
	 * @param array  $reply    The saved reply.
	 * @param string $bodyText Plain-text reply.
	 *
	 * @return array The updated reply.
	 *
	 * @throws \Throwable When the Contactmoment cannot be created; the error is saved on the reply and audited.
	 */
	private function linkReplyToContactmoment(array $parent, array $reply, string $bodyText): array {
		try {
			$interactionId = $this->createContactmomentFromReply(parent: $parent, reply: $reply);
			$reply['createdInteractionId'] = $interactionId;
			$reply['processedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
			$this->saveReply(payload: $reply);
		} catch (\Throwable $e) {
			$reply['processingError'] = $e->getMessage();
			$this->saveReply(payload: $reply);
			$this->auditLogger->logProcessingError(
				messageId: $reply['uuid'],
				reason: $e->getMessage(),
				payloadHash: $this->auditLogger->hashPayload($bodyText)
			);
			throw $e;
		}

		return $reply;
	}//end linkReplyToContactmoment()

	/**
	 * Crypto-shred (re-encrypt with a destruction key) all rows for the
	 * given BSN, preserving audit history (REQ-KEYVAULT-015).
	 *
	 * @param string $bsn Plaintext BSN.
	 * @param string $tenantId Tenant id.
	 *
	 * @return int Number of rows shredded across all schemas.
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
	 */
	public function cryptoShred(
		#[\SensitiveParameter]
		string $bsn,
		string $tenantId,
	): int {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		// Shred key = 32 random bytes never persisted; we simply wipe the
		// bsn ciphertext to a random opaque blob that cannot be decrypted.
		$shredBlob = base64_encode(random_bytes(64));

		$total = 0;
		foreach (['berichtenboxMessage_schema', 'berichtenboxReply_schema', 'mailboxResolution_schema'] as $schemaKey) {
			$total += $this->shredSchema(schemaKey: $schemaKey, bsnHash: $bsnHash, shredBlob: $shredBlob);
		}

		return $total;
	}//end cryptoShred()

	/**
	 * Crypto-shred the rows of one schema that carry a BSN hash.
	 *
	 * @param string $schemaKey App config key of the schema.
	 * @param string $bsnHash   The BSN hash to match.
	 * @param string $shredBlob The opaque blob written over the BSN.
	 *
	 * @return int Number of rows shredded.
	 */
	private function shredSchema(string $schemaKey, string $bsnHash, string $shredBlob): int {
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($schemaId === '' || $registerId === '') {
			return 0;
		}

		$target = ['register' => $registerId, 'schema' => $schemaId, 'schemaKey' => $schemaKey];
		$rows = $this->findShredRows(target: $target, bsnHash: $bsnHash);

		$total = 0;
		foreach ($rows as $row) {
			if ($this->shredRow(row: $row, target: $target, shredBlob: $shredBlob) === true) {
				$total++;
			}
		}

		return $total;
	}//end shredSchema()

	/**
	 * Find the rows of a schema that carry a BSN hash ([] when the lookup fails).
	 *
	 * @param array{register: string, schema: string, schemaKey: string} $target  The register, schema and its config key.
	 * @param string                                                     $bsnHash The BSN hash to match.
	 *
	 * @return iterable<mixed>
	 */
	private function findShredRows(array $target, string $bsnHash): iterable {
		try {
			return $this->getObjectService()->findAll(
				config: ['filters' => ['register' => $target['register'], 'schema' => $target['schema'], 'bsnHash' => $bsnHash]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('cryptoShred lookup failed.', ['exception' => $e->getMessage(), 'schemaKey' => $target['schemaKey']]);
			return [];
		}
	}//end findShredRows()

	/**
	 * Overwrite one row's BSN with the shred blob and save it.
	 *
	 * @param mixed                                                      $row       The row.
	 * @param array{register: string, schema: string, schemaKey: string} $target    The register, schema and its config key.
	 * @param string                                                     $shredBlob The opaque blob written over the BSN.
	 *
	 * @return bool True when the row was shredded.
	 */
	private function shredRow(mixed $row, array $target, string $shredBlob): bool {
		$data = $this->toArray(object: $row);
		if ($data === null) {
			return false;
		}

		$data['bsn'] = $shredBlob;
		try {
			$this->getObjectService()->saveObject(
				object: $data,
				extend: [],
				register: $target['register'],
				schema: $target['schema'],
				uuid: ($data['uuid'] ?? null)
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('cryptoShred save failed.', ['exception' => $e->getMessage(), 'schemaKey' => $target['schemaKey']]);
			return false;
		}
	}//end shredRow()

	// ------------------------- Internal helpers ----------------------------

	/**
	 * Dispatch to Berichtenbox via Logius.
	 *
	 * @param array $message Message payload.
	 * @param string $messageId UUID.
	 * @param string $bsnPlain BSN.
	 * @param string $bodyHash SHA-256 of body.
	 *
	 * @return void
	 */
	private function dispatchToBerichtenbox(
		array $message,
		string $messageId,
		#[\SensitiveParameter]
		string $bsnPlain,
		string $bodyHash,
	): void {
		// Fail closed when the tenant's PKI-overheid material is missing.
		//
		// An empty key does NOT stop the dispatch on its own: LogiusConnector::
		// signRequest() falls back to `base64(sha256(body))` — a keyless digest
		// anyone can recompute — and sends it as the `X-Signature` header. The
		// message still goes out, carrying a plaintext BSN, with request
		// signing silently deactivated. The empty default is the permissive
		// value here, so refuse the dispatch and let the normal retry/fail path
		// record it instead of shipping an unsigned citizen message.
		$cert = $this->appConfig->getValueString(AppIdentity::APP_ID, 'pki_cert', '');
		$key = $this->appConfig->getValueString(AppIdentity::APP_ID, 'pki_key', '');
		if ($cert === '' || $key === '') {
			$this->logger->error(
				'Pipelinq Berichtenbox: refusing to dispatch — tenant PKI-overheid cert/key is not configured, '
				. 'so the Logius request would be sent with an unsigned placeholder signature',
				['messageId' => $messageId]
			);
			$this->markFailedOrRetry(
				message: $message,
				reason: 'Tenant PKI-overheid certificate or key is not configured.',
				bodyHash: $bodyHash
			);
			return;
		}

		try {
			$message['bsnPlain'] = $bsnPlain;
			$response = $this->logius->sendMessage($message, $cert, $key);
		} catch (\Throwable $e) {
			$this->markFailedOrRetry(message: $message, reason: $e->getMessage(), bodyHash: $bodyHash);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		unset($message['bsnPlain']);
		$message['sentToBerichtenboxAt'] = $now->format(DATE_ATOM);
		$message['logiusMessageId'] = $response['logiusMessageId'];
		$message['deliveryStatus'] = 'sent';
		$message['mailboxAvailable'] = true;
		$message['failureReason'] = '';
		$this->saveMessage(payload: $message);

		$this->auditLogger->logSent(
			messageId: $messageId,
			logiusMessageId: $response['logiusMessageId'],
			payloadHash: $bodyHash
		);
	}//end dispatchToBerichtenbox()

	/**
	 * Dispatch via email-fallback path (no-mailbox / opted-out).
	 *
	 * @param array $message Message payload.
	 * @param string $messageId UUID.
	 * @param string $bodyHash SHA-256 of body.
	 * @param bool $optedOut Whether the burger opted out.
	 *
	 * @return void
	 */
	private function dispatchFallback(
		array $message,
		string $messageId,
		string $bodyHash,
		bool $optedOut,
	): void {
		$reason = $this->fallbackReason(optedOut: $optedOut);

		$burgerEmail = (string)($message['burgerEmail'] ?? '');

		if ($burgerEmail === '') {
			$this->skipFallbackWithoutEmail(message: $message, messageId: $messageId, bodyHash: $bodyHash, optedOut: $optedOut);
			return;
		}

		if ($this->trySendFallback(message: $message, burgerEmail: $burgerEmail, bodyHash: $bodyHash) === false) {
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$this->markFallbackEmailed(message: $message, burgerEmail: $burgerEmail, now: $now);

		$this->auditLogger->logFallback(
			messageId: $messageId,
			reason: $reason,
			payloadHash: $bodyHash
		);
	}//end dispatchFallback()

	/**
	 * The fallback reason for a message: opted-out or no-mailbox.
	 *
	 * @param bool $optedOut Whether the burger opted out.
	 *
	 * @return string
	 */
	private function fallbackReason(bool $optedOut): string {
		$reason = 'no-mailbox';
		if ($optedOut === true) {
			$reason = 'opted-out';
		}

		return $reason;
	}//end fallbackReason()

	/**
	 * Log a fallback skipped for want of an email; an opted-out message is marked so.
	 *
	 * @param array  $message   Message payload.
	 * @param string $messageId UUID.
	 * @param string $bodyHash  SHA-256 of body.
	 * @param bool   $optedOut  Whether the burger opted out.
	 *
	 * @return void
	 */
	private function skipFallbackWithoutEmail(array $message, string $messageId, string $bodyHash, bool $optedOut): void {
		$this->logger->warning(
			'Berichtenbox fallback skipped: no email available.',
			['messageId' => $messageId, 'reason' => $this->fallbackReason(optedOut: $optedOut)]
		);
		if ($optedOut === true) {
			$message['deliveryStatus'] = 'opted-out';
			$this->saveMessage(payload: $message);
			$this->auditLogger->logOptedOut(
				messageId: $messageId,
				payloadHash: $bodyHash
			);
		}
	}//end skipFallbackWithoutEmail()

	/**
	 * Send the fallback email; a refusal or a failure (re-queued) returns false.
	 *
	 * @param array  $message     Message payload.
	 * @param string $burgerEmail The fallback address.
	 * @param string $bodyHash    SHA-256 of body.
	 *
	 * @return bool True when the email went out.
	 */
	private function trySendFallback(array $message, string $burgerEmail, string $bodyHash): bool {
		try {
			$accepted = $this->emailFallback->send($message, $burgerEmail, false);
			if ($accepted === false && $this->emailFallback->lastRefusal() !== null) {
				// Integriq refused it: logged by the sender, status unchanged.
				return false;
			}
		} catch (\Throwable $e) {
			$this->markFailedOrRetry(message: $message, reason: $e->getMessage(), bodyHash: $bodyHash);
			return false;
		}

		return true;
	}//end trySendFallback()

	/**
	 * Mark a message failed and re-queue with backoff, or hard-fail after
	 * the retry cap (REQ-RETRY-012).
	 *
	 * @param array $message Message payload.
	 * @param string $reason Failure reason.
	 * @param string $bodyHash SHA-256 of body.
	 *
	 * @return void
	 */
	private function markFailedOrRetry(array $message, string $reason, string $bodyHash): void {
		$retryCount = (int)($message['retryCount'] ?? 0);
		$messageId = (string)($message['uuid'] ?? '');
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		if ($retryCount >= self::MAX_RETRIES) {
			$message['deliveryStatus'] = 'failed';
			$message['failureReason'] = $reason;
			$this->saveMessage(payload: $message);
			$this->auditLogger->logFailed(
				messageId: $messageId,
				reason: $reason,
				payloadHash: $bodyHash
			);
			return;
		}

		$delay = (self::RETRY_BACKOFF_SECONDS[$retryCount] ?? 14400);
		$message['retryCount'] = ($retryCount + 1);
		$message['deliveryStatus'] = 'queued';
		$message['failureReason'] = $reason;
		$message['nextRetryAt'] = $now->modify('+' . $delay . ' seconds')->format(DATE_ATOM);
		$this->saveMessage(payload: $message);

		$this->auditLogger->logFailed(
			messageId: $messageId,
			reason: $reason . ' (retry ' . $message['retryCount'] . '/' . self::MAX_RETRIES . ')',
			payloadHash: $bodyHash
		);
	}//end markFailedOrRetry()

	/**
	 * Find queued messages eligible for dispatch.
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, array>
	 */
	private function findDispatchableMessages(int $limit): array {
		[$register, $schema] = $this->messageConfig();
		$rows = $this->findMessageRows(
			config: ['filters' => ['register' => $register, 'schema' => $schema, 'deliveryStatus' => 'queued'], 'limit' => $limit],
			failure: 'BerichtenboxService findDispatchable failed.'
		);

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$result = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data !== null && $this->isDueForRetry(data: $data, now: $now) === true) {
				$result[] = $data;
			}
		}

		return $result;
	}//end findDispatchableMessages()

	/**
	 * Find message rows; a failed lookup is logged and yields no rows.
	 *
	 * @param array  $config  The findAll config.
	 * @param string $failure The warning logged when the lookup fails.
	 *
	 * @return iterable<mixed>
	 */
	private function findMessageRows(array $config, string $failure): iterable {
		try {
			return $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning($failure, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end findMessageRows()

	/**
	 * Whether a queued message's nextRetryAt has passed (unset or unparseable counts as due).
	 *
	 * @param array             $data The message.
	 * @param DateTimeImmutable $now  The current time.
	 *
	 * @return bool
	 */
	private function isDueForRetry(array $data, DateTimeImmutable $now): bool {
		$nextRetryAt = ($data['nextRetryAt'] ?? '');
		if (is_string($nextRetryAt) === false || $nextRetryAt === '') {
			return true;
		}

		try {
			return (new DateTimeImmutable($nextRetryAt) > $now) === false;
		} catch (\Throwable) {
			// Treat unparseable as eligible.
			return true;
		}
	}//end isDueForRetry()

	/**
	 * Find unread sent messages for the 5-day fallback pass.
	 *
	 * @return array<int, array>
	 */
	private function findUnreadSentMessages(): array {
		[$register, $schema] = $this->messageConfig();
		$rows = $this->findMessageRows(
			config: ['filters' => ['register' => $register, 'schema' => $schema, 'deliveryStatus' => 'sent']],
			failure: 'BerichtenboxService findUnreadSent failed.'
		);

		$unread = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data !== null && $this->isUnread(data: $data) === true) {
				$unread[] = $data;
			}
		}

		return $unread;
	}//end findUnreadSentMessages()

	/**
	 * Whether a sent message has no readAt.
	 *
	 * @param array $data The message.
	 *
	 * @return bool
	 */
	private function isUnread(array $data): bool {
		return ($data['readAt'] ?? '') === '' || $data['readAt'] === null;
	}//end isUnread()

	/**
	 * Find a message by logiusMessageId.
	 *
	 * @param string $logiusMessageId Logius id.
	 *
	 * @return array|null
	 */
	private function findMessageByLogiusId(string $logiusMessageId): ?array {
		[$register, $schema] = $this->messageConfig();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'logiusMessageId' => $logiusMessageId,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			return $this->toArray(object: $row);
		}

		return null;
	}//end findMessageByLogiusId()

	/**
	 * Find a message by uuid.
	 *
	 * @param string $uuid Message uuid.
	 *
	 * @return array|null
	 */
	private function findMessageByUuid(string $uuid): ?array {
		[$register, $schema] = $this->messageConfig();
		try {
			$row = $this->getObjectService()->find(id: $uuid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->toArray(object: $row);
	}//end findMessageByUuid()

	/**
	 * Look up the registry template for a message's case type, status and
	 * language; none when the template store is not configured, has no
	 * match, or the lookup fails.
	 *
	 * @param CaseStatusMessage $message The message, for its status.
	 * @param MessageOptions    $options Its options, for the case type and language.
	 *
	 * @return array|null The template, or null.
	 */
	private function findTemplate(CaseStatusMessage $message, MessageOptions $options): ?array {
		$scope = $this->templateScope();
		if ($scope === null) {
			return null;
		}

		return $this->firstTemplate(
			filters: $scope + [
				'caseType' => $options->caseType(),
				'status' => $message->status,
				'language' => $options->language(),
			]
		);
	}//end findTemplate()

	/**
	 * The register and template schema the templates are kept in, or null
	 * when either is not configured.
	 *
	 * @return array{register: string, schema: string}|null The scope.
	 */
	private function templateScope(): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'berichtenboxTemplate_schema',
			''
		);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end templateScope()

	/**
	 * The first template matching the filters, or null when none matches
	 * or the lookup fails.
	 *
	 * @param array<string, string> $filters The template filters.
	 *
	 * @return array|null The template, or null.
	 */
	private function firstTemplate(array $filters): ?array {
		try {
			$rows = $this->getObjectService()->findAll(config: ['filters' => $filters, 'limit' => 1]);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			return $this->toArray(object: $row);
		}

		return null;
	}//end firstTemplate()

	/**
	 * Return a minimal default template when no match is configured.
	 *
	 * The template body uses mustache placeholders ({{zaakId}}, {{status}},
	 * {{gemeente}}) that are filled at render time by TemplateRenderer::render().
	 * No zaakId/status parameters needed here — they come from the render context.
	 *
	 * @return array
	 */
	private function fallbackTemplate(): array {
		return [
			'id' => 'fallback',
			'subject' => 'Status update voor zaak {{zaakId}}',
			'body' => '<p>Geachte burger,</p>'
				. '<p>De status van uw zaak {{zaakId}} is nu: <strong>{{status}}</strong>.</p>'
				. '<p>Met vriendelijke groet,<br/>{{gemeente}}</p>',
			'requiresDeepLink' => false,
		];
	}//end fallbackTemplate()

	/**
	 * Decrypt the stored BSN ciphertext on a message row.
	 *
	 * @param array $row Message/Reply row.
	 * @param string $tenantId Tenant.
	 *
	 * @return string Plaintext BSN.
	 */
	private function decryptBsn(array $row, string $tenantId): string {
		$ciphertext = (string)($row['bsn'] ?? '');
		if ($ciphertext === '') {
			return '';
		}

		return $this->encryption->decrypt($ciphertext, $tenantId);
	}//end decryptBsn()

	/**
	 * Create a Contactmoment row from an inbound reply, routed via
	 * skill-routing.
	 *
	 * The contactmoment is a `ticket` with `ticketType: contactmoment`
	 * (unify-ticket-supertype); the write is delegated to TicketService, which
	 * resolves the unified schema and stamps the discriminator. The legacy
	 * contactmoment fields are written under their ticket names
	 * (subject -> title, summary -> description).
	 *
	 * @param array $parent Parent BerichtenboxMessage.
	 * @param array $reply Reply payload.
	 *
	 * @return string Contactmoment uuid.
	 *
	 * @throws RuntimeException If the ticket register or schema is not configured.
	 */
	private function createContactmomentFromReply(array $parent, array $reply): string {
		if ($this->ticketService->isConfigured() === false) {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		$payload = [
			'title' => 'Re: ' . ((string)($parent['subject'] ?? '')),
			'description' => (string)($reply['bodyText'] ?? ''),
			'channel' => 'berichtenbox',
			// A Berichtenbox reply is the citizen writing to us, so the
			// direction is fixed rather than passed in: there is no path
			// through this method that records an outbound message.
			'direction' => 'inbound',
			'outcome' => 'opvolging-nodig',
			'caseId' => (string)($parent['caseId'] ?? ''),
			'parentMessageId' => (string)($parent['uuid'] ?? ''),
			'berichtenboxReplyId' => (string)($reply['uuid'] ?? ''),
		];

		$saved = $this->ticketService->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: $payload,
			uuid: null
		);
		$savedArray = $this->toArray(object: $saved);
		$uuid = (string)($savedArray['uuid'] ?? $savedArray['id'] ?? '');

		// Routing — best-effort; the skill-routing service is optional in dev.
		try {
			if ($this->container->has('OCA\\Pipelinq\\Service\\QueueRoutingService') === true) {
				$router = $this->container->get('OCA\\Pipelinq\\Service\\QueueRoutingService');
				if (method_exists($router, 'route') === true) {
					$router->route($savedArray);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox reply routing failed.',
				['exception' => $e->getMessage()]
			);
		}

		return $uuid;
	}//end createContactmomentFromReply()

	/**
	 * Persist a BerichtenboxMessage.
	 *
	 * @param array $payload Payload.
	 *
	 * @return array
	 */
	private function saveMessage(array $payload): array {
		[$register, $schema] = $this->messageConfig();
		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: ($payload['uuid'] ?? null)
		);
		return ($this->toArray(object: $saved) ?? $payload);
	}//end saveMessage()

	/**
	 * Persist a BerichtenboxReply.
	 *
	 * @param array $payload Payload.
	 *
	 * @return array
	 */
	private function saveReply(array $payload): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'berichtenboxReply_schema',
			''
		);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('BerichtenboxReply register or schema not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: ($payload['uuid'] ?? null)
		);
		return ($this->toArray(object: $saved) ?? $payload);
	}//end saveReply()

	/**
	 * Resolve register + message schema config.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function messageConfig(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'berichtenboxMessage_schema',
			''
		);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('BerichtenboxMessage register or schema not configured.');
		}

		return [$register, $schema];
	}//end messageConfig()

	/**
	 * Resolve the tenant id from app config.
	 *
	 * @return string
	 */
	private function resolveTenantId(): string {
		$tenant = $this->appConfig->getValueString(AppIdentity::APP_ID, 'tenant_id', '');
		if ($tenant === '') {
			return self::DEFAULT_TENANT_ID;
		}

		return $tenant;
	}//end resolveTenantId()

	/**
	 * Get OR ObjectService.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface
	 */
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
