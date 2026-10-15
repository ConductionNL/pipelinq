<?php

/**
 * Pipelinq BackgroundServiceAccountCheck.
 *
 * Tells an admin, in the administration overview, whether the background
 * service account can be used and what stops while it cannot.
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
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\SetupCheck;

use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCP\IL10N;

/**
 * Warns while the background service account is unset, unknown, disabled or outside its group.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */
class BackgroundServiceAccountCheck extends ServiceAccountCheck {
	/**
	 * Constructor.
	 *
	 * @param BackgroundServiceAccount $serviceAccount The account to check.
	 * @param IL10N                    $l10n           The localisation service.
	 */
	public function __construct(BackgroundServiceAccount $serviceAccount, IL10N $l10n) {
		parent::__construct(serviceAccount: $serviceAccount, l10n: $l10n);
	}//end __construct()

	/**
	 * The name in the administration overview.
	 *
	 * @return string The name.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function getName(): string {
		return $this->l10n->t('Pipelinq background service account');
	}//end getName()

	/**
	 * What a usable account does.
	 *
	 * @param string $userId The account.
	 *
	 * @return string The sentence.
	 */
	protected function usableText(string $userId): string {
		return $this->l10n->t('Incoming calls are logged as %s.', [$userId]);
	}//end usableText()

	/**
	 * What stops working until an admin picks an account.
	 *
	 * @return string The sentence.
	 */
	protected function consequenceText(): string {
		return $this->l10n->t(
			'Until you choose one in the Pipelinq settings, incoming calls are not logged.'
		);
	}//end consequenceText()
}//end class
