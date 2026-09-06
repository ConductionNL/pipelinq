<?php

/**
 * PHPStan declaration-only stub for OCA\Mail\Service\OutboxService.
 *
 * See `tests/Stubs/Mail/Service/AccountService.php` for why this stub
 * exists and why it is safe: never autoloaded, phpstan-only.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Mail\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Mail\Service;

use OCA\Mail\Account;
use OCA\Mail\Db\LocalMessage;

if (class_exists(OutboxService::class) === false) {
	/**
	 * Declaration-only stub — never instantiated, never autoloaded.
	 */
	class OutboxService {
		/**
		 * @param Account $account Owning Mail account.
		 * @param LocalMessage $message The message to save.
		 * @param array<int, array<string, string>> $to Recipient list.
		 * @param array<int, array<string, string>> $cc Cc list.
		 * @param array<int, array<string, string>> $bcc Bcc list.
		 *
		 * @return LocalMessage
		 */
		public function saveMessage(Account $account, LocalMessage $message, array $to, array $cc, array $bcc): LocalMessage {
			throw new \LogicException('OutboxService stub: declaration-only, never called.');
		}//end saveMessage()

		/**
		 * @param LocalMessage $message The message to send.
		 * @param Account $account Owning Mail account.
		 *
		 * @return LocalMessage
		 */
		public function sendMessage(LocalMessage $message, Account $account): LocalMessage {
			throw new \LogicException('OutboxService stub: declaration-only, never called.');
		}//end sendMessage()
	}//end class
}//end if
