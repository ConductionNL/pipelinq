<?php

/**
 * Pipelinq NrcNotificationListener.
 *
 * Per-kanaal dispatcher for inbound NRC notifications POSTed to
 * `POST /api/zgw/notificaties/inbox`. The controller authenticates the
 * Bearer header against the matching `NrcAbonnement.callbackAuth`, then
 * hands the parsed payload (kanaal, resource, actie, resourceUrl, hoofdObject,
 * abonnement) to `dispatch()`. The listener:
 *
 *   1. Updates `NrcAbonnement.laatstOntvangenOp` to now.
 *   2. Routes to the per-kanaal handler:
 *      - "zaken" + resource="zaak"   + actie="create" → record/no-op zaak mapping.
 *      - "zaken" + resource="status" + actie="create" → GET status, resolve
 *        statustype omschrijving from ZTC, update Request.status, log
 *        elapsed ms (5s budget per REQ-ZGW-007).
 *      - "besluiten" + resource="besluit" + actie="create" → no-op (mapping
 *        is already persisted by createBesluit on the outbound path).
 *      - "catalogi"  any                                 → invalidate ZTC cache.
 *   3. Logs but never re-throws — NRC will retry the callback otherwise.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * NRC notification dispatcher.
 */
class NrcNotificationListener
{
    /**
     * Notifications MUST complete within this many milliseconds (REQ-ZGW-007).
     */
    public const TARGET_DISPATCH_MS = 5000;

    /**
     * Constructor.
     *
     * @param ZgwRegisterAccess $registers Register facade.
     * @param ZrcClient         $zrc       ZRC client (status fetches).
     * @param ZtcClient         $ztc       ZTC client (omschrijving resolution + cache invalidation).
     * @param LoggerInterface   $logger    PSR-3 logger.
     */
    public function __construct(
        private ZgwRegisterAccess $registers,
        private ZrcClient $zrc,
        private ZtcClient $ztc,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch a single inbound notification.
     *
     * @param array<string, mixed> $abonnement   Matching NrcAbonnement record.
     * @param array<string, mixed> $notification Parsed inbound JSON payload.
     *
     * @return void
     */
    public function dispatch(array $abonnement, array $notification): void
    {
        $start = microtime(true);

        $endpointId = (string) ($abonnement['endpointId'] ?? '');
        if ($endpointId !== '') {
            $endpoint = $this->registers->find(ZgwRegisterAccess::SCHEMA_ENDPOINT, $endpointId);
        } else {
            $endpoint = null;
        }

        if ($endpoint === null) {
            $this->logger->warning(
                'ZGW NRC: notification received for unknown endpoint',
                ['endpoint' => $endpointId]
            );
            return;
        }

        $this->markReceived(abonnement: $abonnement);

        $kanaal   = (string) ($notification['kanaal'] ?? '');
        $resource = (string) ($notification['resource'] ?? '');
        $actie    = (string) ($notification['actie'] ?? '');
        try {
            match (true) {
                ($kanaal === 'zaken' && $resource === 'zaak' && $actie === 'create')
                    => $this->onZaakCreated(endpoint: $endpoint, notification: $notification),
                ($kanaal === 'zaken' && $resource === 'status' && $actie === 'create')
                    => $this->onStatusCreated(endpoint: $endpoint, notification: $notification),
                ($kanaal === 'besluiten' && $resource === 'besluit' && $actie === 'create')
                    => $this->onBesluitCreated(endpoint: $endpoint, notification: $notification),
                ($kanaal === 'catalogi')
                    => $this->onCatalogiChange(endpoint: $endpoint, notification: $notification),
                default => null,
            };
        } catch (Throwable $e) {
            $this->logger->error(
                'ZGW NRC: dispatch handler threw',
                ['kanaal' => $kanaal, 'resource' => $resource, 'actie' => $actie, 'err' => $e->getMessage()]
            );
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        if ($elapsedMs > self::TARGET_DISPATCH_MS) {
            $this->logger->warning(
                'ZGW NRC: dispatch exceeded 5s budget',
                ['kanaal' => $kanaal, 'resource' => $resource, 'actie' => $actie, 'ms' => $elapsedMs]
            );
        }
    }//end dispatch()

    /**
     * Update `laatstOntvangenOp` on the matching NrcAbonnement.
     *
     * @param array<string, mixed> $abonnement NrcAbonnement payload.
     *
     * @return void
     */
    private function markReceived(array $abonnement): void
    {
        $uuid = (string) ($abonnement['@self']['uuid'] ?? $abonnement['id'] ?? '');
        $abonnement['laatstOntvangenOp'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        if ($uuid !== '') {
            $saveUuid = $uuid;
        } else {
            $saveUuid = null;
        }

        $this->registers->save(
            ZgwRegisterAccess::SCHEMA_ABONN,
            $abonnement,
            $saveUuid
        );
    }//end markReceived()

    /**
     * Handle zaak.create — record mapping if it isn't already persisted.
     *
     * @param array<string, mixed> $endpoint     Endpoint payload.
     * @param array<string, mixed> $notification Inbound payload.
     *
     * @return void
     */
    private function onZaakCreated(array $endpoint, array $notification): void
    {
        $zaakUrl = (string) ($notification['hoofdObject'] ?? $notification['resourceUrl'] ?? '');
        if ($zaakUrl === '') {
            return;
        }

        $existing = $this->registers->findAll(
            ZgwRegisterAccess::SCHEMA_MAPPING,
            ['zgwUrl' => $zaakUrl]
        );
        if ($existing !== []) {
            return;
        }

        $this->logger->info('ZGW NRC: observed externally-created zaak', ['zaak' => $zaakUrl]);
    }//end onZaakCreated()

    /**
     * Handle status.create — fetch status, resolve omschrijving, update Request.
     *
     * @param array<string, mixed> $endpoint     Endpoint payload.
     * @param array<string, mixed> $notification Inbound payload.
     *
     * @return void
     */
    private function onStatusCreated(array $endpoint, array $notification): void
    {
        $statusUrl = (string) ($notification['resourceUrl'] ?? '');
        $zaakUrl   = (string) ($notification['hoofdObject'] ?? '');
        if ($statusUrl === '' || $zaakUrl === '') {
            return;
        }

        $mappings = $this->registers->findAll(
            ZgwRegisterAccess::SCHEMA_MAPPING,
            ['zgwUrl' => $zaakUrl]
        );
        if ($mappings === []) {
            return;
        }

        $mapping = $mappings[0];
        try {
            $status = $this->zrc->getStatus($endpoint, $statusUrl);
        } catch (Throwable $e) {
            $this->logger->warning('ZGW NRC: failed to GET status', ['url' => $statusUrl, 'err' => $e->getMessage()]);
            return;
        }

        $statustypeUrl = (string) ($status['statustype'] ?? '');
        if ($statustypeUrl !== '') {
            $omsch = $this->ztc->resolveOmschrijvingFromUrl($endpoint, $statustypeUrl);
        } else {
            $omsch = null;
        }

        $requestId = (string) ($mapping['pipelinqId'] ?? '');
        if ($requestId === '' || $omsch === null) {
            return;
        }

        $request = $this->registers->find('request', $requestId);
        if ($request === null) {
            $this->logger->info('ZGW NRC: status for unknown pipelinq Request', ['id' => $requestId]);
            return;
        }

        $request['status'] = $omsch;
        $this->registers->save('request', $request, $requestId);
    }//end onStatusCreated()

    /**
     * Handle besluit.create — observability log, mapping already persisted on outbound.
     *
     * @param array<string, mixed> $endpoint     Endpoint payload.
     * @param array<string, mixed> $notification Inbound payload.
     *
     * @return void
     */
    private function onBesluitCreated(array $endpoint, array $notification): void
    {
        $url = (string) ($notification['resourceUrl'] ?? $notification['hoofdObject'] ?? '');
        if ($url === '') {
            return;
        }

        $existing = $this->registers->findAll(
            ZgwRegisterAccess::SCHEMA_MAPPING,
            ['zgwUrl' => $url]
        );
        if ($existing !== []) {
            return;
        }

        $this->logger->info('ZGW NRC: observed externally-created besluit', ['besluit' => $url]);
    }//end onBesluitCreated()

    /**
     * Handle catalogi changes — invalidate the ZTC cache.
     *
     * @param array<string, mixed> $endpoint     Endpoint payload.
     * @param array<string, mixed> $notification Inbound payload.
     *
     * @return void
     */
    private function onCatalogiChange(array $endpoint, array $notification): void
    {
        $resource    = (string) ($notification['resource'] ?? '*');
        $resourceMap = [
            'zaaktype'      => ZtcClient::RESOURCE_ZAAKTYPE,
            'statustype'    => ZtcClient::RESOURCE_STATUSTYPE,
            'roltype'       => ZtcClient::RESOURCE_ROLTYPE,
            'resultaattype' => ZtcClient::RESOURCE_RESULTAATTYPE,
            'besluittype'   => ZtcClient::RESOURCE_BESLUITTYPE,
        ];
        $bucket      = $resourceMap[$resource] ?? '*';
        $this->ztc->invalidateCache($endpoint, $bucket);
    }//end onCatalogiChange()
}//end class
