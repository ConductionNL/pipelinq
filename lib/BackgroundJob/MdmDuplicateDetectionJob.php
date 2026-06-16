<?php

/**
 * Pipelinq MdmDuplicateDetectionJob.
 *
 * Daily background job that scans every MDM entity type for duplicate
 * candidates (deterministic + probabilistic) and auto-merges the high-confidence
 * candidates whose deciding key is non-overridable, leaving the rest for steward
 * review on the Duplicate Candidates dashboard.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Mdm\DuplicateDetectionService;
use OCA\Pipelinq\Service\Mdm\MergeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily duplicate-detection and auto-merge job.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) run()'s $argument is required
 *  by the TimedJob contract but unused by this job.
 */
class MdmDuplicateDetectionJob extends TimedJob
{
    /**
     * Entity types scanned each run.
     *
     * @var array<int, string>
     */
    private const ENTITY_TYPES = ['contact', 'account', 'product', 'vendor'];

    /**
     * Constructor.
     *
     * @param ITimeFactory              $time      The time factory.
     * @param DuplicateDetectionService $detection The duplicate-detection service.
     * @param MergeService              $merge     The merge service.
     * @param LoggerInterface           $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private DuplicateDetectionService $detection,
        private MergeService $merge,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run once per day.
        $this->setInterval(seconds: 86400);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Execute the daily duplicate scan.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        foreach (self::ENTITY_TYPES as $entityType) {
            try {
                $candidates = $this->detection->detectDuplicates($entityType);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Pipelinq MDM: duplicate detection failed',
                    ['entityType' => $entityType, 'exception' => $e->getMessage()]
                );
                continue;
            }

            $autoMerged = 0;
            foreach ($candidates as $candidate) {
                if (($candidate['autoMergeEligible'] ?? false) !== true) {
                    continue;
                }

                $reason = 'duplicate-detected-probabilistic';
                if ((string) ($candidate['linkageMethod'] ?? '') === 'deterministic-key') {
                    $reason = 'duplicate-detected-deterministic';
                }

                try {
                    $this->merge->executeMerge(
                        fromMasterId: (string) $candidate['fromMasterId'],
                        intoMasterId: (string) $candidate['intoMasterId'],
                        mergedBy: 'system-auto-merge',
                        mergeReason: $reason
                    );
                    $autoMerged++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Pipelinq MDM: auto-merge failed',
                        ['from' => ($candidate['fromMasterId'] ?? ''), 'exception' => $e->getMessage()]
                    );
                }
            }//end foreach

            $this->logger->info(
                'Pipelinq MDM: duplicate detection complete',
                ['entityType' => $entityType, 'candidates' => count($candidates), 'autoMerged' => $autoMerged]
            );
        }//end foreach
    }//end run()
}//end class
