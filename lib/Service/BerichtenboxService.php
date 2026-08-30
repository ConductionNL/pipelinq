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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-003
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-receipt-005
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-inbound-006
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-retry-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
 */
class BerichtenboxService {
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
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
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
	 * @param string $caseId External zaak id.
	 * @param string|null $interactionId Linked Contactmoment id (optional).
	 * @param string $status New zaak status.
	 * @param string $bsn Burger's plaintext BSN (validated upstream).
	 * @param array|null $templateOverride Optional explicit BerichtenboxTemplate
	 *                                     payload (skips registry lookup).
	 * @param array $extraVariables Additional template variables
	 *                              (e.g. gemeente, deadline).
	 * @param array $attachments Attachment array per BBK 1.7
	 *                           (filename / mime / sizeBytes
	 *                           / openregisterFileRef).
	 *
	 * @return array The persisted BerichtenboxMessage object.
	 *
	 * @throws RuntimeException On validation / persistence failure.
	 *
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
	 */
	public function queueOutboundMessage(
		string $caseId,
		?string $interactionId,
		string $status,
		string $bsn,
		?array $templateOverride = null,
		array $extraVariables = [],
		array $attachments = [],
	): array {
		$tenantId = $this->resolveTenantId();
		$template = ($templateOverride ?? $this->findTemplate(
			caseType: (string)($extraVariables['caseType'] ?? ''),
			status: $status,
			language: (string)($extraVariables['language'] ?? 'nl')
		));
		$template = ($template ?? $this->fallbackTemplate());

		$variables = array_merge(
			[
				'caseId' => $caseId,
				'status' => $status,
				'gemeente' => $this->appConfig->getValueString(
					Application::APP_ID,
					'tenant_display_name',
					'Gemeente'
				),
				'deadline' => '',
				'messageId' => $this->logius->newUuidV4(),
			],
			$extraVariables
		);

		$rendered = $this->templateRenderer->render($template, $variables);

		$messageUuid = $variables['messageId'];
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);

		$payload = [
			'uuid' => $messageUuid,
			'bsn' => $this->encryption->encrypt($bsn, $tenantId),
			'bsnHash' => $bsnHash,
			'interactionId' => ($interactionId ?? ''),
			'caseId' => $caseId,
			'subject' => $rendered['subject'],
			'body' => $rendered['body'],
			'templateId' => (string)($template['id'] ?? $template['uuid'] ?? 'inline'),
			'attachments' => $attachments,
			'deliveryStatus' => 'queued',
			'retryCount' => 0,
			'mailboxAvailable' => null,
		];

		$saved = $this->saveMessage(payload: $payload);
		$this->auditLogger->logQueued(
			messageId: $messageUuid,
			payloadHash: $this->auditLogger->hashPayload($rendered['body']),
			retentionUntil: $this->auditLogger->calculateRetentionUntil((string)($extraVariables['caseType'] ?? ''))
		);

		return $saved;
	}//end queueOutboundMessage()

	/**
	 * Dispatch all queued messages whose nextRetryAt is past (or unset).
	 *
	 * @param int $limit Maximum messages per run (default 100).
	 *
	 * @return int Number of messages processed.
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
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
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
	 */
	public function processFallbackQueue(): int {
		$unread = $this->findUnreadSentMessages();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$sent = 0;
		foreach ($unread as $message) {
			$sentToBerichtenboxAt = (string)($message['sentToBerichtenboxAt'] ?? '');
			if ($sentToBerichtenboxAt === '') {
				continue;
			}

			try {
				$sentDate = new DateTimeImmutable($sentToBerichtenboxAt);
			} catch (\Throwable) {
				continue;
			}

			$eligibleAt = $this->holidayCalendar->addWorkingDays($sentDate, 5);
			if ($eligibleAt > $now) {
				continue;
			}

			$burgerEmail = (string)($message['burgerEmail'] ?? '');
			if ($burgerEmail === '') {
				$this->logger->info(
					'Berichtenbox 5-day fallback skipped: no burgerEmail.',
					['messageId' => (string)($message['uuid'] ?? '')]
				);
				continue;
			}

			try {
				$this->emailFallback->send($message, $burgerEmail, true);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'5-day fallback email send failed.',
					['exception' => $e->getMessage()]
				);
				continue;
			}

			$message['deliveryStatus'] = 'fallback-emailed';
			$message['fallbackTriggeredAt'] = $now->format(DATE_ATOM);
			$message['fallbackEmail'] = $burgerEmail;
			$message['fallbackSentAt'] = $now->format(DATE_ATOM);
			$this->saveMessage(payload: $message);

			$this->auditLogger->logFallback(
				messageId: (string)($message['uuid'] ?? ''),
				reason: '5-day-unread',
				payloadHash: $this->auditLogger->hashPayload((string)($message['body'] ?? ''))
			);
			$sent++;
		}//end foreach

		return $sent;
	}//end processFallbackQueue()

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
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
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

		$tenantId = $this->resolveTenantId();
		$bsnPlain = $this->decryptBsn(row: $parent, tenantId: $tenantId);
		$bsnHash = $this->encryption->hashBsn($bsnPlain, $tenantId);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$reply = [
			'uuid' => $this->logius->newUuidV4(),
			'parentMessageId' => $parentMessageId,
			'logiusReplyId' => $logiusReplyId,
			'receivedAt' => $now->format(DATE_ATOM),
			'bsn' => $this->encryption->encrypt($bsnPlain, $tenantId),
			'bsnHash' => $bsnHash,
			'bodyText' => $bodyText,
			'attachments' => $attachments,
		];

		try {
			$reply = $this->saveReply(payload: $reply);
		} catch (\Throwable $e) {
			$this->auditLogger->logProcessingError(
				messageId: $reply['uuid'],
				reason: $e->getMessage(),
				payloadHash: $this->auditLogger->hashPayload($bodyText)
			);
			throw $e;
		}

		$interactionId = null;
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

		$this->auditLogger->logReplyReceived(
			replyId: $reply['uuid'],
			payloadHash: $this->auditLogger->hashPayload($bodyText)
		);

		return $reply;
	}//end handleInboundReply()

	/**
	 * Crypto-shred (re-encrypt with a destruction key) all rows for the
	 * given BSN, preserving audit history (REQ-KEYVAULT-015).
	 *
	 * @param string $bsn Plaintext BSN.
	 * @param string $tenantId Tenant id.
	 *
	 * @return int Number of rows shredded across all schemas.
	 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
	 */
	public function cryptoShred(string $bsn, string $tenantId): int {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		// Shred key = 32 random bytes never persisted; we simply wipe the
		// bsn ciphertext to a random opaque blob that cannot be decrypted.
		$shredBlob = base64_encode(random_bytes(64));

		$total = 0;
		foreach (['berichtenboxMessage_schema', 'berichtenboxReply_schema', 'mailboxResolution_schema'] as $schemaKey) {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
			if ($schemaId === '' || $registerId === '') {
				continue;
			}

			try {
				$rows = $this->getObjectService()->findAll(
					config: [
						'filters' => [
							'register' => $registerId,
							'schema' => $schemaId,
							'bsnHash' => $bsnHash,
						],
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'cryptoShred lookup failed.',
					['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
				);
				continue;
			}

			foreach ($rows as $row) {
				$data = $this->toArray(row: $row);
				if ($data === null) {
					continue;
				}

				$data['bsn'] = $shredBlob;
				try {
					$this->getObjectService()->saveObject(
						object: $data,
						extend: [],
						register: $registerId,
						schema: $schemaId,
						uuid: ($data['uuid'] ?? null)
					);
					$total++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'cryptoShred save failed.',
						['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
					);
				}
			}//end foreach
		}//end foreach

		return $total;
	}//end cryptoShred()

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
		$cert = $this->appConfig->getValueString(Application::APP_ID, 'pki_cert', '');
		$key = $this->appConfig->getValueString(Application::APP_ID, 'pki_key', '');
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
		$reason = 'no-mailbox';
		if ($optedOut === true) {
			$reason = 'opted-out';
		}

		$burgerEmail = (string)($message['burgerEmail'] ?? '');

		if ($burgerEmail === '') {
			$this->logger->warning(
				'Berichtenbox fallback skipped: no email available.',
				['messageId' => $messageId, 'reason' => $reason]
			);
			if ($optedOut === true) {
				$message['deliveryStatus'] = 'opted-out';
				$this->saveMessage(payload: $message);
				$this->auditLogger->logOptedOut(
					messageId: $messageId,
					payloadHash: $bodyHash
				);
			}

			return;
		}

		try {
			$this->emailFallback->send($message, $burgerEmail, false);
		} catch (\Throwable $e) {
			$this->markFailedOrRetry(message: $message, reason: $e->getMessage(), bodyHash: $bodyHash);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$message['deliveryStatus'] = 'fallback-emailed';
		$message['fallbackTriggeredAt'] = $now->format(DATE_ATOM);
		$message['fallbackEmail'] = $burgerEmail;
		$message['fallbackSentAt'] = $now->format(DATE_ATOM);
		$this->saveMessage(payload: $message);

		$this->auditLogger->logFallback(
			messageId: $messageId,
			reason: $reason,
			payloadHash: $bodyHash
		);
	}//end dispatchFallback()

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
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'deliveryStatus' => 'queued',
					],
					'limit' => $limit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BerichtenboxService findDispatchable failed.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$result = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$nextRetryAt = ($data['nextRetryAt'] ?? '');
			if (is_string($nextRetryAt) === true && $nextRetryAt !== '') {
				try {
					if (new DateTimeImmutable($nextRetryAt) > $now) {
						continue;
					}
				} catch (\Throwable) {
					// Treat unparseable as eligible.
				}
			}

			$result[] = $data;
		}

		return $result;
	}//end findDispatchableMessages()

	/**
	 * Find unread sent messages for the 5-day fallback pass.
	 *
	 * @return array<int, array>
	 */
	private function findUnreadSentMessages(): array {
		[$register, $schema] = $this->messageConfig();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'deliveryStatus' => 'sent',
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BerichtenboxService findUnreadSent failed.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$unread = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			if (($data['readAt'] ?? '') !== '' && $data['readAt'] !== null) {
				continue;
			}

			$unread[] = $data;
		}

		return $unread;
	}//end findUnreadSentMessages()

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
			return $this->toArray(row: $row);
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

		return $this->toArray(row: $row);
	}//end findMessageByUuid()

	/**
	 * Find a matching template for zaaktype + status + language.
	 *
	 * @param string $caseType zaaktype slug.
	 * @param string $status status.
	 * @param string $language language.
	 *
	 * @return array|null
	 */
	private function findTemplate(string $caseType, string $status, string $language): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'berichtenboxTemplate_schema',
			''
		);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'caseType' => $caseType,
						'status' => $status,
						'language' => $language,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			return $this->toArray(row: $row);
		}

		return null;
	}//end findTemplate()

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
		$savedArray = $this->toArray(row: $saved);
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
		return ($this->toArray(row: $saved) ?? $payload);
	}//end saveMessage()

	/**
	 * Persist a BerichtenboxReply.
	 *
	 * @param array $payload Payload.
	 *
	 * @return array
	 */
	private function saveReply(array $payload): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
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
		return ($this->toArray(row: $saved) ?? $payload);
	}//end saveReply()

	/**
	 * Resolve register + message schema config.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function messageConfig(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
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
		$tenant = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
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

	/**
	 * Normalise OR result to array.
	 *
	 * @param mixed $row Row.
	 *
	 * @return array|null
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialized = $row->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}//end toArray()
}//end class
