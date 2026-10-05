<?php

/**
 * Pipelinq PortalServiceAccountCheck.
 *
 * Tells the admin, in the administration overview, when the customer portal
 * has no usable service account. Until one is picked the portal refuses every
 * write with 503: no reset mail, no login.
 *
 * @category SetupCheck
 * @package  OCA\Pipelinq\SetupCheck
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

namespace OCA\Pipelinq\SetupCheck;

use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns when the portal service account is unset or unusable.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalServiceAccountCheck implements ISetupCheck {
	/**
	 * Constructor.
	 *
	 * @param PortalServiceAccount $serviceAccount The account to check.
	 * @param IL10N                $l10n           The localisation service.
	 */
	public function __construct(
		private readonly PortalServiceAccount $serviceAccount,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The overview category.
	 *
	 * @return string The category.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * The check's name.
	 *
	 * @return string The name.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getName(): string {
		return $this->l10n->t('Pipelinq customer portal service account');
	}//end getName()

	/**
	 * Success when the account can be used, a warning naming why otherwise.
	 *
	 * @return SetupResult The result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the only way OCP offers to build one.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function run(): SetupResult {
		$status = $this->serviceAccount->status();
		if ($status['usable'] === true) {
			return SetupResult::success(
				$this->l10n->t('Customer portal writes run as %s.', [$status['userId']])
			);
		}

		$why = match ($status['reason']) {
			PortalServiceAccount::REASON_UNKNOWN => $this->l10n->t('The chosen account does not exist.'),
			PortalServiceAccount::REASON_DISABLED => $this->l10n->t('The chosen account is disabled.'),
			PortalServiceAccount::REASON_NOT_IN_GROUP => $this->l10n->t(
				'The chosen account is not in the group %s.',
				[PortalServiceAccount::GROUP]
			),
			default => $this->l10n->t('No account is chosen.'),
		};

		return SetupResult::warning(
			$why.' '.$this->l10n->t(
				'Until you choose one in the Pipelinq settings, the customer portal cannot save anything: residents cannot log in or reset their password.'
			)
		);
	}//end run()
}//end class
