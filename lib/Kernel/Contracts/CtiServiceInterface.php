<?php

/**
 * Contract of CtiService
 *
 * The public surface of CtiService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;

/**
 * Contract of CtiService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CtiServiceInterface {

	/**
	 * Attach recording metadata to an existing contactmoment ticket.
	 *
	 * @param string $interactionId Contactmoment ticket UUID.
	 * @param string $recordingUrl URL of the recording.
	 * @param string $expiresAt ISO 8601 retention expiry.
	 *
	 * @return bool True when the recording was written, false when the ticket
	 *              store is unconfigured or the write failed.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function attachRecording(string $interactionId, string $recordingUrl, string $expiresAt): bool;

	/**
	 * The escalation queues an agent can choose at wrap-up, by id and label.
	 *
	 * @return array<int, array{id: string, label: string}> The queues.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function escalationQueues(): array;

	/**
	 * Handle an inbound webhook payload.
	 *
	 * Persists the raw payload to the event log, then dispatches based on the
	 * normalised event type:
	 *   - ringing/answered → create/update a pending contactmoment
	 *   - ended            → write call duration and recording fields
	 *   - presence         → update ctiAgentPresence
	 *
	 * @param string $platform Platform identifier.
	 * @param array<string,mixed> $payload Decoded webhook body.
	 * @param string|null $rawBody Raw body (for signature recheck).
	 * @param string|null $signature Signature header from the request.
	 *
	 * @return array{logged: bool, valid: bool, interactionId: string|null}
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function handleWebhook(
		string $platform,
		array $payload,
		?string $rawBody = null,
		?string $signature = null,
	): array;

	/**
	 * Initiate a screen-pop lookup for an inbound call.
	 *
	 * @param string $fromNumber Raw caller phone number.
	 * @param string|null $orgId Reserved for multi-tenant pipelinq.
	 *
	 * @return ScreenPopResult The screen-pop result for the frontend.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function initiateScreenPop(string $fromNumber, ?string $orgId = null): ScreenPopResult;

	/**
	 * List event-log entries with optional filters (max 30 days back).
	 *
	 * @param array<string,mixed> $filters Filter map: platform, event_type, since, until.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<int,array<string,mixed>>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function listEventLog(array $filters = [], int $limit = 50, int $offset = 0): array;

	/**
	 * Read the CTI singleton config.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function loadConfig(): array;

	/**
	 * Originate an outbound call via the active adapter.
	 *
	 * @param string $userId Acting agent UID.
	 * @param string $extension Agent extension to ring.
	 * @param string $targetNumber Destination number (E.164 preferred).
	 *
	 * @return OriginateResult The outcome (success/failure, external call id, pre-created contactmoment id).
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function originateCall(string $userId, string $extension, string $targetNumber): OriginateResult;

	/**
	 * Process an agent's disposition form submission.
	 *
	 * Delegates to {@see CtiDispositionService}.
	 *
	 * @param string $interactionId Contactmoment UUID.
	 * @param string $subject Disposition subject.
	 * @param string $outcome Outcome enum.
	 * @param string $notes Notes.
	 * @param string $queue The escalation queue id, or ''.
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function processDisposition(string $interactionId, string $subject, string $outcome, string $notes, string $queue=''): array;

	/**
	 * Purge event-log entries older than 30 days.
	 *
	 * @return int Number of deleted records.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over the fetch/filter/delete loop; extraction adds no clarity.
	 */
	public function purgeOldEventLog(): int;

	/**
	 * Save the CTI singleton config (creating or updating).
	 *
	 * @param array<string,mixed> $config The config payload.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function saveConfig(array $config): array;

	/**
	 * Test connectivity against the configured platform.
	 *
	 * @return array{ok: bool, platform: string, message: string}
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function testConnection(): array;
}//end interface
