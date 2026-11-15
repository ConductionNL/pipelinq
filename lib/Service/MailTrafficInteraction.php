<?php

/**
 * Pipelinq MailTrafficInteraction.
 *
 * One recorded mail open or click, as the traffic emitter reports it.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The kind, the blastDelivery row, its parent blast row and the clicked URL of one mail interaction.
 *
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */
final class MailTrafficInteraction {
	/**
	 * Constructor.
	 *
	 * @param string               $kind       `open` or `click`.
	 * @param array<string, mixed> $delivery   The blastDelivery row.
	 * @param array<string, mixed> $blast      The parent blast row (may be empty).
	 * @param string|null          $clickedUrl The decoded click target, or null for an open.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $delivery,
		public readonly array $blast,
		public readonly ?string $clickedUrl = null,
	) {
	}//end __construct()

	/**
	 * The blast id of the delivery, or an empty string.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function blastId(): string {
		return (string)($this->delivery['blastId'] ?? '');
	}//end blastId()

	/**
	 * The contact id of the delivery, or an empty string.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function contactId(): string {
		return (string)($this->delivery['contactId'] ?? '');
	}//end contactId()
}//end class
