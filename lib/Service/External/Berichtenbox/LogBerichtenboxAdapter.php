<?php

/**
 * Dormant default Pipelinq Berichtenbox / Logius BBK 1.7 adapter.
 *
 * Records the would-be BBK 1.7 dispatch / webhook-verify /
 * mailbox-check intent to the structured logger and returns a
 * synthetic DISPATCH_DEFERRED / VERIFY_DEFERRED / MAILBOX_DEFERRED
 * result so the surrounding lifecycle
 * (`BerichtenboxService::dispatchMessage()`,
 * `BerichtenboxWebhookController` delivery-receipt persistence,
 * `EmailFallbackSender` fallback threshold,
 * `MailboxResolver::isReachable()`) stays observable until either
 * the existing `LogiusConnector`-backed binding OR an
 * openconnector-routed binding is wired in via
 * `Application::register()`.
 *
 * Mirrors the wave-3 `LogIb47Adapter` / `LogCbsBestandenAdapter`
 * dormant-default pattern from the fleet's external surface.
 *
 * ⚠️ THE SEAM IS UNCONSUMED, AND THE CAPABILITY IT MODELS IS ALREADY
 * LIVE ELSEWHERE. `BerichtenboxAdapterInterface` is DI-bound in
 * `Application::register()` but injected into no service or controller,
 * so `checkMailbox()` has zero callers and hydra gate-6 (orphan-auth)
 * reports it. Do NOT close that finding by wiring
 * `MailboxResolver::resolve()` to this adapter: that path already calls
 * `LogiusConnector::checkMailboxExists()`, which performs a REAL
 * mailbox check, so routing it here would replace a live check with a
 * `MAILBOX_DEFERRED` stub — a regression wearing the costume of a fix.
 * Consume-or-remove is a product decision, tracked in pipelinq#764
 * ("Decision needed: consume-or-remove the 7 dormant capabilities behind
 * gate-6 and gate-57").
 *
 * Per AVG / WBP article 9, BSN values are NEVER passed to the
 * structured logger. Outbound `recipientBsn` + `dispatchMessage`'s
 * BSN payload field + `checkMailbox`'s `bsn` argument are all
 * redacted before being logged.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\External\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\External\Berichtenbox;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Pipelinq Berichtenbox / Logius BBK 1.7 adapter.
 *
 * @spec openspec/specs/berichtenbox/spec.md
 */
class LogBerichtenboxAdapter implements BerichtenboxAdapterInterface {

	/**
	 * What to bind for real transport.
	 *
	 * @var string
	 */
	private const NOTE_DISPATCH = 'Bind LogiusConnector (or an openconnector source slug `logius-berichtenbox`) by overriding '
		. 'BerichtenboxAdapterInterface in Application::register() to enable real transport.';

	/**
	 * What to bind for real webhook verification.
	 *
	 * @var string
	 */
	private const NOTE_VERIFY = 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() '
		. 'to enable real webhook HMAC verification.';

	/**
	 * What to bind for real mailbox checks.
	 *
	 * @var string
	 */
	private const NOTE_MAILBOX = 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() '
		. 'to enable real mailbox reachability checks. NEVER log BSN values.';
	/**
	 * Construct the log-backed Berichtenbox adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the dispatch intent + synthesise a DISPATCH_DEFERRED result.
	 *
	 * The `recipientBsn` field + any attached body bytes are redacted
	 * before logging — BSN per AVG, body bytes to avoid spilling
	 * official correspondence into the logger.
	 *
	 * @param array<string,mixed> $message BBK 1.7 envelope.
	 *
	 * @return BerichtenboxResult The dispatch outcome.
	 * @spec openspec/specs/berichtenbox/spec.md
	 */
	public function dispatchMessage(array $message): BerichtenboxResult {
		$reference = 'bbk-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Pipelinq Berichtenbox dispatch deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'message' => $this->redacted(message: $message),
			]
		);

		return $this->deferred(outcome: 'DISPATCH_DEFERRED', trail: ['reference' => $reference, 'note' => self::NOTE_DISPATCH]);
	}//end dispatchMessage()

	/**
	 * The message as it may be logged: the BSN replaced, the body by its
	 * byte length, a list of attachments by its count.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<string, mixed>
	 */
	private function redacted(array $message): array {
		if (isset($message['recipientBsn']) === true) {
			$message['recipientBsn'] = '[REDACTED]';
		}

		if (isset($message['body']) === true) {
			$message['body'] = '[REDACTED-body-bytes=' . strlen((string)$message['body']) . ']';
		}

		if (isset($message['attachments']) === true && is_array($message['attachments']) === true) {
			$message['attachments'] = ['_redacted' => true, 'count' => count($message['attachments'])];
		}

		return $message;
	}//end redacted()

	/**
	 * A deferred result: dormant, under the logged reference, saying no
	 * outbound connector is bound and what to bind.
	 *
	 * @param string                                $outcome The deferred outcome.
	 * @param array{reference: string, note: string} $trail   The logged reference and the note.
	 *
	 * @return BerichtenboxResult
	 */
	private function deferred(string $outcome, array $trail): BerichtenboxResult {
		return new BerichtenboxResult(
			outcome: $outcome,
			logiusReference: $trail['reference'],
			dormant: true,
			extras: ['reason' => 'no-outbound-connector-bound', 'note' => $trail['note']],
		);
	}//end deferred()

	/**
	 * Log the webhook-verify intent + synthesise a VERIFY_DEFERRED
	 * result.
	 *
	 * The raw body is NOT logged — it may contain delivery receipts
	 * with PII; only the body length + the presence-of-signature
	 * boolean go through.
	 *
	 * @param string $rawBody Raw inbound body bytes.
	 * @param array<string,string> $headers Inbound headers.
	 *
	 * @return BerichtenboxResult The verification outcome.
	 * @spec openspec/specs/berichtenbox/spec.md
	 */
	public function verifyDeliveryWebhook(string $rawBody, array $headers): BerichtenboxResult {
		$signature = $headers['X-Logius-Signature'] ?? ($headers['x-logius-signature'] ?? '');
		unset($headers['X-Logius-Signature'], $headers['x-logius-signature']);

		$reference = 'bbk-verify-log-' . bin2hex(random_bytes(6));
		$this->logger->info(
			'Pipelinq Berichtenbox verifyDeliveryWebhook deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'bodyLength' => strlen($rawBody),
				'signaturePresent' => ($signature !== ''),
				'headers' => $headers,
			]
		);

		return $this->deferred(outcome: 'VERIFY_DEFERRED', trail: ['reference' => $reference, 'note' => self::NOTE_VERIFY]);
	}//end verifyDeliveryWebhook()

	/**
	 * Log the mailbox-check intent + synthesise a MAILBOX_DEFERRED
	 * result.
	 *
	 * The BSN value is NEVER passed to the structured logger
	 * (AVG / WBP art. 9); only a redaction marker + length-check
	 * boolean go through.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer.
	 *
	 * @return BerichtenboxResult The mailbox-status outcome.
	 *
	 * @orphan-auth exclude belongs to the log-only Berichtenbox adapter, the stand-in used until a real
	 * mailbox integration lands; the method is the shape that integration will
	 * call. Pending in pipelinq#764.
	 * @spec openspec/specs/berichtenbox/spec.md
	 */
	public function checkMailbox(
		#[\SensitiveParameter]
		string $bsn,
	): BerichtenboxResult {
		$reference = 'bbk-mbox-log-' . bin2hex(random_bytes(6));
		$this->logger->info(
			'Pipelinq Berichtenbox checkMailbox deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'bsn' => '[REDACTED]',
				'bsn_length_check' => (strlen($bsn) === 9),
			]
		);

		return $this->deferred(outcome: 'MAILBOX_DEFERRED', trail: ['reference' => $reference, 'note' => self::NOTE_MAILBOX]);
	}//end checkMailbox()

	/**
	 * Report whether this adapter is a dormant log-only stub.
	 *
	 * @inheritDoc
	 *
	 * @return bool Always true for the log-only adapter.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
