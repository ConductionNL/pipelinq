<?php

/**
 * Pipelinq MdmDataQualityScorerJob.
 *
 * Nightly background job that recomputes and persists the dataQualityScore for
 * every active Master Entity.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Mdm\DataQualityScorer;
use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly data-quality scoring job.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) run()'s $argument is required
 *  by the TimedJob contract but unused by this job.
 */
class MdmDataQualityScorerJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time           The time factory.
     * @param MasterEntityService $masterEntities The master-entity service.
     * @param DataQualityScorer   $scorer         The data-quality scorer.
     * @param LoggerInterface     $logger         The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private MasterEntityService $masterEntities,
        private DataQualityScorer $scorer,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run once per day (nightly).
        $this->setInterval(seconds: 86400);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Recompute the data-quality score for every active Master Entity.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        try {
            $entities = $this->masterEntities->findAll(null, 'active');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: data-quality scoring skipped (could not list entities)',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $scored = 0;
        foreach ($entities as $entity) {
            $masterId = (string) ($entity['masterId'] ?? ($entity['id'] ?? ''));
            if ($masterId === '') {
                continue;
            }

            try {
                $this->scorer->scoreEntity($masterId);
                $scored++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Pipelinq MDM: scoring failed for entity',
                    ['master' => $masterId, 'exception' => $e->getMessage()]
                );
            }
        }

        $this->logger->info('Pipelinq MDM: data-quality scoring complete', ['scored' => $scored]);
    }//end run()
}//end class
