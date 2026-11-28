<?php

/**
 * Pipelinq BackfillTicketMunicipality repair step.
 *
 * Gives existing tickets their client's municipality; a ticket that has one
 * keeps it. Safe to run on every upgrade.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\TicketMunicipalityBackfillService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Fills in the municipality of existing tickets from their client.
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */
class BackfillTicketMunicipality implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param TicketMunicipalityBackfillService $service The backfill.
	 */
	public function __construct(
		private readonly TicketMunicipalityBackfillService $service,
	) {
	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
	 */
	public function getName(): string {
		return 'Give existing tickets their client\'s municipality, without notifying anyone';
	}//end getName()

	/**
	 * Run the backfill and report what it did.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
	 */
	public function run(IOutput $output): void {
		$result = $this->service->backfill();
		$reason = match ($result['status']) {
			'not-configured' => 'the client or ticket schema is not configured yet',
			'no-silent-path' => 'this OpenRegister cannot save without notifying anyone',
			'scope-inactive' => 'OpenRegister\'s system scope was not active at the write',
			'read-failed' => 'the clients or tickets could not be read',
			default => '',
		};

		if ($reason !== '' && $result['filled'] === 0) {
			$output->warning(sprintf('BackfillTicketMunicipality: no ticket filled in, because %s.', $reason));
			return;
		}

		$output->info(
			sprintf(
				'BackfillTicketMunicipality: %d ticket(s) given their client\'s municipality, %d left as they were, %d could not be saved.',
				$result['filled'],
				$result['skipped'],
				$result['failed']
			)
		);
		if ($reason !== '') {
			$output->warning(sprintf('BackfillTicketMunicipality: stopped early, because %s.', $reason));
		}
	}//end run()
}//end class
