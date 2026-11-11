<?php

/**
 * Pipelinq SoftOptInCheck.
 *
 * The checks a soft opt-in import must pass.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Mail\IMailer;

/**
 * Why an existing customer cannot be added to a list on the soft opt-in ground.
 * Built by SubscriptionService for one import.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
 */
final class SoftOptInCheck {
	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer The mailer (address validation).
	 */
	public function __construct(
		private IMailer $mailer,
	) {
	}//end __construct()

	/**
	 * Why a list cannot take a soft opt-in import, or null when it can.
	 *
	 * @param array<string, mixed>|null $list The list.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 */
	public function listRefusal(?array $list): ?string {
		if ($list === null) {
			return 'Not found';
		}

		if ((string)($list['optInMode'] ?? '') !== MailingListService::OPT_IN_SOFT) {
			return 'This list uses double opt-in, so a subscriber has to confirm the link themselves';
		}

		return null;
	}//end listRefusal()

	/**
	 * Why an address and its evidence cannot be imported, or null when they can.
	 *
	 * @param string               $email    The normalised address.
	 * @param array<string, mixed> $evidence The evidence; it must say the objection was offered.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 */
	public function importRefusal(string $email, array $evidence): ?string {
		if ($this->mailer->validateMailAddress($email) === false) {
			return 'That is not a valid email address';
		}

		if ((bool)($evidence['objectionOffered'] ?? false) === false) {
			return 'A soft opt-in needs a record that the customer was offered the chance to object';
		}

		return null;
	}//end importRefusal()
}//end class
