<?php

/**
 * Pipelinq MdmOpenRegisterSyncJob.
 *
 * Hourly background job that projects each active Master Entity's golden record
 * onto its corresponding OpenRegister schema instance (contact / client /
 * product), stamping masterEntityRef and isMasterRecord.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCA\Pipelinq\Service\Mdm\OpenRegisterSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly golden-record → OpenRegister projection job.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) run()'s $argument is required
 *  by the TimedJob contract but unused by this job.
 */
class MdmOpenRegisterSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory            $time       The time factory.
     * @param MdmObjectRepository     $repository The MDM object repository (master-entity reads).
     * @param OpenRegisterSyncService $orSync     The OR sync service.
     * @param LoggerInterface         $logger     The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private MdmObjectRepository $repository,
        private OpenRegisterSyncService $orSync,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run hourly.
        $this->setInterval(seconds: 3600);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Project active golden records onto their OpenRegister instances.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        try {
            $entities = $this->repository->findMasterEntities(null, 'active');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: OR sync skipped (could not list entities)',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $synced = 0;
        foreach ($entities as $entity) {
            $masterId = (string) ($entity['masterId'] ?? ($entity['id'] ?? ''));
            if ($masterId === '') {
                continue;
            }

            try {
                $result = $this->orSync->syncMasterToRegister($masterId);
                if ($result !== null) {
                    $synced++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Pipelinq MDM: OR sync failed for entity',
                    ['master' => $masterId, 'exception' => $e->getMessage()]
                );
            }
        }

        $this->logger->info('Pipelinq MDM: OpenRegister sync complete', ['synced' => $synced]);
    }//end run()
}//end class
