<?php

/**
 * Pipelinq ServiceAccount.
 *
 * A Nextcloud account that writes on behalf of callers who have none. A public
 * endpoint (the customer portal, a provider webhook) has no Nextcloud session,
 * so OpenRegister would see Anonymous. Instead of switching OpenRegister's
 * access checks off, the endpoint acts as one account an admin picks, and the
 * schemas it writes grant that account's group. It is the consumer model
 * integriq's intakes use (WebhookConnection: a consumer names the account its
 * writes run as).
 *
 * The identity is set with `IUserSession::setVolatileActiveUser()` and the
 * previous user is restored in a `finally` (ADR-099). It is never written to
 * the PHP session. A missing, unknown, disabled or ungrouped account refuses
 * before anything runs; each subclass says how.
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
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a configured service account and runs operations as it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Config, users, groups, the session and
 *  the refusal are the minimal set an acting identity needs; splitting them hides the check.
 *
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */
abstract class ServiceAccount {
	/**
	 * Why the account cannot be used: none is set.
	 *
	 * @var string
	 */
	public const REASON_UNSET = 'unset';

	/**
	 * Why the account cannot be used: the uid names no account.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * Why the account cannot be used: the account is disabled.
	 *
	 * @var string
	 */
	public const REASON_DISABLED = 'disabled';

	/**
	 * Why the account cannot be used: it is not in the service group.
	 *
	 * @var string
	 */
	public const REASON_NOT_IN_GROUP = 'not-in-group';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig    Holds the chosen uid.
	 * @param IUserManager    $userManager  Resolves the uid to an account.
	 * @param IGroupManager   $groupManager Creates the group and checks membership.
	 * @param IUserSession    $userSession  Carries the acting user for one operation.
	 * @param LoggerInterface $logger       Logs every refusal.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The app-config key holding the uid.
	 *
	 * @return string The key.
	 */
	abstract protected function configKey(): string;

	/**
	 * The group the written schemas grant.
	 *
	 * @return string The group id.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	abstract public function group(): string;

	/**
	 * Refuse: log the reason and throw what the caller answers with.
	 *
	 * @param string $userId The configured uid.
	 * @param string $reason One of the REASON_* constants.
	 *
	 * @return never
	 */
	abstract protected function refuse(string $userId, string $reason): never;

	/**
	 * The configured uid, or an empty string.
	 *
	 * @return string The uid.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function configuredUserId(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, $this->configKey(), ''));
	}//end configuredUserId()

	/**
	 * Whether the account can be used, and why not when it cannot.
	 *
	 * @return array{userId: string, usable: bool, reason: string|null} The status.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function status(): array {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);

		return ['userId' => $userId, 'usable' => ($reason === null), 'reason' => $reason];
	}//end status()

	/**
	 * The account, or the subclass's refusal.
	 *
	 * @return IUser The enabled account, a member of the service group.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function require(): IUser {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);
		$account = null;
		if ($reason === null) {
			$account = $this->userManager->get($userId);
		}

		if ($account === null) {
			$this->refuse(userId: $userId, reason: ($reason ?? self::REASON_UNKNOWN));
		}

		return $account;
	}//end require()

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function runAs(callable $operation): mixed {
		$account = $this->require();
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($account);

		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end runAs()

	/**
	 * Pick the account: it must exist and be enabled. It joins the service group.
	 *
	 * @param string $userId The uid.
	 *
	 * @return array{userId: string, usable: bool, reason: string|null} The new status.
	 *
	 * @throws InvalidArgumentException When the uid names no enabled account or the group cannot be made.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function assign(string $userId): array {
		$userId = trim($userId);
		$account = null;
		if ($userId !== '') {
			$account = $this->userManager->get($userId);
		}

		if ($account === null) {
			throw new InvalidArgumentException('No account "'.$userId.'" exists.');
		}

		if ($account->isEnabled() === false) {
			throw new InvalidArgumentException('The account "'.$userId.'" is disabled.');
		}

		$group = $this->ensureGroup();
		if ($group === null) {
			throw new InvalidArgumentException('The group '.$this->group().' could not be created.');
		}

		if ($group->inGroup($account) === false) {
			$group->addUser($account);
		}

		$this->appConfig->setValueString(Application::APP_ID, $this->configKey(), $userId);

		return $this->status();
	}//end assign()

	/**
	 * Create the service group when it does not exist.
	 *
	 * @return IGroup|null The group, or null when it could not be created.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function ensureGroup(): ?IGroup {
		$group = $this->groupManager->get($this->group());
		if ($group !== null) {
			return $group;
		}

		try {
			return $this->groupManager->createGroup($this->group());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: could not create a service group',
				['app' => Application::APP_ID, 'group' => $this->group(), 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end ensureGroup()

	/**
	 * What stops the uid from acting, or null when nothing does.
	 *
	 * @param string $userId The uid.
	 *
	 * @return string|null One of the REASON_* constants, or null.
	 */
	private function problemWith(string $userId): ?string {
		if ($userId === '') {
			return self::REASON_UNSET;
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			return self::REASON_UNKNOWN;
		}

		if ($account->isEnabled() === false) {
			return self::REASON_DISABLED;
		}

		if ($this->groupManager->isInGroup($userId, $this->group()) === false) {
			return self::REASON_NOT_IN_GROUP;
		}

		return null;
	}//end problemWith()
}//end class
