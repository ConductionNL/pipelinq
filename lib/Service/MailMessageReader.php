<?php

/**
 * Pipelinq MailMessageReader.
 *
 * Reads Mail app messages and their participants for email matching.
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
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a Mail message's metadata and an account's inbound messages from the Mail app tables.
 *
 * Every read returns empty when Mail is uninstalled or a query fails.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class MailMessageReader {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The database holding the Mail app tables.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Fetch a Mail message's metadata from the Mail app DB.
	 *
	 * Returns null when Mail is uninstalled or the row is absent.
	 *
	 * @param MailMessageKey $message The Mail message.
	 *
	 * @return array{uid:string,subject:?string,sender:?string,recipients:array<int,string>,date:?string}|null
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function fetchMeta(MailMessageKey $message): ?array {
		$row = $this->fetchMessageRow(message: $message);
		if ($row === null) {
			return null;
		}

		$participants = $this->fetchParticipants(messageId: $message->mailMessageId);

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'subject' => $this->subjectOf(row: $row),
			'sender' => $participants['sender'],
			'recipients' => array_values(array_unique($participants['recipients'])),
			'date' => $this->sentAtOf(row: $row),
		];
	}//end fetchMeta()
	/**
	 * The Mail database row of a message in its account.
	 *
	 * @param MailMessageKey $message The Mail message.
	 *
	 * @return array<string, mixed>|null The row, null when absent or Mail is unavailable.
	 */
	private function fetchMessageRow(MailMessageKey $message): ?array {
		try {
			$result = $this->messageRowQuery(message: $message)->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			return null;
		}

		if ($row === false || is_array($row) === false) {
			return null;
		}

		return $row;
	}//end fetchMessageRow()
	/**
	 * The query for a message row, joined to its mailbox's account.
	 *
	 * @param MailMessageKey $message The Mail message.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function messageRowQuery(MailMessageKey $message): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at', 'mb.account_id')
			->from('mail_messages', 'm')
			->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
			->where($qb->expr()->eq('m.id', $qb->createNamedParameter($message->mailMessageId)))
			->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($message->mailAccountId)))
			->setMaxResults(1);
		return $qb;
	}//end messageRowQuery()
	/**
	 * The sender (type 0) and the other addresses of a message.
	 *
	 * @param int $messageId Mail message id.
	 *
	 * @return array{sender:?string,recipients:array<int,string>} What was read; a lookup failure keeps what was read so far.
	 */
	private function fetchParticipants(int $messageId): array {
		$participants = ['sender' => null, 'recipients' => []];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('email', 'type')
				->from('mail_recipients')
				->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)));

			$result = $qb->executeQuery();
			while (($rcpt = $result->fetch()) !== false) {
				$participants = $this->withParticipant(participants: $participants, rcpt: $rcpt);
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			// Leave recipients empty on lookup failure.
		}//end try

		return $participants;
	}//end fetchParticipants()
	/**
	 * Add one recipient row: type 0 is the sender, every other type a recipient.
	 *
	 * @param array{sender:?string,recipients:array<int,string>} $participants The participants so far.
	 * @param array<string, mixed>                               $rcpt         One mail_recipients row.
	 *
	 * @return array{sender:?string,recipients:array<int,string>} The participants with the row added.
	 */
	private function withParticipant(array $participants, array $rcpt): array {
		$email = strtolower(trim((string)($rcpt['email'] ?? '')));
		if ($email === '') {
			return $participants;
		}

		if ((int)($rcpt['type'] ?? -1) === 0) {
			$participants['sender'] = $email;
			return $participants;
		}

		$participants['recipients'][] = $email;
		return $participants;
	}//end withParticipant()
	/**
	 * The subject of a message row.
	 *
	 * @param array<string, mixed> $row The message row.
	 *
	 * @return string|null The subject, null when absent.
	 */
	private function subjectOf(array $row): ?string {
		if (isset($row['subject']) === true) {
			return (string)$row['subject'];
		}

		return null;
	}//end subjectOf()
	/**
	 * The send date of a message row, ISO 8601.
	 *
	 * `isset()` is already false for null — the `!== null` conjunct this
	 * replaces was unreachable.
	 *
	 * @param array<string, mixed> $row The message row.
	 *
	 * @return string|null The date, null when absent.
	 */
	private function sentAtOf(array $row): ?string {
		if (isset($row['sent_at']) === true) {
			return date('c', (int)$row['sent_at']);
		}

		return null;
	}//end sentAtOf()
	/**
	 * List inbound Mail message rows for an account, since a cursor id.
	 *
	 * Returns rows ordered by id ascending so the cursor advances
	 * monotonically. The cap of 200 prevents a single run from holding
	 * the DB busy when an account has a large historical backlog.
	 *
	 * @param int $accountId Mail account id.
	 * @param int $sinceId Last processed message id (0 = from the start).
	 *
	 * @return array<int,array{id:int}>
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function listInbound(int $accountId, int $sinceId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId)))
				->orderBy('m.id', 'ASC')
				->setMaxResults(200);

			$result = $qb->executeQuery();
			$rows = [];
			while (($row = $result->fetch()) !== false) {
				$rows[] = ['id' => (int)($row['id'] ?? 0)];
			}

			$result->closeCursor();
			return $rows;
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: listInboundMessages failed');
			return [];
		}//end try

	}//end listInbound()
}//end class
