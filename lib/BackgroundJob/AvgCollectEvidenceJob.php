<?php

/**
 * Pipelinq AvgCollectEvidenceJob.
 *
 * One-off queued job that runs a federated evidence-collection pass for a single
 * AVG request off the request thread. Queued by the evidence controller / handler
 * with the request UUID as its argument; delegates to EvidenceCollectionService,
 * which already handles per-source timeouts and deduplication so a slow or
 * unreachable source can never block the run.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\EvidenceCollectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued job for asynchronous AVG evidence collection.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.1
 */
class AvgCollectEvidenceJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory              $time       The time factory.
     * @param EvidenceCollectionService $collection The evidence collection service.
     * @param AvgRepository             $repository The AVG OR repository.
     * @param LoggerInterface           $logger     The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private EvidenceCollectionService $collection,
        private AvgRepository $repository,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the queued collection pass for the request named in the argument.
     *
     * @param mixed $argument The job argument: ['verzoekId' => '<uuid>'].
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#4.1
     */
    protected function run($argument): void
    {
        $verzoekId = '';
        if (is_array($argument) === true) {
            $verzoekId = (string) ($argument['verzoekId'] ?? '');
        }

        if ($verzoekId === '') {
            return;
        }

        $request = $this->repository->findOrNull(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $verzoekId);
        if ($request === null) {
            $this->logger->warning('AvgCollectEvidenceJob: request not found', ['verzoekId' => $verzoekId]);
            return;
        }

        try {
            $summary = $this->collection->collect(request: $request);
            $this->logger->info('AvgCollectEvidenceJob: completed', ['verzoekId' => $verzoekId, 'summary' => $summary]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AvgCollectEvidenceJob: error',
                ['verzoekId' => $verzoekId, 'exception' => $e->getMessage()]
            );
        }
    }//end run()
}//end class
