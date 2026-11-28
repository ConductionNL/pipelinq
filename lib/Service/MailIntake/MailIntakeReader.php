<?php

/**
 * Pipelinq MailIntakeReader.
 *
 * Reads the new messages of a ticket mailbox's INBOX from the Mail app's own
 * tables (requests-inbound-to-queue D4): the same query shape as
 * `MailMessageReader::listInbound()`, narrowed to the mailbox named INBOX,
 * with the thread root, subject and preview text the intake needs.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MailIntake
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MailIntake;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a mailbox's new INBOX messages, oldest first.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */
class MailIntakeReader {

	/**
	 * The mailbox the Mail app names INBOX (nextcloud/mail Mailbox::isInbox()).
	 *
	 * @var string
	 */
	public const INBOX = 'INBOX';

	/**
	 * The recipient type the Mail app stores the sender under.
	 *
	 * @var integer
	 */
	public const FROM = 0;

	/**
	 * Most messages read per account per run, so a backlog drains over several runs.
	 *
	 * @var integer
	 */
	public const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Reads the Mail app's tables.
	 * @param LoggerInterface $logger Where an unreadable Mail table is logged.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The INBOX messages of an account with an id above the cursor, oldest first.
	 *
	 * @param int $accountId The Mail account id.
	 * @param int $sinceId   The last message id already taken in (0 = from the start).
	 *
	 * @return list<array{id: int, uid: string, subject: string, preview: string, threadRootId: string}> The messages.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function inboxSince(int $accountId, int $sinceId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.uid', 'm.subject', 'm.preview_text', 'm.thread_root_id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->andWhere($qb->expr()->eq('mb.name', $qb->createNamedParameter(self::INBOX)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId)))
				->orderBy('m.id', 'ASC')
				->setMaxResults(self::PAGE);

			$result = $qb->executeQuery();
			$rows   = [];
			while (($row = $result->fetch()) !== false) {
				$rows[] = [
					'id' => (int) ($row['id'] ?? 0),
					'uid' => (string) ($row['uid'] ?? ''),
					'subject' => (string) ($row['subject'] ?? ''),
					'preview' => (string) ($row['preview_text'] ?? ''),
					'threadRootId' => (string) ($row['thread_root_id'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the ticket mailbox could not be read', ['account' => $accountId, 'error' => $e->getMessage()]);
			$rows = [];
		}//end try

		return $rows;
	}//end inboxSince()

	/**
	 * The sender's address of a message (recipient type 0), lower case; '' when unknown.
	 *
	 * @param int $messageId The Mail message id.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function senderOf(int $messageId): string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('email')
				->from('mail_recipients')
				->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
				->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(self::FROM)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row    = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: the sender of a ticket mailbox message could not be read',
				['message' => $messageId, 'error' => $e->getMessage()]
			);
			$row = false;
		}//end try

		if (is_array($row) === false) {
			return '';
		}

		return strtolower(trim((string) ($row['email'] ?? '')));
	}//end senderOf()
}//end class
