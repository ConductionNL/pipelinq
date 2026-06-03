<?php

/**
 * Pipelinq NrcNotificationHandler.
 *
 * Dispatches an authenticated inbound NRC notification to the correct per-kanaal
 * handler: zaak/status changes update the linked pipelinq Request status (which
 * in turn fires OpenRegister's ObjectUpdatedEvent and any subscribed workflow),
 * besluit creations are reconciled to mappings, and catalogi notifications
 * invalidate the ZTC cache. Handlers are resilient — they log and swallow
 * errors rather than throwing, to avoid NRC redelivery storms (REQ-ZGW-007).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-kanaal NRC notification dispatcher.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#3.2
 */
class NrcNotificationHandler
{
    /**
     * Constructor.
     *
     * @param ZgwObjectRepository $repository The ZGW object persistence helper.
     * @param ZrcClient           $zrcClient  The Zaken API client.
     * @param ZtcClient           $ztcClient  The Catalogi API client (cache invalidation).
     * @param IAppConfig          $appConfig  The app config (request register/schema).
     * @param ContainerInterface  $container  The container (OpenRegister ObjectService).
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private ZgwObjectRepository $repository,
        private ZrcClient $zrcClient,
        private ZtcClient $ztcClient,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle one authenticated NRC notification.
     *
     * @param array<string, mixed> $abonnement   The NrcAbonnement the bearer matched.
     * @param array<string, mixed> $notification The decoded NRC notification body.
     *
     * @return void
     */
    public function handle(array $abonnement, array $notification): void
    {
        $start  = microtime(true);
        $kanaal = (string) ($notification['kanaal'] ?? '');

        $this->touchAbonnement(abonnement: $abonnement);

        try {
            $endpoint = $this->repository->findOneByField(
                entity: 'zgwEndpoint',
                field: 'id',
                value: (string) ($abonnement['endpointId'] ?? '')
            );
            if ($endpoint === null) {
                $this->logger->warning('NRC: no endpoint for abonnement', ['abonnement' => ($abonnement['id'] ?? '')]);
                return;
            }

            match ($kanaal) {
                'zaken'     => $this->handleZaken(endpoint: $endpoint, notification: $notification),
                'besluiten' => $this->handleBesluiten(notification: $notification),
                'catalogi'  => $this->ztcClient->invalidateCache(
                    endpoint: $endpoint,
                    resourceType: (string) ($notification['resource'] ?? '')
                ),
                default     => $this->logger->debug('NRC: unhandled kanaal', ['kanaal' => $kanaal]),
            };
        } catch (\Throwable $e) {
            // Never rethrow: NRC redelivers on non-2xx and we have already
            // ack'd with 202. Persistent failures are surfaced via the log.
            $this->logger->error(
                'NRC: notification handling failed',
                ['kanaal' => $kanaal, 'exception' => $e->getMessage()]
            );
        }//end try

        $elapsed = (microtime(true) - $start);
        if ($elapsed > 5.0) {
            $this->logger->warning('NRC: handler exceeded 5s budget', ['kanaal' => $kanaal, 'seconds' => $elapsed]);
        }
    }//end handle()

    /**
     * Handle a "zaken" kanaal notification (zaak/status changes).
     *
     * @param array<string, mixed> $endpoint     The owning ZgwEndpoint object array.
     * @param array<string, mixed> $notification The NRC notification body.
     *
     * @return void
     */
    private function handleZaken(array $endpoint, array $notification): void
    {
        $resource    = (string) ($notification['resource'] ?? '');
        $hoofdObject = (string) ($notification['hoofdObject'] ?? '');
        $mapping     = $this->repository->findOneByField(
            entity: 'zgwResourceMapping',
            field: 'zgwUrl',
            value: $hoofdObject
        );

        if ($mapping === null) {
            // Zaak not (yet) known locally; nothing to reconcile.
            return;
        }

        if ($resource !== 'status') {
            // Zaak create/update for an already-registered zaak: no-op.
            return;
        }

        $client = $this->repository->findOneByField(
            entity: 'zgwClient',
            field: 'id',
            value: (string) ($endpoint['clientId'] ?? '')
        );
        if ($client === null) {
            return;
        }

        $statusUrl = (string) ($notification['resourceUrl'] ?? '');
        $status    = $this->zrcClient->getStatus(endpoint: $endpoint, client: $client, statusUrl: $statusUrl);

        $statustypeUrl      = (string) ($status['statustype'] ?? '');
        $statusOmschrijving = $this->resolveStatusLabel(
            status: $status,
            statustypeUrl: $statustypeUrl
        );

        $this->updateRequestStatus(
            pipelinqId: (string) ($mapping['pipelinqId'] ?? ''),
            status: $statusOmschrijving
        );
    }//end handleZaken()

    /**
     * Resolve a human-readable status label from the status payload.
     *
     * @param array<string, mixed> $status        The status representation.
     * @param string               $statustypeUrl The statustype URL.
     *
     * @return string The status label (falls back to the toelichting or URL).
     */
    private function resolveStatusLabel(array $status, string $statustypeUrl): string
    {
        $omschrijving = (string) ($status['statustoelichting'] ?? '');
        if ($omschrijving !== '') {
            return $omschrijving;
        }

        // The statustype omschrijving is the canonical label; the ZTC cache is
        // keyed by omschrijving so we use the URL tail as a stable fallback.
        $segments = explode('/', rtrim($statustypeUrl, '/'));
        $tail     = (string) end($segments);
        if ($tail === '') {
            return $statustypeUrl;
        }

        return $tail;
    }//end resolveStatusLabel()

    /**
     * Handle a "besluiten" kanaal notification (reconcile besluit mappings).
     *
     * @param array<string, mixed> $notification The NRC notification body.
     *
     * @return void
     */
    private function handleBesluiten(array $notification): void
    {
        $hoofdObject = (string) ($notification['hoofdObject'] ?? '');
        $existing    = $this->repository->findOneByField(
            entity: 'zgwResourceMapping',
            field: 'zgwUrl',
            value: $hoofdObject
        );

        if ($existing !== null) {
            // Besluit already mapped: no-op.
            return;
        }

        $this->logger->info('NRC: besluit notification for unmapped besluit', ['besluit' => $hoofdObject]);
    }//end handleBesluiten()

    /**
     * Update the pipelinq Request status (fires OR ObjectUpdatedEvent → workflows).
     *
     * @param string $pipelinqId The Request UUID.
     * @param string $status     The new status label.
     *
     * @return void
     */
    private function updateRequestStatus(string $pipelinqId, string $status): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        if ($pipelinqId === '' || $register === '' || $schema === '' || $status === '') {
            return;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $request       = $objectService->find($pipelinqId, []);
        $requestArray  = $this->repository->toArray(object: $request);
        unset($requestArray['@self']);
        $requestArray['status'] = $status;

        $objectService->saveObject(
            object: $requestArray,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $pipelinqId
        );
    }//end updateRequestStatus()

    /**
     * Stamp laatstOntvangenOp on the abonnement.
     *
     * @param array<string, mixed> $abonnement The NrcAbonnement object array.
     *
     * @return void
     */
    private function touchAbonnement(array $abonnement): void
    {
        $uuid = (string) ($abonnement['@self']['uuid'] ?? ($abonnement['id'] ?? ''));
        if ($uuid === '') {
            return;
        }

        try {
            $data = $abonnement;
            unset($data['@self']);
            $data['laatstOntvangenOp'] = gmdate('c');
            $this->repository->save(entity: 'nrcAbonnement', data: $data, uuid: $uuid);
        } catch (\Throwable $e) {
            $this->logger->warning('NRC: failed to stamp abonnement', ['exception' => $e->getMessage()]);
        }
    }//end touchAbonnement()
}//end class
