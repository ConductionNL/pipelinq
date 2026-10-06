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
 * previous user is restored in a `finally` (ADR-099), by {@see ServiceAccount},
 * which the messaging webhooks share. A missing, unknown, disabled or ungrouped
 * account refuses with 503 before anything is written.
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ServiceAccount;
use OCP\AppFramework\Http;

/**
 * Resolves the portal service account and runs portal writes as it.
 *
 * The resolution, the volatile switch and the assignment live in
 * {@see ServiceAccount}; this class names the config key and the group, and
 * refuses with the portal's own 503.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalServiceAccount extends ServiceAccount {
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
	 * The app-config key holding the uid.
	 *
	 * @return string The key.
	 */
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}//end configKey()

	/**
	 * The group the portal schemas grant.
	 *
	 * @return string The group id.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function group(): string {
		return self::GROUP;
	}//end group()

	/**
	 * Refuse every portal write with 503 portalUnavailable.
	 *
	 * @param string $userId The configured uid.
	 * @param string $reason Why the account cannot be used.
	 *
	 * @return never
	 *
	 * @throws PortalException 503 portalUnavailable.
	 */
	protected function refuse(string $userId, string $reason): never {
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
	}//end refuse()
}//end class
