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

/**
 * Warns while the portal service account is unset, unknown, disabled or outside its group.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalServiceAccountCheck extends ServiceAccountCheck {
	/**
	 * Constructor.
	 *
	 * @param PortalServiceAccount $serviceAccount The account to check.
	 * @param IL10N                $l10n           The localisation service.
	 */
	public function __construct(PortalServiceAccount $serviceAccount, IL10N $l10n) {
		parent::__construct(serviceAccount: $serviceAccount, l10n: $l10n);
	}//end __construct()

	/**
	 * The name in the administration overview.
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
	 * What a usable account does.
	 *
	 * @param string $userId The account.
	 *
	 * @return string The sentence.
	 */
	protected function usableText(string $userId): string {
		return $this->l10n->t('Customer portal writes run as %s.', [$userId]);
	}//end usableText()

	/**
	 * What stops working until an admin picks an account.
	 *
	 * @return string The sentence.
	 */
	protected function consequenceText(): string {
		return $this->l10n->t(
			'Until you choose one in the Pipelinq settings, the customer portal cannot save anything: residents cannot log in or reset their password.'
		);
	}//end consequenceText()
}//end class
