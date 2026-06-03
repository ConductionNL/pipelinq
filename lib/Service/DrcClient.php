<?php

/**
 * Pipelinq DrcClient.
 *
 * Documenten API (DRC) typed client. Creates enkelvoudiginformatieobjecten
 * (EIO) with inline base64 inhoud for small files (default ≤4 MiB) or the DRC
 * large-file multipart protocol (create → bestandsdelen[] PUT → unlock) for
 * larger ones, then links the EIO to a zaak via a zaakinformatieobject
 * (REQ-ZGW-003). A component-level documenten.aanmaken scope pre-flight guards
 * creation (REQ-ZGW-006).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\ZgwBridgeException;
use OCP\IAppConfig;

/**
 * Documenten API (DRC) typed client.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.3
 */
class DrcClient
{
    /**
     * Default inline upload threshold in bytes (4 MiB).
     *
     * @var int
     */
    private const DEFAULT_INLINE_THRESHOLD = 4194304;

    /**
     * Constructor.
     *
     * @param ZgwApiClient        $apiClient  The base ZGW HTTP client.
     * @param ZgwObjectRepository $repository The ZGW object persistence helper.
     * @param AcClient            $acClient   The autorisaties (AC) client for scope pre-flight.
     * @param IAppConfig          $appConfig  The app config (inline-threshold tuning).
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private ZgwObjectRepository $repository,
        private AcClient $acClient,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get the configured inline-upload threshold in bytes.
     *
     * @return int The threshold.
     */
    private function inlineThreshold(): int
    {
        $threshold = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'zgw.drc_inline_threshold',
            (string) self::DEFAULT_INLINE_THRESHOLD
        );

        if ($threshold <= 0) {
            return self::DEFAULT_INLINE_THRESHOLD;
        }

        return $threshold;
    }//end inlineThreshold()

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
     * Create an enkelvoudiginformatieobject (inline or multipart) and persist its mapping.
     *
     * @param array<string, mixed> $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed> $client   The ZgwClient object array.
     * @param array<string, mixed> $document The pipelinq Document object array (id, bytes, bestandsomvang).
     * @param array<string, mixed> $metadata The EIO metadata (bronorganisatie, informatieobjecttype, titel, ...).
     *
     * @return array<string, mixed> The persisted ZgwResourceMapping object array.
     *
     * @throws \OCA\Pipelinq\Exception\InsufficientScopeException When documenten.aanmaken is not granted.
     * @throws ZgwBridgeException When the EIO response carries no url.
     */
    public function createEnkelvoudigInformatieobject(
        array $endpoint,
        array $client,
        array $document,
        array $metadata
    ): array {
        // Component-level scope pre-flight (REQ-ZGW-006).
        $this->acClient->requireScope($endpoint, $client, '*', AcClient::SCOPE_DOCUMENTEN_AANMAKEN);

        $drcUrl = (string) ($endpoint['componenten']['drc'] ?? '');
        $size   = (int) ($document['bestandsomvang'] ?? strlen((string) ($document['bytes'] ?? '')));
        $body   = $metadata;
        $body['bestandsomvang'] = $size;

        $inline = ($size <= $this->inlineThreshold());
        if ($inline === true) {
            $body['inhoud'] = base64_encode((string) ($document['bytes'] ?? ''));
        }

        $response = $this->apiClient->callComponent(
            $drcUrl,
            'POST',
            '/enkelvoudiginformatieobjecten',
            $client,
            $body
        );

        $eioUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($eioUrl === '') {
            throw new ZgwBridgeException(message: 'DRC createEnkelvoudigInformatieobject gaf geen url terug.');
        }

        $mapping = $this->repository->save(
            'zgwResourceMapping',
            [
                'pipelinqEntiteit'      => 'document',
                'pipelinqId'            => (string) ($document['id'] ?? ''),
                'zgwResourceType'       => 'informatieobject',
                'zgwUrl'                => $eioUrl,
                'zgwUuid'               => $this->uuidFromUrl(url: $eioUrl),
                'endpointId'            => (string) ($endpoint['id'] ?? ''),
                'laatsteSynchronisatie' => gmdate('c'),
                'etag'                  => (string) $response['etag'],
            ]
        );

        if ($inline === false) {
            $this->uploadBestandsdelen(
                endpoint: $endpoint,
                client: $client,
                eioMapping: $mapping,
                document: $document,
                bestandsdelen: ($response['body']['bestandsdelen'] ?? []),
                lock: (string) ($response['body']['lock'] ?? '')
            );
        }

        return $mapping;
    }//end createEnkelvoudigInformatieobject()

    /**
     * Upload a large file via the DRC bestandsdelen protocol then unlock.
     *
     * @param array<string, mixed> $endpoint      The ZgwEndpoint object array.
     * @param array<string, mixed> $client        The ZgwClient object array.
     * @param array<string, mixed> $eioMapping    The EIO ZgwResourceMapping object array.
     * @param array<string, mixed> $document      The pipelinq Document object array (bytes).
     * @param mixed                $bestandsdelen The bestandsdelen[] list from the create response.
     * @param string               $lock          The lock id returned by creation.
     *
     * @return void
     *
     * @throws ZgwBridgeException When a part is missing its URL/omvang.
     */
    public function uploadBestandsdelen(
        array $endpoint,
        array $client,
        array $eioMapping,
        array $document,
        mixed $bestandsdelen,
        string $lock
    ): void {
        $drcUrl = (string) ($endpoint['componenten']['drc'] ?? '');
        $bytes  = (string) ($document['bytes'] ?? '');
        $offset = 0;

        if (is_array($bestandsdelen) === false) {
            $bestandsdelen = [];
        }

        foreach ($bestandsdelen as $deel) {
            $partUrl = (string) ($deel['url'] ?? '');
            $omvang  = (int) ($deel['omvang'] ?? 0);
            if ($partUrl === '' || $omvang <= 0) {
                throw new ZgwBridgeException(message: 'DRC bestandsdeel mist url of omvang.');
            }

            $chunk   = substr($bytes, $offset, $omvang);
            $offset += $omvang;

            $this->apiClient->callComponent(
                $drcUrl,
                'PUT',
                $partUrl,
                $client,
                ['inhoud' => base64_encode($chunk), 'lock' => $lock]
            );
        }//end foreach

        // Unlock to finalise the EIO.
        $eioUrl = (string) ($eioMapping['zgwUrl'] ?? '');
        $this->apiClient->callComponent($drcUrl, 'POST', rtrim($eioUrl, '/').'/unlock', $client, ['lock' => $lock]);
    }//end uploadBestandsdelen()

    /**
     * Link an EIO to a zaak via a zaakinformatieobject (posted to ZRC).
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param array<string, mixed> $zaakMapping The zaak ZgwResourceMapping object array.
     * @param array<string, mixed> $eioMapping  The EIO ZgwResourceMapping object array.
     *
     * @return string The zaakinformatieobject link URL.
     *
     * @throws ZgwBridgeException When no link URL is returned.
     */
    public function linkZaakinformatieobject(
        array $endpoint,
        array $client,
        array $zaakMapping,
        array $eioMapping
    ): string {
        $zrcUrl   = (string) ($endpoint['componenten']['zrc'] ?? '');
        $response = $this->apiClient->callComponent(
            $zrcUrl,
            'POST',
            '/zaakinformatieobjecten',
            $client,
            [
                'zaak'             => (string) ($zaakMapping['zgwUrl'] ?? ''),
                'informatieobject' => (string) ($eioMapping['zgwUrl'] ?? ''),
            ]
        );

        $linkUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));
        if ($linkUrl === '') {
            throw new ZgwBridgeException(message: 'ZRC linkZaakinformatieobject gaf geen url terug.');
        }

        return $linkUrl;
    }//end linkZaakinformatieobject()
}//end class
