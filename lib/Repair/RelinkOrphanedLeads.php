<?php

/**
 * Pipelinq RelinkOrphanedLeads repair step.
 *
 * Moves leads whose pipeline was deleted to the default lead pipeline, so
 * they show on a board again (pipelinq review F1).
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\OrphanedLeadRepairService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step: move leads off deleted pipelines.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */
class RelinkOrphanedLeads implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param OrphanedLeadRepairService $service Does the work.
	 */
	public function __construct(
		private readonly OrphanedLeadRepairService $service,
	) {
	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
	 */
	public function getName(): string {
		return 'Move leads off deleted pipelines onto the default pipeline';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
	 */
	public function run(IOutput $output): void {
		$counts = $this->service->repair();
		$output->info(
			sprintf(
				'RelinkOrphanedLeads: %d lead(s) moved off a deleted pipeline, %d could not be saved',
				$counts['moved'],
				$counts['failed']
			)
		);
	}//end run()
}//end class
