<?php

/**
 * Contract of BerichtenboxService
 *
 * The public surface of BerichtenboxService that other components call, so they depend
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

use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use OCA\Pipelinq\Service\Berichtenbox\MessageOptions;
use RuntimeException;

/**
 * Contract of BerichtenboxService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BerichtenboxServiceInterface {

	/**
	 * Dispatch all queued messages whose nextRetryAt is past (or unset).
	 *
	 * @param int $limit Maximum messages per run (default 100).
	 *
	 * @return int Number of messages processed.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function dispatchQueuedMessages(int $limit = 100): int;

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
	): array;

	/**
	 * Mark a delivered message as read in response to a Logius webhook.
	 *
	 * @param string $logiusMessageId Logius-assigned id.
	 * @param string $readAtIso Read timestamp (ISO 8601).
	 *
	 * @return bool True iff a matching message was found and updated.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handleReadReceipt(string $logiusMessageId, string $readAtIso): bool;

	/**
	 * Run the daily 5-working-day fallback pass.
	 *
	 * @return int Number of fallback emails sent.
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function processFallbackQueue(): int;

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
	public function queueOutboundMessage(CaseStatusMessage $message, MessageOptions $options = new MessageOptions()): array;
}//end interface
