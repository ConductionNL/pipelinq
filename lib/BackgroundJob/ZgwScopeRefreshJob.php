<?php

/**
 * Pipelinq ZgwScopeRefreshJob.
 *
 * Timed background job that refreshes the AC (Autorisaties) scope cache for every
 * active ZGW endpoint on a fixed interval (default 15 minutes), so that newly
 * granted permissions are picked up without a restart and pre-flight scope
 * guards stay accurate (REQ-ZGW-006).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\AcClient;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Refreshes ZGW AC scope caches every 15 minutes.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.2
 */
class ZgwScopeRefreshJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory        $time       The time factory.
     * @param ZgwObjectRepository $repository The ZGW object persistence helper.
     * @param AcClient            $acClient   The autorisaties (AC) client.
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ZgwObjectRepository $repository,
        private AcClient $acClient,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 15 minutes (900 seconds).
        $this->setInterval(seconds: 900);
        $this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
    }//end __construct()

    /**
     * Refresh AC scopes for every active ZGW endpoint.
     *
     * @param mixed $argument The job argument (unused, required by TimedJob).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        try {
            $endpoints = $this->repository->findBy(entity: 'zgwEndpoint', filters: ['actief' => true]);
        } catch (\Throwable $e) {
            $this->logger->debug('ZgwScopeRefreshJob: skipping — '.$e->getMessage());
            return;
        }

        foreach ($endpoints as $endpoint) {
            $client = $this->repository->findOneByField(
                entity: 'zgwClient',
                field: 'id',
                value: (string) ($endpoint['clientId'] ?? '')
            );
            if ($client === null) {
                continue;
            }

            $this->acClient->refreshScopes(endpoint: $endpoint, client: $client);
        }
    }//end run()
}//end class
