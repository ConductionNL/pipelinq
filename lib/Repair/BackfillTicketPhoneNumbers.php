<?php

/**
 * Pipelinq BackfillTicketPhoneNumbers.
 *
 * Stores `phoneE164` on the tickets that carry a phone number from before the
 * phone search existed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\PhoneHistoryService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Idempotent: a ticket whose stored number already matches is skipped, so a
 * second run changes nothing.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
class BackfillTicketPhoneNumbers implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IAppManager         $appManager The app manager.
	 * @param PhoneHistoryService $history    The phone search, which owns the back-fill.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly PhoneHistoryService $history,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function getName(): string {
		return 'Store the phone number of every ticket in E.164 for the phone search';
	}//end getName()

	/**
	 * Run the back-fill.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function run(IOutput $output): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('OpenRegister is not installed; no ticket phone numbers to store.');
			return;
		}

		try {
			$counts = $this->history->backfillTicketPhones();
			$output->info(sprintf('Ticket phone numbers: %d stored, %d skipped.', $counts['updated'], $counts['skipped']));
		} catch (Throwable $e) {
			$output->warning('Ticket phone numbers were not stored: ' . $e->getMessage());
		}
	}//end run()
}//end class
