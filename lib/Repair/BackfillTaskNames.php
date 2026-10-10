<?php

/**
 * Pipelinq BackfillTaskNames.
 *
 * Repair step that names every existing task after its subject, silently.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\TaskNameBackfillService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step: name existing tasks after their subject, without notifying.
 *
 * Idempotent: a task that already has a name is not saved, so a second run
 * writes nothing.
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */
class BackfillTaskNames implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param TaskNameBackfillService $service Does the work.
	 */
	public function __construct(
		private readonly TaskNameBackfillService $service,
	) {
	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function getName(): string {
		return 'Name existing tasks after their subject, without notifying anyone';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function run(IOutput $output): void {
		$result = $this->service->backfill();

		$reason = match ($result['status']) {
			'not-configured' => 'the task schema is not configured yet',
			'no-silent-path' => 'this OpenRegister cannot save without notifying the assignee',
			'scope-inactive' => 'OpenRegister\'s system scope was not active at the write',
			'read-failed' => 'the tasks could not be read',
			default => '',
		};

		if ($reason !== '' && $result['named'] === 0) {
			$output->warning(sprintf('BackfillTaskNames: no task named, because %s.', $reason));
			return;
		}

		$output->info(
			sprintf(
				'BackfillTaskNames: %d task(s) named after their subject, %d left as they were, %d could not be saved.',
				$result['named'],
				$result['skipped'],
				$result['failed']
			)
		);

		if ($reason !== '') {
			$output->warning(sprintf('BackfillTaskNames: stopped early, because %s.', $reason));
		}
	}//end run()
}//end class
