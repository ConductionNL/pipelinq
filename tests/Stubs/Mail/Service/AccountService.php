<?php

/**
 * PHPStan declaration-only stub for OCA\Mail\Service\AccountService.
 *
 * The Nextcloud Mail app (`custom_apps/mail`) ships no OCP contract and is a
 * genuinely optional runtime dependency: `MailAccountTransport` resolves it
 * lazily behind a `class_exists()` guard and degrades soft when it is
 * absent. That guard is exactly what this stub exists to keep honest under
 * static analysis: with no declaration of `OCA\Mail\*` anywhere phpstan
 * scans, it can PROVE the guard's `class_exists()` call is always false
 * (`function.impossibleType`) and flag genuinely soft-dependency code as
 * dead. Mirrors the existing declaration-only pattern for OpenRegister/Talk
 * stubs (see `phpstan.neon`'s `scanDirectories: tests/Stubs` comment).
 *
 * NOT registered on any PSR-4 autoload path (composer.json nor
 * tests/bootstrap.php) — phpstan's `scanDirectories` parses this file
 * directly for its declaration; nothing ever autoloads it. `class_exists()`
 * on the real FQCN therefore still correctly reports `false` at both
 * PHPUnit-test time and Nextcloud-runtime whenever the Mail app is not
 * installed — this stub changes phpstan's analysis only, never behaviour.
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

if (class_exists(AccountService::class) === false) {
	/**
	 * Declaration-only stub — never instantiated, never autoloaded.
	 */
	class AccountService {
		/**
		 * @param string $userId Nextcloud user id that owns the account.
		 * @param int $id Mail app account id.
		 *
		 * @return Account
		 */
		public function find(string $userId, int $id): Account {
			throw new \LogicException('AccountService stub: declaration-only, never called.');
		}//end find()
	}//end class
}//end if
