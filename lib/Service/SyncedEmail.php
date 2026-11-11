<?php

/**
 * Pipelinq SyncedEmail.
 *
 * The mail message an EmailLink records: its identity, envelope and thread.
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
 * One synced mail message, as an EmailLink stores it.
 *
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-3
 */
final class SyncedEmail {
	/**
	 * Constructor.
	 *
	 * @param string             $messageId  The email message ID.
	 * @param string             $subject    The email subject.
	 * @param string             $sender     The sender address.
	 * @param array<int, string> $recipients The recipient addresses.
	 * @param string             $date       The email date.
	 * @param string|null        $threadId   The thread ID.
	 */
	public function __construct(
		public readonly string $messageId,
		public readonly string $subject,
		public readonly string $sender,
		public readonly array $recipients,
		public readonly string $date,
		public readonly ?string $threadId=null,
	) {
	}//end __construct()
}//end class
