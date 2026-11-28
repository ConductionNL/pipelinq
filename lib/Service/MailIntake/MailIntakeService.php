<?php

/**
 * Pipelinq MailIntakeService.
 *
 * One pass over the ticket mailboxes (requests-inbound-to-queue D4). For
 * each mailbox it reads the INBOX messages since that account's cursor,
 * skips mail sent from a ticket mailbox's own address, matches the sender to
 * a client or contact, hands the message to the ticket intake with the thread
 * key `mail:<account>:<thread root>` and links the Mail message to the ticket
 * it landed on. The cursor advances past every message it read, skipped ones
 * included, so a message is taken in once.
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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\MailMessageKey;
use OCP\IAppConfig;

/**
 * Takes the new mail of every ticket mailbox into the Queue.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */
class MailIntakeService {

	/**
	 * Prefix of the per-account cursor key in app config.
	 *
	 * @var string
	 */
	public const CURSOR_PREFIX = 'inboundMail.cursor.';

	/**
	 * The channel a mail ticket carries.
	 *
	 * @var string
	 */
	public const CHANNEL = 'email';

	/**
	 * Constructor.
	 *
	 * @param MailIntakeAccounts   $accounts  The ticket mailboxes.
	 * @param MailIntakeReader     $reader    Their new INBOX messages and each one's sender.
	 * @param EmailMatchService    $matcher   Matches the sender and links the mail.
	 * @param InboundTicketService $intake    Opens or continues the ticket.
	 * @param IAppConfig           $appConfig Holds the cursors.
	 */
	public function __construct(
		private readonly MailIntakeAccounts $accounts,
		private readonly MailIntakeReader $reader,
		private readonly EmailMatchService $matcher,
		private readonly InboundTicketService $intake,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Take in the new mail of every ticket mailbox.
	 *
	 * @return array{mailboxes: int, scanned: int, tickets: int, skipped: int} What the pass did.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function run(): array {
		$mailboxes = $this->accounts->mailboxes();
		$own       = array_values(array_filter(array_map(static fn (array $box): string => strtolower(trim($box['address'])), $mailboxes)));

		$totals = ['mailboxes' => count($mailboxes), 'scanned' => 0, 'tickets' => 0, 'skipped' => 0];
		foreach ($mailboxes as $mailbox) {
			$counts = $this->runMailbox(mailbox: $mailbox, ownAddresses: $own);
			foreach (['scanned', 'tickets', 'skipped'] as $key) {
				$totals[$key] += $counts[$key];
			}
		}

		return $totals;
	}//end run()

	/**
	 * Take in one mailbox's new INBOX messages and advance its cursor.
	 *
	 * @param array{id: int, address: string, name: string, owner: string} $mailbox      The ticket mailbox.
	 * @param list<string> $ownAddresses Every ticket mailbox address, lower case.
	 *
	 * @return array{scanned: int, tickets: int, skipped: int} What the pass did for this mailbox.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function runMailbox(array $mailbox, array $ownAddresses): array {
		$accountId = (int) $mailbox['id'];
		$cursorKey = self::CURSOR_PREFIX . $accountId;
		$cursor    = (int) $this->appConfig->getValueString(AppIdentity::APP_ID, $cursorKey, '0');

		$counts = ['scanned' => 0, 'tickets' => 0, 'skipped' => 0];
		foreach ($this->reader->inboxSince(accountId: $accountId, sinceId: $cursor) as $row) {
			$counts['scanned']++;
			$cursor = max($cursor, $row['id']);
			if ($this->takeIn(mailbox: $mailbox, row: $row, ownAddresses: $ownAddresses) === true) {
				$counts['tickets']++;
				continue;
			}

			$counts['skipped']++;
		}

		if ($counts['scanned'] > 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, $cursorKey, (string) $cursor);
		}

		return $counts;
	}//end runMailbox()

	/**
	 * Hand one message to the intake and link it to its ticket.
	 *
	 * @param array{id: int, address: string, name: string, owner: string} $mailbox      The ticket mailbox.
	 * @param array{id: int, uid: string, subject: string, preview: string, threadRootId: string} $row          The message.
	 * @param list<string> $ownAddresses Every ticket mailbox address, lower case.
	 *
	 * @return bool Whether the message is on a ticket now.
	 */
	private function takeIn(array $mailbox, array $row, array $ownAddresses): bool {
		$key    = new MailMessageKey(mailAccountId: (int) $mailbox['id'], mailMessageId: $row['id']);
		$sender = $this->reader->senderOf(messageId: $row['id']);
		if ($sender === '' || in_array($sender, $ownAddresses, true) === true) {
			return false;
		}

		$message = $this->message(mailbox: $mailbox, row: $row, sender: $sender);
		$ticket  = $this->intake->tryReceive(message: $message);
		if ($ticket === '') {
			return false;
		}

		$this->matcher->linkMessageTo(message: $key, uid: $row['uid'], objectId: $ticket, schemaKey: 'ticket_schema');

		return true;
	}//end takeIn()

	/**
	 * The intake message of one mail: thread key, sender, matched client and contact.
	 *
	 * @param array{id: int, address: string, name: string, owner: string} $mailbox The ticket mailbox.
	 * @param array{id: int, uid: string, subject: string, preview: string, threadRootId: string} $row     The message.
	 * @param string $sender  The sender's address, lower case.
	 *
	 * @return array<string, mixed> The message, as the intake takes it.
	 */
	private function message(array $mailbox, array $row, string $sender): array {
		$thread = trim($row['threadRootId']);
		if ($thread === '') {
			$thread = (string) $row['id'];
		}

		$domain  = (string) substr((string) strrchr($sender, '@'), 1);
		$message = [
			'channel' => self::CHANNEL,
			'threadKey' => 'mail:' . (int) $mailbox['id'] . ':' . $thread,
			'title' => $row['subject'],
			'text' => $row['preview'],
			'fromAddress' => $sender,
			'fromDomain' => $domain,
			'mailbox' => $mailbox['address'],
		];

		return $message + $this->party(sender: $sender, domain: $domain);
	}//end message()

	/**
	 * The client and contact the sender's address, or else its domain, names.
	 *
	 * @param string $sender The sender's address.
	 * @param string $domain The sender's domain.
	 *
	 * @return array<string, string> `client` and/or `contact`; empty when nothing matched.
	 */
	private function party(string $sender, string $domain): array {
		$party = [];
		foreach ($this->matcher->matchEmailToEntities(address: $sender) as $match) {
			if (isset($party[$match['entityType']]) === false && in_array($match['entityType'], ['client', 'contact'], true) === true) {
				$party[$match['entityType']] = (string) $match['entityId'];
			}
		}

		if (isset($party['client']) === false && $domain !== '') {
			$organisation = $this->matcher->matchDomainToOrganization(domain: $domain);
			if ($organisation !== null) {
				$party['client'] = (string) $organisation['entityId'];
			}
		}

		return $party;
	}//end party()
}//end class
