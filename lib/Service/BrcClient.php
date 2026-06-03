<?php

/**
 * Pipelinq BrcClient.
 *
 * Besluiten API (BRC) typed client. Creates besluiten (referencing a zaak and a
 * catalogus besluittype) and links a decision document via a
 * besluitinformatieobject. Guards creation with a besluiten.aanmaken AC scope
 * pre-flight (REQ-ZGW-004, REQ-ZGW-006).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Exception\ZgwBridgeException;

/**
 * Besluiten API (BRC) typed client.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.4
 */
class BrcClient
{
    /**
     * Constructor.
     *
     * @param ZgwApiClient        $apiClient  The base ZGW HTTP client.
     * @param ZgwObjectRepository $repository The ZGW object persistence helper.
     * @param AcClient            $acClient   The autorisaties (AC) client for scope pre-flight.
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private ZgwObjectRepository $repository,
        private AcClient $acClient,
    ) {
    }//end __construct()

    /**
     * Extract the trailing UUID from a ZGW resource URL.
     *
     * @param string $url The resource URL.
     *
     * @return string The UUID.
     */
    private function uuidFromUrl(string $url): string
    {
        $segments = explode('/', rtrim($url, '/'));

        // The explode() result is always a non-empty list, so the last segment
        // is a string; an empty input simply produces ''.
        return (string) end($segments);
    }//end uuidFromUrl()

    /**
     * Create a besluit linked to a zaak and persist its mapping.
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param array<string, mixed> $zaakMapping The zaak ZgwResourceMapping object array.
     * @param array<string, mixed> $besluitData The besluit body (must include besluittype URL, datum, ingangsdatum).
     *
     * @return array<string, mixed> The persisted ZgwResourceMapping object array.
     *
     * @throws \OCA\Pipelinq\Exception\InsufficientScopeException When besluiten.aanmaken is not granted.
     * @throws ZgwBridgeException When the besluit response carries no url.
     */
    public function createBesluit(
        array $endpoint,
        array $client,
        array $zaakMapping,
        array $besluitData
    ): array {
        $besluittypeUrl = (string) ($besluitData['besluittype'] ?? '');
        // Pre-flight scope guard (REQ-ZGW-006).
        $this->acClient->requireScope($endpoint, $client, $besluittypeUrl, 'besluiten.aanmaken');

        $brcUrl = (string) ($endpoint['componenten']['brc'] ?? '');
        $body   = array_merge($besluitData, ['zaak' => (string) ($zaakMapping['zgwUrl'] ?? '')]);

        $response = $this->apiClient->callComponent($brcUrl, 'POST', '/besluiten', $client, $body);

        $besluitUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($besluitUrl === '') {
            throw new ZgwBridgeException(message: 'BRC createBesluit gaf geen url terug.');
        }

        return $this->repository->save(
            'zgwResourceMapping',
            [
                'pipelinqEntiteit'      => 'request',
                'pipelinqId'            => (string) ($zaakMapping['pipelinqId'] ?? ''),
                'zgwResourceType'       => 'besluit',
                'zgwUrl'                => $besluitUrl,
                'zgwUuid'               => $this->uuidFromUrl(url: $besluitUrl),
                'endpointId'            => (string) ($endpoint['id'] ?? ''),
                'laatsteSynchronisatie' => gmdate('c'),
                'etag'                  => (string) $response['etag'],
            ]
        );
    }//end createBesluit()

    /**
     * Link an EIO to a besluit via a besluitinformatieobject.
     *
     * @param array<string, mixed> $endpoint       The ZgwEndpoint object array.
     * @param array<string, mixed> $client         The ZgwClient object array.
     * @param array<string, mixed> $besluitMapping The besluit ZgwResourceMapping object array.
     * @param array<string, mixed> $eioMapping     The EIO ZgwResourceMapping object array.
     *
     * @return string The besluitinformatieobject link URL.
     *
     * @throws ZgwBridgeException When no link URL is returned.
     */
    public function linkBesluitInformatieobject(
        array $endpoint,
        array $client,
        array $besluitMapping,
        array $eioMapping
    ): string {
        $brcUrl   = (string) ($endpoint['componenten']['brc'] ?? '');
        $response = $this->apiClient->callComponent(
            $brcUrl,
            'POST',
            '/besluitinformatieobjecten',
            $client,
            [
                'besluit'          => (string) ($besluitMapping['zgwUrl'] ?? ''),
                'informatieobject' => (string) ($eioMapping['zgwUrl'] ?? ''),
            ]
        );

        $linkUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($linkUrl === '') {
            throw new ZgwBridgeException(message: 'BRC linkBesluitInformatieobject gaf geen url terug.');
        }

        return $linkUrl;
    }//end linkBesluitInformatieobject()
}//end class
