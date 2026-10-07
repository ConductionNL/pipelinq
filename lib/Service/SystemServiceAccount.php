<?php

/**
 * Pipelinq SystemServiceAccount.
 *
 * The Nextcloud account pipelinq's own background writes run as when nobody
 * is signed in, such as the repair step that creates the default pipelines
 * during `occ upgrade` or an app install. Without it OpenRegister sees the
 * caller as Anonymous and refuses the write. OpenRegister's access checks stay
 * on: the writes run as this one account, the same model as the portal
 * service account (PortalServiceAccount), never with `_rbac: false`.
 *
 * The account is created on first use, disabled, with a random password, so
 * nobody can sign in with it. The identity is set with
 * `IUserSession::setVolatileActiveUser()` and the previous user is restored
 * in a `finally`. It is never written to the PHP session.
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
 * @spec openspec/changes/review-part-two/specs/pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use RuntimeException;

/**
 * Runs pipelinq's own writes as a dedicated, disabled system account.
 *
 * @spec openspec/changes/review-part-two/specs/pipeline/spec.md
 */
class SystemServiceAccount {
	/**
	 * The account's user id.
	 */
	public const USER_ID = 'pipelinq-system';

	/**
	 * The account's display name.
	 */
	public const DISPLAY_NAME = 'Pipelinq system';

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $userManager Finds or creates the account.
	 * @param IUserSession  $userSession Switches the active user.
	 * @param ISecureRandom $random      Generates the unusable password.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Run an operation as the signed-in user, or as the system account when
	 * nobody is signed in.
	 *
	 * @param callable $operation The work to run.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/changes/review-part-two/specs/pipeline/spec.md
	 */
	public function runAsCurrentOrSystem(callable $operation): mixed {
		$previous = $this->userSession->getUser();
		if ($previous !== null) {
			return $operation();
		}

		$this->userSession->setVolatileActiveUser($this->account());
		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end runAsCurrentOrSystem()

	/**
	 * The system account, created disabled on first use.
	 *
	 * @return IUser The account.
	 *
	 * @throws RuntimeException When the account cannot be created.
	 *
	 * @spec openspec/changes/review-part-two/specs/pipeline/spec.md
	 */
	public function account(): IUser {
		$account = $this->userManager->get(self::USER_ID);
		if ($account !== null) {
			return $account;
		}

		$password = $this->random->generate(64);
		$account  = $this->userManager->createUser(self::USER_ID, $password);
		if ($account === false) {
			throw new RuntimeException('The '.self::USER_ID.' account could not be created.');
		}

		$account->setDisplayName(self::DISPLAY_NAME);
		$account->setEnabled(false);
		return $account;
	}//end account()
}//end class
