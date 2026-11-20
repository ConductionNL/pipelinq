<?php

/**
 * Pipelinq SocialInboxService.
 *
 * One pass over the connected Facebook pages and Instagram business accounts
 * (requests-inbound-to-queue D5). Per account it reads one page of recent
 * conversations through the credential broker, hands every visitor message
 * newer than that account's cursor to the ticket intake with the thread key
 * `meta:<conversation id>`, and advances the cursor. A refusal from the broker
 * or from Meta is stored on the account as `messagingStatus`, next to the
 * posting `status` it leaves alone, so the Social accounts page says why
 * messages do not arrive.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\SocialAccountService;
use OCP\IAppConfig;

/**
 * Takes the new Facebook and Instagram messages into the Queue.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */
class SocialInboxService {

	/**
	 * Prefix of the per-account cursor key in app config: the newest message time taken in, as a Unix time.
	 *
	 * @var string
	 */
	public const CURSOR_PREFIX = 'socialInbox.cursor.';

	/**
	 * The messaging status of an account whose inbox was read.
	 *
	 * @var string
	 */
	public const RECEIVING = 'receiving';

	/**
	 * Constructor.
	 *
	 * @param SocialAccountService     $accounts  The connected accounts, and where their messaging status is kept.
	 * @param FacebookPageAdapter      $facebook  Reads a page's Messenger conversations.
	 * @param InstagramBusinessAdapter $instagram Reads a business account's Instagram conversations.
	 * @param SocialBrokerGateway      $gateway   Makes the brokered read.
	 * @param InboundTicketService     $intake    Opens or continues the ticket.
	 * @param IAppConfig               $appConfig Holds the cursors.
	 */
	public function __construct(
		private readonly SocialAccountService $accounts,
		private readonly FacebookPageAdapter $facebook,
		private readonly InstagramBusinessAdapter $instagram,
		private readonly SocialBrokerGateway $gateway,
		private readonly InboundTicketService $intake,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Read the inbox of every connected Facebook page and Instagram business account.
	 *
	 * @return array{accounts: int, messages: int, tickets: int, refused: int} What the pass did.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
	 */
	public function run(): array {
		$totals = ['accounts' => 0, 'messages' => 0, 'tickets' => 0, 'refused' => 0];
		foreach ($this->accounts->activeAccounts() as $account) {
			$adapter = $this->adapterFor(network: (string) ($account['network'] ?? ''));
			if ($adapter === null || trim((string) ($account['credentialRef'] ?? '')) === '') {
				continue;
			}

			$totals['accounts']++;
			$counts = $this->runAccount(account: $account, adapter: $adapter);
			foreach (['messages', 'tickets', 'refused'] as $key) {
				$totals[$key] += $counts[$key];
			}
		}

		return $totals;
	}//end run()

	/**
	 * Read one account's inbox, take in what is new and advance its cursor.
	 *
	 * @param array<string, mixed> $account The socialAccount record.
	 * @param FacebookPageAdapter  $adapter The adapter of its network.
	 *
	 * @return array{messages: int, tickets: int, refused: int} What the pass did for this account.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#scenario-a-page-without-messaging-permission-says-so
	 */
	public function runAccount(array $account, FacebookPageAdapter $adapter): array {
		$counts    = ['messages' => 0, 'tickets' => 0, 'refused' => 0];
		$accountId = (string) ($account['id'] ?? ($account['uuid'] ?? ''));
		$call      = $adapter->conversationsCall(account: $account);
		if ($accountId === '' || $call === null) {
			return $counts;
		}

		$result = $this->gateway->request(
			credentialRef: (string) $account['credentialRef'],
			method: $call->method,
			path: $call->path,
			headers: $call->requestHeaders(),
			body: $call->body(),
			actingUserId: $this->ownerOf(account: $account),
		);
		if ($result->accepted === false) {
			$this->accounts->recordMessagingStatus(accountId: $accountId, status: $result->failureCode, reason: $result->failureReason);
			$counts['refused'] = 1;
			return $counts;
		}

		$this->accounts->recordMessagingStatus(accountId: $accountId, status: self::RECEIVING, reason: '');

		$cursorKey = self::CURSOR_PREFIX . $accountId;
		$cursor    = (int) $this->appConfig->getValueString(AppIdentity::APP_ID, $cursorKey, '0');
		$newest    = $cursor;
		foreach ($adapter->visitorMessages(payload: $result->body, account: $account) as $message) {
			if ($message['createdAt'] <= $cursor) {
				continue;
			}

			$counts['messages']++;
			$newest = max($newest, $message['createdAt']);
			if ($this->intake->tryReceive(message: $this->intakeMessage(network: $adapter->network(), account: $account, message: $message)) !== '') {
				$counts['tickets']++;
			}
		}

		if ($newest > $cursor) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, $cursorKey, (string) $newest);
		}

		return $counts;
	}//end runAccount()

	/**
	 * The adapter that reads a network's inbox; null for a network without one here.
	 *
	 * @param string $network The account's network.
	 *
	 * @return FacebookPageAdapter|null
	 */
	private function adapterFor(string $network): ?FacebookPageAdapter {
		return match ($network) {
			'facebook' => $this->facebook,
			'instagram' => $this->instagram,
			default => null,
		};
	}//end adapterFor()

	/**
	 * The account owner the sessionless read is made for; null when it names none.
	 *
	 * @param array<string, mixed> $account The socialAccount record.
	 *
	 * @return string|null
	 */
	private function ownerOf(array $account): ?string {
		$owner = trim((string) ($account['ownerUserId'] ?? ''));
		if ($owner === '') {
			return null;
		}

		return $owner;
	}//end ownerOf()

	/**
	 * The intake message of one visitor message. Meta gives a page-scoped id,
	 * not an address, so no contact is matched; the sender's name is kept.
	 *
	 * @param string               $network The account's network.
	 * @param array<string, mixed> $account The socialAccount record.
	 * @param array<string, mixed> $message The visitor message, as ReadsMetaConversations::visitorMessages() gives it.
	 *
	 * @return array<string, mixed> The message, as the intake takes it.
	 */
	private function intakeMessage(string $network, array $account, array $message): array {
		return [
			'channel' => $network,
			'threadKey' => 'meta:' . $message['conversationId'],
			'text' => $message['text'],
			'fromName' => $message['fromName'],
			'mailbox' => trim((string) ($account['handle'] ?? ($account['displayName'] ?? ''))),
		];
	}//end intakeMessage()
}//end class
