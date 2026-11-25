<?php

/**
 * A visitor's writes, run as the messaging service account.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A visitor has no account, so what their chat writes runs as the messaging
 * service account, through OpenRegister's checks for that account. A failure
 * answers 503 and is logged without the visitor's text.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
class WebChatVisitorWrites {

	/**
	 * Constructor.
	 *
	 * @param MessagingServiceAccount $account The account a visitor's writes run as.
	 * @param LoggerInterface         $logger  Where a failed write is logged.
	 */
	public function __construct(
		private readonly MessagingServiceAccount $account,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run a visitor's writes as the messaging service account.
	 *
	 * @param callable(): array<string, mixed> $writes The writes.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When the account or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function run(callable $writes): array {
		try {
			return $this->account->runAs(operation: $writes);
		} catch (WebChatRefusal $e) {
			throw $e;
		} catch (ServiceAccountUnavailableException | RuntimeException $e) {
			$this->logger->error('Pipelinq web chat: a visitor write failed', ['exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The chat is not available right now.', status: 503);
		}
	}//end run()
}//end class
