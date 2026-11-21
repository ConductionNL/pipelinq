<?php

/**
 * Pipelinq DeliveryAuditEntry.
 *
 * The message, the event tag, the payload hash, the retention end and the actor of one audit row.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeInterface;

/**
 * One delivery audit event about a message.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */
final class DeliveryAuditEntry {
	/**
	 * Constructor.
	 *
	 * @param string             $messageId      Subject UUID.
	 * @param string             $event          Event tag.
	 * @param string             $payloadHash    SHA-256 of the body.
	 * @param ?DateTimeInterface $retentionUntil Retention end, or null for the default.
	 * @param string             $actor          Actor.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function __construct(
		public readonly string $messageId,
		public readonly string $event,
		public readonly string $payloadHash,
		public readonly ?DateTimeInterface $retentionUntil,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
