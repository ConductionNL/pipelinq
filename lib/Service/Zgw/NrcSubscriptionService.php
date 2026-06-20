<?php

/**
 * Pipelinq NrcSubscriptionService.
 *
 * Manages the lifecycle of an NRC abonnement per ZgwEndpoint:
 *
 *   - registerAbonnement()   — POST /api/v1/abonnement on first activation;
 *                              persist the returned URL + a freshly-minted
 *                              callback bearer token onto a new NrcAbonnement.
 *   - syncAbonnement()       — when the configured kanalen drift from the
 *                              abonnement state, unsubscribe and re-register
 *                              with the new kanalen.
 *   - unregisterAbonnement() — DELETE the abonnement at NRC; mark inactive.
 *
 * The callback bearer is generated locally (32 bytes hex), persisted on the
 * NrcAbonnement as a vault reference, and shared with NRC at registration
 * time so subsequent inbound POSTs to /api/zgw/notificaties/inbox carry it
 * as the `Authorization: Bearer` header (verified by ZgwNotificationController).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
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

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * NRC abonnement lifecycle service.
 */
class NrcSubscriptionService
{
    /**
     * Constructor.
     *
     * @param ZgwApiClient      $api       Base transport.
     * @param ZgwRegisterAccess $registers Register facade.
     * @param LoggerInterface   $logger    PSR-3 logger.
     */
    public function __construct(
        private ZgwApiClient $api,
        private ZgwRegisterAccess $registers,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register an abonnement at NRC and persist the NrcAbonnement record.
     *
     * @param array<string, mixed>            $endpoint    ZgwEndpoint payload.
     * @param array<int, array<string,mixed>> $kanalen     List of `{naam, filters}` kanaal entries.
     * @param string                          $callbackUrl Pipelinq inbox URL.
     *
     * @return array<string, mixed> Saved NrcAbonnement record.
     *
     * @throws NrcSubscriptionFailedException On HTTP failure.
     */
    public function registerAbonnement(array $endpoint, array $kanalen, string $callbackUrl): array
    {
        $client = $this->registers->findClientForEndpoint($endpoint);
        if ($client === null) {
            throw new NrcSubscriptionFailedException(
                    sprintf(
                'ZGW NRC: ZgwEndpoint "%s" has no resolvable client',
                (string) ($endpoint['id'] ?? '?')
            )
                    );
        }

        $nrcUrl = (string) ($endpoint['componenten']['nrc'] ?? '');
        if ($nrcUrl === '') {
            throw new NrcSubscriptionFailedException('ZGW NRC: endpoint missing nrc URL');
        }

        $bearer = $this->generateCallbackBearer();
        $body   = [
            'callbackUrl' => $callbackUrl,
            'auth'        => 'Bearer '.$bearer,
            'kanalen'     => $this->normaliseKanalen(kanalen: $kanalen),
        ];

        try {
            $response = $this->api->callComponent(
                componentUrl: $nrcUrl,
                method: 'POST',
                path: '/abonnement',
                client: $client,
                body: $body
            );
        } catch (Throwable $e) {
            throw new NrcSubscriptionFailedException(
                'ZGW NRC: POST /abonnement failed: '.$e->getMessage(),
                0,
                $e
            );
        }

        $abonnementUrl = (string) ($response['headers']['location'] ?? $response['body']['url'] ?? '');
        if ($abonnementUrl === '') {
            throw new NrcSubscriptionFailedException('ZGW NRC: registration returned no abonnement URL');
        }

        $endpointId = (string) ($endpoint['id'] ?? '');
        $record     = [
            'endpointId'    => $endpointId,
            'abonnementUrl' => $abonnementUrl,
            'callbackUrl'   => $callbackUrl,
            'callbackAuth'  => $bearer,
            'kanalen'       => $this->normaliseKanalen(kanalen: $kanalen),
            'actief'        => true,
        ];

        $saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $record);
        return $saved ?? $record;
    }//end registerAbonnement()

    /**
     * Reconcile a stored NrcAbonnement against a new desired kanalen list.
     *
     * @param array<string, mixed>            $endpoint   ZgwEndpoint payload.
     * @param array<string, mixed>            $abonnement Current NrcAbonnement record.
     * @param array<int, array<string,mixed>> $newKanalen Desired kanalen.
     *
     * @return array<string, mixed> Updated NrcAbonnement record.
     */
    public function syncAbonnement(array $endpoint, array $abonnement, array $newKanalen): array
    {
        $current = $this->normaliseKanalen(kanalen: ($abonnement['kanalen'] ?? []));
        $desired = $this->normaliseKanalen(kanalen: $newKanalen);
        if ($current === $desired) {
            return $abonnement;
        }

        try {
            $this->unregisterAbonnement(endpoint: $endpoint, abonnement: $abonnement);
        } catch (Throwable $e) {
            $this->logger->warning('ZGW NRC: syncAbonnement unregister failed', ['err' => $e->getMessage()]);
        }

        return $this->registerAbonnement(
            endpoint: $endpoint,
            kanalen: $desired,
            callbackUrl: (string) ($abonnement['callbackUrl'] ?? '')
        );
    }//end syncAbonnement()

    /**
     * Remove an abonnement at NRC and mark the local record inactive.
     *
     * @param array<string, mixed> $endpoint   ZgwEndpoint payload.
     * @param array<string, mixed> $abonnement Current NrcAbonnement record.
     *
     * @return void
     *
     * @throws NrcSubscriptionFailedException On HTTP failure.
     */
    public function unregisterAbonnement(array $endpoint, array $abonnement): void
    {
        $client = $this->registers->findClientForEndpoint($endpoint);
        if ($client === null) {
            throw new NrcSubscriptionFailedException('ZGW NRC: endpoint has no resolvable client');
        }

        $abonnementUrl = (string) ($abonnement['abonnementUrl'] ?? '');
        if ($abonnementUrl !== '') {
            try {
                $this->api->callComponent(
                    componentUrl: $abonnementUrl,
                    method: 'DELETE',
                    path: '',
                    client: $client
                );
            } catch (Throwable $e) {
                throw new NrcSubscriptionFailedException(
                    'ZGW NRC: DELETE failed: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $abonnement['actief'] = false;
        $uuid = (string) ($abonnement['@self']['uuid'] ?? $abonnement['id'] ?? '');
        if ($uuid !== '') {
            $saveUuid = $uuid;
        } else {
            $saveUuid = null;
        }

        $this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $abonnement, $saveUuid);
    }//end unregisterAbonnement()

    /**
     * Generate a fresh 256-bit callback bearer token.
     *
     * @return string Hex-encoded random token.
     */
    public function generateCallbackBearer(): string
    {
        return bin2hex(random_bytes(32));
    }//end generateCallbackBearer()

    /**
     * Normalise the kanalen list (drop empties, sort for deterministic compare).
     *
     * @param array<int, array<string, mixed>> $kanalen Raw list.
     *
     * @return array<int, array{naam:string,filters:array<string,mixed>}>
     */
    private function normaliseKanalen(array $kanalen): array
    {
        $out = [];
        foreach ($kanalen as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $naam = (string) ($entry['naam'] ?? '');
            if ($naam === '') {
                continue;
            }

            $filters = $entry['filters'] ?? [];
            if (is_array($filters) === false) {
                $filters = [];
            }

            ksort($filters);
            $out[] = ['naam' => $naam, 'filters' => $filters];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['naam'], $b['naam']));
        return $out;
    }//end normaliseKanalen()
}//end class
