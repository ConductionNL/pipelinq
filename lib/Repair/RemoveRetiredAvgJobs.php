<?php

/**
 * Pipelinq RemoveRetiredAvgJobs.
 *
 * Upgrade repair step that unregisters the retired AVG/DSAR background jobs from
 * `oc_jobs` (ADR-047 Phase-3). Removing a `<job>` entry from `appinfo/info.xml`
 * does NOT delete its existing `oc_jobs` row, so without this step the
 * background-job scheduler would log a "class not found" error every tick for
 * each removed job. The removed classes are gone from disk; this step deletes
 * their scheduler rows by class name (idempotent — a no-op when already absent).
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-local-surface-retirement/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Unregister the retired AVG/DSAR background jobs from oc_jobs.
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-local-surface-retirement/spec.md
 */
class RemoveRetiredAvgJobs implements IRepairStep
{
    /**
     * Fully-qualified class names of the retired jobs to unregister.
     *
     * @var array<int, string>
     */
    private const RETIRED_JOBS = [
        'OCA\\Pipelinq\\BackgroundJob\\AvgDeadlineTrackerJob',
        'OCA\\Pipelinq\\BackgroundJob\\AvgDpiaPatternDetectionJob',
        'OCA\\Pipelinq\\BackgroundJob\\AvgRetentionJob',
        'OCA\\Pipelinq\\BackgroundJob\\AvgCollectEvidenceJob',
    ];

    /**
     * Constructor.
     *
     * @param IJobList        $jobList The Nextcloud background-job list.
     * @param LoggerInterface $logger  The logger.
     */
    public function __construct(
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The repair step name.
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-local-surface-retirement/spec.md
     */
    public function getName(): string
    {
        return 'Unregister retired AVG/DSAR background jobs from oc_jobs (ADR-047 Phase-3)';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-local-surface-retirement/spec.md
     */
    public function run(IOutput $output): void
    {
        foreach (self::RETIRED_JOBS as $jobClass) {
            try {
                // The job classes are deleted, so they are no longer loadable as
                // class-string<IJob>; remove() accepts the bare class name to purge
                // the orphaned oc_jobs row (phpstan ignore in phpstan.neon).
                $this->jobList->remove($jobClass);
                $output->info('Unregistered retired AVG job: '.$jobClass);
            } catch (\Throwable $e) {
                $output->warning('Failed to unregister '.$jobClass.': '.$e->getMessage());
                $this->logger->warning(
                    'Pipelinq: failed to unregister retired AVG job',
                    ['job' => $jobClass, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end run()
}//end class
