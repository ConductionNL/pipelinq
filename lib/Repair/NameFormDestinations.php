<?php

/**
 * Repair step: give this app's hosted forms the destination they submit into.
 *
 * Runs once per upgrade and changes a form once: a form that already names
 * its destination is skipped. See FormDestinationBackfill.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\Marketing\FormDestinationBackfill;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Names the destination of forms made before forms had one.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
class NameFormDestinations implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param FormDestinationBackfill $backfill Does the work.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function __construct(
		private readonly FormDestinationBackfill $backfill,
	) {
	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function getName(): string {
		return 'Name the destination of campaign forms that have none';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function run(IOutput $output): void {
		$result = $this->backfill->run();

		if ($result['status'] === 'no-openregister') {
			$output->info('NameFormDestinations: nothing to do, OpenRegister is not available.');
			return;
		}

		if ($result['status'] === 'read-failed') {
			$output->info('NameFormDestinations: nothing to do, there are no hosted forms to read.');
			return;
		}

		$output->info(
			sprintf(
				'NameFormDestinations: %d form(s) now submit into a lead, %d already named a destination, %d could not be saved.',
				$result['named'],
				$result['skipped'],
				$result['failed']
			)
		);

		if ($result['failed'] > 0) {
			$output->warning('NameFormDestinations: a form without a destination cannot be submitted. See the log, then run occ maintenance:repair again.');
		}
	}//end run()
}//end class
