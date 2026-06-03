<?php

/**
 * Pipelinq ZrcClient.
 *
 * Zaken API (ZRC) typed client: zaken, statussen, rollen and
 * zaakinformatieobjecten. Handles createZaak (with AC scope pre-flight and
 * ZgwResourceMapping persistence), ETag-guarded PATCH with optimistic-lock
 * surfacing, status append/read, and idempotent initiator-rol linking with
 * BSN/RSIN betrokkeneIdentificatie (REQ-ZGW-002, REQ-ZGW-006, REQ-ZGW-009,
 * REQ-ZGW-010).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Exception\OptimisticLockException;
use OCA\Pipelinq\Exception\ZgwBridgeException;

/**
 * Zaken API (ZRC) typed client.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.2
 */
class ZrcClient
{
    /**
     * Constructor.
     *
     * @param ZgwApiClient            $apiClient   The base ZGW HTTP client.
     * @param ZgwObjectRepository     $repository  The ZGW object persistence helper.
     * @param AcClient                $acClient    The autorisaties (AC) client for scope pre-flight.
     * @param ZgwCoexistenceValidator $coexistence The StUF/ZGW double-write guard.
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private ZgwObjectRepository $repository,
        private AcClient $acClient,
        private ZgwCoexistenceValidator $coexistence,
    ) {
    }//end __construct()

    /**
     * Extract the trailing UUID from a ZGW resource URL.
     *
     * @param string $url The resource URL.
     *
     * @return string The UUID (or '' when not present).
     */
    private function uuidFromUrl(string $url): string
    {
        $segments = explode('/', rtrim($url, '/'));

        // The explode() result is always a non-empty list, so the last segment
        // is a string; an empty input simply produces ''.
        return (string) end($segments);
    }//end uuidFromUrl()

    /**
     * Create a zaak and persist its ZgwResourceMapping.
     *
     * @param array<string, mixed> $endpoint   The ZgwEndpoint object array.
     * @param array<string, mixed> $client     The ZgwClient object array.
     * @param array<string, mixed> $zaakData   The POST /zaken body (must include zaaktype URL).
     * @param string               $pipelinqId The originating pipelinq Request UUID.
     *
     * @return array<string, mixed> The persisted ZgwResourceMapping object array.
     *
     * @throws \OCA\Pipelinq\Exception\DoubleWritePathException When both StUF and ZGW write paths are active.
     * @throws \OCA\Pipelinq\Exception\InsufficientScopeException When the client lacks zaken.aanmaken.
     * @throws ZgwBridgeException When the response carries no Location/url.
     */
    public function createZaak(array $endpoint, array $client, array $zaakData, string $pipelinqId): array
    {
        // Coexistence guard (REQ-ZGW-008): refuse to register a zaak when both a
        // StUF and a ZGW write path are active for this gemeente. Self-guarding
        // the single write path here keeps the check unconditional rather than
        // relying on a (not-yet-existing) request orchestrator to remember it.
        $this->coexistence->validateWritePath(
            gemeenteCode: (string) ($endpoint['gemeenteCode'] ?? '')
        );

        $zaaktypeUrl = (string) ($zaakData['zaaktype'] ?? '');
        // Pre-flight scope guard (REQ-ZGW-006): block before any HTTP call.
        $this->acClient->requireScope($endpoint, $client, $zaaktypeUrl, 'zaken.aanmaken');

        $zrcUrl   = (string) ($endpoint['componenten']['zrc'] ?? '');
        $response = $this->apiClient->callComponent($zrcUrl, 'POST', '/zaken', $client, $zaakData);

        $location = (string) ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? ($response['body']['url'] ?? '')));
        if ($location === '') {
            throw new ZgwBridgeException(message: 'ZRC createZaak gaf geen Location/url terug.');
        }

        return $this->repository->save(
            'zgwResourceMapping',
            [
                'pipelinqEntiteit'      => 'request',
                'pipelinqId'            => $pipelinqId,
                'zgwResourceType'       => 'zaak',
                'zgwUrl'                => $location,
                'zgwUuid'               => $this->uuidFromUrl(url: $location),
                'endpointId'            => (string) ($endpoint['id'] ?? ''),
                'laatsteSynchronisatie' => gmdate('c'),
                'etag'                  => (string) $response['etag'],
            ]
        );
    }//end createZaak()

    /**
     * GET a zaak and refresh the cached ETag on its mapping.
     *
     * @param array<string, mixed> $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed> $client   The ZgwClient object array.
     * @param array<string, mixed> $mapping  The ZgwResourceMapping object array.
     *
     * @return array<string, mixed> The zaak representation.
     */
    public function getZaak(array $endpoint, array $client, array $mapping): array
    {
        $zaakUrl  = (string) ($mapping['zgwUrl'] ?? '');
        $response = $this->apiClient->callComponent(
            (string) ($endpoint['componenten']['zrc'] ?? ''),
            'GET',
            $zaakUrl,
            $client
        );

        $this->persistEtag(mapping: $mapping, etag: (string) $response['etag']);

        return $response['body'];
    }//end getZaak()

    /**
     * PATCH a zaak using the cached ETag (If-Match); surface 412 as a lock conflict.
     *
     * @param array<string, mixed> $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed> $client   The ZgwClient object array.
     * @param array<string, mixed> $mapping  The ZgwResourceMapping object array.
     * @param array<string, mixed> $updates  The fields to PATCH.
     *
     * @return array<string, mixed> The updated ZgwResourceMapping object array.
     *
     * @throws OptimisticLockException On a 412 Precondition Failed (no auto-retry).
     */
    public function updateZaak(array $endpoint, array $client, array $mapping, array $updates): array
    {
        $zrcUrl  = (string) ($endpoint['componenten']['zrc'] ?? '');
        $zaakUrl = (string) ($mapping['zgwUrl'] ?? '');
        $etag    = (string) ($mapping['etag'] ?? '');

        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }

        $response = $this->apiClient->callComponent($zrcUrl, 'PATCH', $zaakUrl, $client, $updates, $headers);

        if ($response['status'] === 412) {
            // Fetch the fresh server representation for conflict reconciliation.
            $fresh = $this->apiClient->callComponent($zrcUrl, 'GET', $zaakUrl, $client);
            throw new OptimisticLockException(
                message: sprintf('Optimistic-lock conflict op zaak %s (ETag verouderd).', $zaakUrl),
                staleRepresentation: $updates,
                freshRepresentation: $fresh['body'],
                conflictingField: $this->firstConflictingField(updates: $updates, fresh: $fresh['body'])
            );
        }

        return $this->persistEtag(mapping: $mapping, etag: (string) $response['etag']);
    }//end updateZaak()

    /**
     * Append a status to a zaak.
     *
     * @param array<string, mixed> $endpoint   The ZgwEndpoint object array.
     * @param array<string, mixed> $client     The ZgwClient object array.
     * @param array<string, mixed> $statusData The POST /statussen body (zaak URL, statustype, datumStatusGezet).
     *
     * @return string The created status URL.
     *
     * @throws ZgwBridgeException When no status URL is returned.
     */
    public function addStatus(array $endpoint, array $client, array $statusData): string
    {
        $zrcUrl   = (string) ($endpoint['componenten']['zrc'] ?? '');
        $response = $this->apiClient->callComponent($zrcUrl, 'POST', '/statussen', $client, $statusData);

        $statusUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($statusUrl === '') {
            throw new ZgwBridgeException(message: 'ZRC addStatus gaf geen status-URL terug.');
        }

        return $statusUrl;
    }//end addStatus()

    /**
     * GET a status by URL.
     *
     * @param array<string, mixed> $endpoint  The ZgwEndpoint object array.
     * @param array<string, mixed> $client    The ZgwClient object array.
     * @param string               $statusUrl The status URL.
     *
     * @return array<string, mixed> The status representation.
     */
    public function getStatus(array $endpoint, array $client, string $statusUrl): array
    {
        $response = $this->apiClient->callComponent(
            (string) ($endpoint['componenten']['zrc'] ?? ''),
            'GET',
            $statusUrl,
            $client
        );

        return $response['body'];
    }//end getStatus()

    /**
     * Link a pipelinq Contact as an initiator rol on a zaak, idempotently.
     *
     * Queries existing rollen for the betrokkene first and returns the existing
     * rol URL on a hit; otherwise POSTs a new rol with inpBsn (natuurlijk
     * persoon) or innNnpId (niet-natuurlijk persoon) betrokkeneIdentificatie
     * (REQ-ZGW-010).
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param array<string, mixed> $zaakMapping The zaak ZgwResourceMapping object array.
     * @param array<string, mixed> $contact     The pipelinq Contact object array.
     * @param string               $roltypeUrl  The resolved roltype URL.
     *
     * @return string The rol URL (existing or newly created).
     *
     * @throws ZgwBridgeException When neither a BSN nor an RSIN/KvK identifier is present.
     */
    public function linkInitiator(
        array $endpoint,
        array $client,
        array $zaakMapping,
        array $contact,
        string $roltypeUrl
    ): string {
        $zrcUrl  = (string) ($endpoint['componenten']['zrc'] ?? '');
        $zaakUrl = (string) ($zaakMapping['zgwUrl'] ?? '');

        [$betrokkeneType, $identificatie] = $this->betrokkeneIdentificatie(contact: $contact);

        // Idempotency: look up an existing rol for this betrokkene first.
        $query       = http_build_query(['zaak' => $zaakUrl, 'betrokkeneType' => $betrokkeneType]);
        $existing    = $this->apiClient->callComponent($zrcUrl, 'GET', '/rollen?'.$query, $client);
        $existingUrl = $this->matchExistingRol(
            rollen: ($existing['body']['results'] ?? []),
            identificatie: $identificatie
        );
        if ($existingUrl !== null) {
            return $existingUrl;
        }

        $body = [
            'zaak'                    => $zaakUrl,
            'betrokkeneType'          => $betrokkeneType,
            'roltype'                 => $roltypeUrl,
            'roltoelichting'          => (string) ($contact['role'] ?? 'Aanvrager'),
            'betrokkeneIdentificatie' => $identificatie,
        ];

        $response = $this->apiClient->callComponent($zrcUrl, 'POST', '/rollen', $client, $body);
        $rolUrl   = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($rolUrl === '') {
            throw new ZgwBridgeException(message: 'ZRC linkInitiator gaf geen rol-URL terug.');
        }

        return $rolUrl;
    }//end linkInitiator()

    /**
     * Derive betrokkeneType + betrokkeneIdentificatie from a Contact.
     *
     * @param array<string, mixed> $contact The Contact object array.
     *
     * @return array{0:string, 1:array<string, string>} [betrokkeneType, identificatie].
     *
     * @throws ZgwBridgeException When no BSN/RSIN/KvK identifier is present.
     */
    private function betrokkeneIdentificatie(array $contact): array
    {
        $bsn = (string) ($contact['bsn'] ?? '');
        if ($bsn !== '') {
            return ['natuurlijk_persoon', ['inpBsn' => $bsn]];
        }

        $rsin = (string) ($contact['rsin'] ?? ($contact['kvkNummer'] ?? ($contact['kvk'] ?? '')));
        if ($rsin !== '') {
            return ['niet_natuurlijk_persoon', ['innNnpId' => $rsin]];
        }

        throw new ZgwBridgeException(
            message: 'Contact mist een BSN (natuurlijk persoon) of RSIN/KvK-nummer (organisatie) voor rol-koppeling.'
        );
    }//end betrokkeneIdentificatie()

    /**
     * Find an existing rol URL whose betrokkeneIdentificatie matches.
     *
     * @param mixed                 $rollen        The rollen "results" array.
     * @param array<string, string> $identificatie The target betrokkeneIdentificatie.
     *
     * @return string|null The matching rol URL, or null.
     */
    private function matchExistingRol(mixed $rollen, array $identificatie): ?string
    {
        if (is_array($rollen) === false) {
            return null;
        }

        $targetValue = (string) reset($identificatie);
        $firstKey    = array_key_first($identificatie);
        $targetKey   = (string) ($firstKey ?? '');

        foreach ($rollen as $rol) {
            $current = ($rol['betrokkeneIdentificatie'][$targetKey] ?? null);
            if ($current !== null && (string) $current === $targetValue) {
                return (string) ($rol['url'] ?? '');
            }
        }

        return null;
    }//end matchExistingRol()

    /**
     * Persist a refreshed ETag onto a ZgwResourceMapping.
     *
     * @param array<string, mixed> $mapping The ZgwResourceMapping object array.
     * @param string               $etag    The new ETag (may be empty).
     *
     * @return array<string, mixed> The updated mapping object array.
     */
    private function persistEtag(array $mapping, string $etag): array
    {
        $uuid = (string) ($mapping['@self']['uuid'] ?? ($mapping['id'] ?? ''));
        $data = $mapping;
        unset($data['@self']);
        $data['etag'] = $etag;
        $data['laatsteSynchronisatie'] = gmdate('c');

        if ($uuid === '') {
            return array_merge($mapping, ['etag' => $etag]);
        }

        return $this->repository->save('zgwResourceMapping', $data, $uuid);
    }//end persistEtag()

    /**
     * Best-effort identification of the first conflicting field for a 412.
     *
     * @param array<string, mixed> $updates The attempted updates.
     * @param array<string, mixed> $fresh   The fresh server representation.
     *
     * @return string The first field whose value differs, or '' when none.
     */
    private function firstConflictingField(array $updates, array $fresh): string
    {
        foreach ($updates as $field => $value) {
            if (($fresh[$field] ?? null) !== $value) {
                return (string) $field;
            }
        }

        return '';
    }//end firstConflictingField()
}//end class
