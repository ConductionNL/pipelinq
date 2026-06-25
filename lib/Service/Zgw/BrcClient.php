<?php

/**
 * Pipelinq BrcClient.
 *
 * Typed client for the ZGW Besluiten (BRC) component. Implements
 * createBesluit() (POST /besluiten, persist a ZgwResourceMapping with
 * `zgwResourceType="besluit"`) and linkBesluitInformatieobject() (POST
 * /besluitinformatieobjecten to bind the formal decision document to the
 * besluit).
 *
 * Required scope: `besluiten.aanmaken` on the besluittype; pre-flight
 * guard runs via `AcClient::require()` before the underlying HTTP call.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Log\LoggerInterface;

/**
 * Typed Besluiten (BRC) client.
 */
class BrcClient
{
    public const SCOPE_AANMAKEN = 'besluiten.aanmaken';

    /**
     * Constructor.
     *
     * @param ZgwApiClient      $api       Base transport.
     * @param ZgwRegisterAccess $registers Register facade.
     * @param AcClient          $ac        Scope cache (pre-flight guards).
     * @param LoggerInterface   $logger    PSR-3 logger.
     */
    public function __construct(
        private ZgwApiClient $api,
        private ZgwRegisterAccess $registers,
        private AcClient $ac,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a besluit (POST /besluiten) and persist a ZgwResourceMapping.
     *
     * @param array<string, mixed> $endpoint    ZgwEndpoint payload.
     * @param array<string, mixed> $zaakMapping Mapping for the parent zaak.
     * @param array<string, mixed> $besluitData Body for POST /besluiten —
     *                                          MUST include `besluittype`
     *                                          (URL), `datum`, `ingangsdatum`,
     *                                          `verantwoordelijkeOrganisatie`.
     *
     * @return array<string, mixed> Saved ZgwResourceMapping (with zgwUrl, zgwUuid, etag).
     *
     * @throws InsufficientScopeException When the configured client lacks besluiten.aanmaken.
     */
    public function createBesluit(array $endpoint, array $zaakMapping, array $besluitData): array
    {
        $client = $this->requireClient(endpoint: $endpoint);
        $brcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'brc');

        $besluittypeUrl = (string) ($besluitData['besluittype'] ?? '');
        if ($besluittypeUrl !== '') {
            $this->ac->require($endpoint, $besluittypeUrl, self::SCOPE_AANMAKEN);
        }

        $body = array_merge(
            [
                'zaak'                         => (string) ($zaakMapping['zgwUrl'] ?? ''),
                'verantwoordelijkeOrganisatie' => (string) ($endpoint['gemeenteCode'] ?? ''),
            ],
            $besluitData
        );

        $response = $this->api->callComponent(
            componentUrl: $brcUrl,
            method: 'POST',
            path: '/besluiten',
            client: $client,
            body: $body
        );

        $url  = (string) ($response['headers']['location'] ?? $response['body']['url'] ?? '');
        $etag = (string) ($response['headers']['etag'] ?? '');
        $uuid = self::extractUuid(url: $url);

        $mapping = [
            'pipelinqEntiteit'      => 'request',
            'pipelinqId'            => (string) ($zaakMapping['pipelinqId'] ?? ''),
            'zgwResourceType'       => 'besluit',
            'zgwUrl'                => $url,
            'zgwUuid'               => $uuid,
            'endpointId'            => (string) ($endpoint['id'] ?? ''),
            'laatsteSynchronisatie' => self::nowIso(),
            'etag'                  => $etag,
        ];

        $saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_MAPPING, $mapping);
        return $saved ?? $mapping;
    }//end createBesluit()

    /**
     * Link a besluit to an informatieobject (POST /besluitinformatieobjecten).
     *
     * @param array<string, mixed> $endpoint       ZgwEndpoint payload.
     * @param array<string, mixed> $besluitMapping Mapping for the besluit.
     * @param array<string, mixed> $eioMapping     Mapping for the EIO.
     *
     * @return string URL of the created link.
     */
    public function linkBesluitInformatieobject(
        array $endpoint,
        array $besluitMapping,
        array $eioMapping,
    ): string {
        $client = $this->requireClient(endpoint: $endpoint);
        $brcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'brc');

        $response = $this->api->callComponent(
            componentUrl: $brcUrl,
            method: 'POST',
            path: '/besluitinformatieobjecten',
            client: $client,
            body: [
                'besluit'          => (string) ($besluitMapping['zgwUrl'] ?? ''),
                'informatieobject' => (string) ($eioMapping['zgwUrl'] ?? ''),
            ]
        );

        return (string) ($response['headers']['location'] ?? $response['body']['url'] ?? '');
    }//end linkBesluitInformatieobject()

    /**
     * Resolve and return the ZgwClient for an endpoint, raising on miss.
     *
     * @param array<string, mixed> $endpoint Endpoint payload.
     *
     * @return array<string, mixed>
     *
     * @throws ZgwException When the endpoint's clientId cannot be resolved.
     */
    private function requireClient(array $endpoint): array
    {
        $client = $this->registers->findClientForEndpoint($endpoint);
        if ($client === null) {
            throw new ZgwException(
                    sprintf(
                'ZGW: ZgwEndpoint "%s" references unknown clientId "%s"',
                (string) ($endpoint['id'] ?? '?'),
                (string) ($endpoint['clientId'] ?? '?')
            )
                    );
        }

        return $client;
    }//end requireClient()

    /**
     * Return the URL for a named component, raising on miss.
     *
     * @param array<string, mixed> $endpoint Endpoint payload.
     * @param string               $key      Component key (zrc/drc/brc/ztc/ac/nrc).
     *
     * @return string
     *
     * @throws ZgwException When the endpoint has no URL configured for the component.
     */
    private function requireComponentUrl(array $endpoint, string $key): string
    {
        $url = (string) ($endpoint['componenten'][$key] ?? '');
        if ($url === '') {
            throw new ZgwException(sprintf('ZGW: endpoint missing "%s" component URL', $key));
        }

        return $url;
    }//end requireComponentUrl()

    /**
     * Extract the trailing UUID from a ZGW URL.
     *
     * @param string $url Full URL.
     *
     * @return string UUID or empty string when not present.
     */
    private static function extractUuid(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '';
        }

        $tail = basename($path);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tail) === 1) {
            return $tail;
        }

        return '';
    }//end extractUuid()

    /**
     * Current ISO 8601 timestamp (UTC).
     *
     * @return string
     */
    private static function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }//end nowIso()
}//end class
