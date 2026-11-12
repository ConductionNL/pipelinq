<?php

/**
 * Pipelinq EmailLinkTarget.
 *
 * The CRM entity an email is linked to, and how it was synced.
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
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-emails-must-be-automatically-linked-to-crm-contacts
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The entity a synced email is linked to, the mail direction and the source account.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-contact-comms/tasks.md#task-3
 */
final class EmailLinkTarget {
	/**
	 * Constructor.
	 *
	 * @param string      $entityType The linked entity type.
	 * @param string      $entityId   The linked entity UUID.
	 * @param string      $direction  Inbound or outbound.
	 * @param string|null $syncSource The mail account ID.
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $entityId,
		public readonly string $direction,
		public readonly ?string $syncSource=null,
	) {
	}//end __construct()
}//end class
