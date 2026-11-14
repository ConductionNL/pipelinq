<?php

/**
 * Contract of MessagingService
 *
 * The public surface of MessagingService that other components call, so they depend
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

use OCA\Pipelinq\Service\Messaging\MessageContent;
use OCA\Pipelinq\Service\Messaging\MessageRoute;

/**
 * Contract of MessagingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessagingServiceInterface {

	/**
	 * Load a contact / client object by id (per-object accessibility guard).
	 *
	 * Returns null when OpenRegister is absent, the register is unconfigured,
	 * or the object does not exist — the caller treats null as "not accessible"
	 * and refuses before any adapter is invoked (no-admin-idor).
	 *
	 * @param string $contactId Contact or client UUID.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function loadContact(string $contactId): ?array;

	/**
	 * Load one channelMessage row through the register RBAC.
	 *
	 * @param string $messageId The message UUID.
	 *
	 * @return array<string, mixed>|null The row, or null when absent or not readable.
	 *
	 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function loadMessage(string $messageId): ?array;

	/**
	 * Composer preflight facts for one contact.
	 *
	 * @param string $contactId Contact UUID (already access-guarded by the caller).
	 *
	 * @return array{
	 *     channels: array{sms: bool, whatsapp: bool},
	 *     whatsappSessionOpen: bool,
	 *     consent: array{sms: string, whatsapp: string},
	 *     templates: array<int, array<string, mixed>>
	 * } Preflight facts.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function preflight(string $contactId): array;

	/**
	 * Send a failed message again through the normal send() and its checks.
	 *
	 * A template row repeats its template with the stored parameters; any
	 * other row repeats its text. On success the new row carries
	 * `metadata.resendOf` and the failed row `metadata.resentAs`; the failed
	 * row keeps its delivery status.
	 *
	 * @param array<string, mixed> $message The failed row (refusals already checked).
	 * @param array<string, mixed> $contact The contact, loaded through the RBAC.
	 * @param string $actor Acting user id.
	 *
	 * @return array{status: string, messageId?: string, reason?: string} Sanitised outcome.
	 *
	 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function resend(array $message, array $contact, string $actor): array;

	/**
	 * Why a message may not be sent again, or null when it may.
	 *
	 * @param array<string, mixed> $message The channelMessage row.
	 *
	 * @return string|null `inbound`, `not-failed`, `already-resent` or null.
	 *
	 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function resendRefusal(array $message): ?string;

	/**
	 * Run the zero-cost connectivity test for a provider through the OR leaf.
	 *
	 * For a mock-flagged source the leaf short-circuits and returns the canned
	 * vendor-shaped body (reachable + mock badge). A degraded leaf result
	 * surfaces the cause without any Throwable reaching the caller.
	 *
	 * @param array<string, mixed> $provider The channelProvider row.
	 *
	 * @return array{reachable: bool, mock?: bool, cause?: string} Test result.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-002-zero-cost-provider-connectivity-test
	 */
	public function runProviderTest(array $provider): array;

	/**
	 * Send a message, delegating all gating to the adapters (REQ-OM-004).
	 *
	 * @param MessageRoute   $route   The contact, pinned vendor, linked client and reply target.
	 * @param MessageContent $content The channel, text and template.
	 * @param string         $actor   Acting user id (audit).
	 *
	 * @return array{status: string, messageId?: string, reason?: string} Sanitised outcome.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function send(MessageRoute $route, MessageContent $content, string $actor): array;
}//end interface
