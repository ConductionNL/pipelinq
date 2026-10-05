<?php

/**
 * Pipelinq PortalServiceAccount.
 *
 * The Nextcloud account every customer-portal write runs as. A portal request
 * has no Nextcloud session: residents sign in with portal credentials, so
 * OpenRegister would see the caller as Anonymous. Instead of switching
 * OpenRegister's access checks off, the portal acts as one account an admin
 * picks, and the portal schemas grant that account's group create and update
 * and nothing else. It is the consumer model integriq's intakes use
 * (WebhookConnection: a consumer names the account its writes run as).
 *
 * The identity is set with `IUserSession::setVolatileActiveUser()` and the
 * previous user is restored in a `finally` (ADR-099). It is never written to
 * the PHP session. A missing, unknown, disabled or ungrouped account refuses
 * with 503 before anything is written.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the portal service account and runs portal writes as it.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalServiceAccount {
	/**
	 * App-config key holding the uid of the portal service account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'portal_service_account';

	/**
	 * The group the portal schemas grant create and update to.
	 *
	 * @var string
	 */
	public const GROUP = 'pipelinq-portal-service';

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
	 * @param IUserSession    $userSession  Carries the acting user for one write.
	 * @param LoggerInterface $logger       Logs every refusal.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured uid, or an empty string.
	 *
	 * @return string The uid.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function configuredUserId(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
	}//end configuredUserId()

	/**
	 * Whether the account can be used, and why not when it cannot.
	 *
	 * @return array{userId: string, usable: bool, reason: string|null} The status.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function status(): array {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);

		return ['userId' => $userId, 'usable' => ($reason === null), 'reason' => $reason];
	}//end status()

	/**
	 * The account, or a 503 refusal.
	 *
	 * @return IUser The enabled account, a member of the service group.
	 *
	 * @throws PortalException 503 portalUnavailable when it cannot be used.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function require(): IUser {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);
		if ($reason !== null) {
			$this->logger->error(
				'Pipelinq portal: no usable portal service account, the portal refuses every write',
				['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason]
			);
			// The reason goes to the log only: the caller is anonymous.
			throw new PortalException(
				Http::STATUS_SERVICE_UNAVAILABLE,
				'portalUnavailable',
				'Het portaal is tijdelijk niet beschikbaar.'
			);
		}

		return $this->userManager->get($userId);
	}//end require()

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws PortalException 503 when the account cannot be used; nothing runs then.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
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
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
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
			throw new InvalidArgumentException('The group '.self::GROUP.' could not be created.');
		}

		if ($group->inGroup($account) === false) {
			$group->addUser($account);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $userId);

		return $this->status();
	}//end assign()

	/**
	 * Create the service group when it does not exist.
	 *
	 * @return IGroup|null The group, or null when it could not be created.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function ensureGroup(): ?IGroup {
		$group = $this->groupManager->get(self::GROUP);
		if ($group !== null) {
			return $group;
		}

		try {
			return $this->groupManager->createGroup(self::GROUP);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: could not create the portal service group',
				['app' => Application::APP_ID, 'group' => self::GROUP, 'exception' => $e->getMessage()]
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

		if ($this->groupManager->isInGroup($userId, self::GROUP) === false) {
			return self::REASON_NOT_IN_GROUP;
		}

		return null;
	}//end problemWith()
}//end class
