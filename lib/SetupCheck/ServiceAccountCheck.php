<?php

/**
 * Pipelinq ServiceAccountCheck.
 *
 * Shared by the portal and messaging setup checks: warns in the
 * administration overview while a service account cannot be used, and says
 * why and what stops working until an admin picks one.
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
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\SetupCheck;

use OCA\Pipelinq\Service\ServiceAccount;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns while a service account is unset, unknown, disabled or outside its group.
 *
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */
abstract class ServiceAccountCheck implements ISetupCheck {
	/**
	 * Constructor.
	 *
	 * @param ServiceAccount $serviceAccount The account to check.
	 * @param IL10N          $l10n           The localisation service.
	 */
	public function __construct(
		private readonly ServiceAccount $serviceAccount,
		protected readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * What a usable account does, naming it.
	 *
	 * @param string $userId The account.
	 *
	 * @return string The sentence.
	 */
	abstract protected function usableText(string $userId): string;

	/**
	 * What stops working until an admin picks an account.
	 *
	 * @return string The sentence.
	 */
	abstract protected function consequenceText(): string;

	/**
	 * The category in the administration overview.
	 *
	 * @return string The category.
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * Run the check.
	 *
	 * @return SetupResult The result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the only way OCP offers to build one.
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function run(): SetupResult {
		$status = $this->serviceAccount->status();
		if ($status['usable'] === true) {
			return SetupResult::success($this->usableText(userId: $status['userId']));
		}

		$why = match ($status['reason']) {
			ServiceAccount::REASON_UNKNOWN => $this->l10n->t('The chosen account does not exist.'),
			ServiceAccount::REASON_DISABLED => $this->l10n->t('The chosen account is disabled.'),
			ServiceAccount::REASON_NOT_IN_GROUP => $this->l10n->t(
				'The chosen account is not in the group %s.',
				[$this->serviceAccount->group()]
			),
			default => $this->l10n->t('No account is chosen.'),
		};

		return SetupResult::warning($why.' '.$this->consequenceText());
	}//end run()
}//end class
