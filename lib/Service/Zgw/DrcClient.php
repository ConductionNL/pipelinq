<?php

/**
 * Pipelinq DrcClient.
 *
 * Typed client for the ZGW Documenten (DRC) component. Handles two upload
 * variants per REQ-ZGW-003:
 *
 *   1. Inline upload (default for files ≤ 4 MiB): POST a single
 *      enkelvoudiginformatieobject with `inhoud` set to the base64 of the
 *      file bytes. Returns the EIO URL + ETag.
 *
 *   2. Multipart upload (large files): POST without `inhoud`, capture
 *      `bestandsdelen[]` and the lock id, PUT each part, POST .../unlock.
 *
 * After successful creation the bridge links the EIO to the parent zaak by
 * POSTing a zaakinformatieobject to ZRC (handled by linkZaakinformatieobject).
 *
 * Required scope: `documenten.aanmaken` for the configured client; pre-flight
 * guard runs via `AcClient::require()` before any DRC HTTP call.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Throwable;

/**
 * Typed Documenten (DRC) client.
 */
class DrcClient
{
    public const SCOPE_AANMAKEN = 'documenten.aanmaken';

    /**
     * Default inline-upload threshold (bytes). Files at or below this size
     * are uploaded inline with the EIO POST; larger files use the
     * bestandsdelen[]/unlock multipart protocol.
     */
    private const DEFAULT_INLINE_THRESHOLD = 4194304;

    /**
     * Constructor.
     *
     * @param ZgwApiClient      $api       Base transport.
     * @param ZgwRegisterAccess $registers Register facade.
     * @param AcClient          $ac        Scope cache (pre-flight guards).
     * @param IAppConfig        $appConfig App config (threshold tuning).
     */
    public function __construct(
        private ZgwApiClient $api,
        private ZgwRegisterAccess $registers,
        private AcClient $ac,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Create an enkelvoudiginformatieobject (EIO).
     *
     * For small documents (≤ inline threshold) the body content is sent
     * inline as base64. For larger documents the call returns a `lock` and
     * a `bestandsdelen[]` plan; the caller MUST drive `uploadBestandsdelen()`
     * before the EIO is durable.
     *
     * @param array<string, mixed> $endpoint ZgwEndpoint payload.
     * @param array<string, mixed> $document Pipelinq Document payload (must include
     *                                       'pipelinqId', 'bytes' OR 'inhoud',
     *                                       'bestandsnaam', 'formaat').
     * @param array<string, mixed> $metadata Extra DRC metadata (auteur, beschrijving,
     *                                       informatieobjecttype, etc).
     *
     * @return array<string, mixed> Saved ZgwResourceMapping (with zgwUrl, zgwUuid, etag,
     *                              and a 'plan' key when multipart is required).
     *
     * @throws InsufficientScopeException When the configured client lacks documenten.aanmaken.
     */
    public function createEnkelvoudigInformatieobject(
        array $endpoint,
        array $document,
        array $metadata,
    ): array {
        $client = $this->requireClient(endpoint: $endpoint);
        $drcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'drc');

        // AC scope is component-level for DRC (not zaaktype-specific); the wildcard
        // bucket carries it.
        $this->ac->require($endpoint, '*', self::SCOPE_AANMAKEN);

        $bytes = (string) ($document['bytes'] ?? '');
        if (isset($document['bestandsomvang']) === true) {
            $size = (int) $document['bestandsomvang'];
        } else {
            $size = strlen($bytes);
        }

        $threshold = $this->inlineThreshold();
        $useInline = ($bytes !== '' && $size <= $threshold);

        if ($useInline === true) {
            $inhoud = ['inhoud' => base64_encode($bytes)];
        } else {
            $inhoud = ['inhoud' => null];
        }

        $body = array_merge(
            [
                'bronorganisatie'      => (string) ($metadata['bronorganisatie'] ?? ($endpoint['gemeenteCode'] ?? '')),
                'creatiedatum'         => (string) ($metadata['creatiedatum'] ?? self::today()),
                'titel'                => (string) ($document['titel'] ?? $document['bestandsnaam'] ?? 'Document'),
                'auteur'               => (string) ($metadata['auteur'] ?? 'pipelinq'),
                'taal'                 => (string) ($metadata['taal'] ?? 'nld'),
                'informatieobjecttype' => (string) ($metadata['informatieobjecttype'] ?? ''),
                'bestandsnaam'         => (string) ($document['bestandsnaam'] ?? 'document.bin'),
                'bestandsomvang'       => $size,
                'formaat'              => (string) ($document['formaat'] ?? 'application/octet-stream'),
            ],
            $inhoud
        );

        $response = $this->api->callComponent(
            componentUrl: $drcUrl,
            method: 'POST',
            path: '/enkelvoudiginformatieobjecten',
            client: $client,
            body: $body
        );

        $url  = (string) ($response['headers']['location'] ?? $response['body']['url'] ?? '');
        $etag = (string) ($response['headers']['etag'] ?? '');
        $uuid = self::extractUuid(url: $url);

        $mapping = [
            'pipelinqEntiteit'      => 'document',
            'pipelinqId'            => (string) ($document['pipelinqId'] ?? $uuid),
            'zgwResourceType'       => 'informatieobject',
            'zgwUrl'                => $url,
            'zgwUuid'               => $uuid,
            'endpointId'            => (string) ($endpoint['id'] ?? ''),
            'laatsteSynchronisatie' => self::nowIso(),
            'etag'                  => $etag,
        ];

        if ($useInline === false) {
            // Caller MUST follow up with uploadBestandsdelen(); attach the plan.
            $mapping['plan'] = [
                'lock'          => (string) ($response['body']['lock'] ?? ''),
                'bestandsdelen' => $response['body']['bestandsdelen'] ?? [],
            ];
        }

        $saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_MAPPING, $mapping);
        if ($saved !== null) {
            return array_merge($saved, $mapping);
        }

        return $mapping;
    }//end createEnkelvoudigInformatieobject()

    /**
     * Drive the bestandsdelen[]/unlock protocol for a large-file EIO.
     *
     * For each entry in `$plan['bestandsdelen']` issue a PUT to the part URL
     * with the corresponding slice of `$document['bytes']`. After every part
     * succeeds POST `.../unlock` with the lock id captured during creation.
     *
     * @param array<string, mixed> $endpoint   ZgwEndpoint payload.
     * @param array<string, mixed> $eioMapping Mapping returned by createEnkelvoudigInformatieobject
     *                                         (must contain `plan.lock`, `plan.bestandsdelen`).
     * @param array<string, mixed> $document   Document payload (with `bytes`).
     *
     * @return void
     *
     * @throws ZgwException On unrecoverable failure.
     */
    public function uploadBestandsdelen(array $endpoint, array $eioMapping, array $document): void
    {
        $plan  = $eioMapping['plan'] ?? [];
        $parts = $plan['bestandsdelen'] ?? [];
        $lock  = (string) ($plan['lock'] ?? '');
        if (is_array($parts) === false || $parts === [] || $lock === '') {
            throw new ZgwException('ZGW DRC: uploadBestandsdelen called without a multipart plan');
        }

        $bytes  = (string) ($document['bytes'] ?? '');
        $client = $this->requireClient(endpoint: $endpoint);

        foreach ($parts as $part) {
            if (is_array($part) === false) {
                continue;
            }

            $partUrl = (string) ($part['url'] ?? '');
            $start   = (int) ($part['begin'] ?? 0);
            $end     = (int) ($part['einde'] ?? strlen($bytes));
            $slice   = substr($bytes, $start, ($end - $start));

            $this->api->callComponent(
                componentUrl: $partUrl,
                method: 'PUT',
                path: '',
                client: $client,
                body: ['inhoud' => base64_encode($slice)]
            );
        }

        $unlockUrl = (string) ($eioMapping['zgwUrl'] ?? '');
        if ($unlockUrl === '') {
            return;
        }

        $this->api->callComponent(
            componentUrl: $unlockUrl,
            method: 'POST',
            path: '/unlock',
            client: $client,
            body: ['lock' => $lock]
        );
    }//end uploadBestandsdelen()

    /**
     * Link an EIO to a zaak via POST /zaakinformatieobjecten on the ZRC.
     *
     * @param array<string, mixed> $endpoint    ZgwEndpoint payload.
     * @param array<string, mixed> $zaakMapping Zaak mapping.
     * @param array<string, mixed> $eioMapping  EIO mapping.
     *
     * @return string URL of the created zaakinformatieobject.
     */
    public function linkZaakinformatieobject(
        array $endpoint,
        array $zaakMapping,
        array $eioMapping,
    ): string {
        $client = $this->requireClient(endpoint: $endpoint);
        $zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');

        $response = $this->api->callComponent(
            componentUrl: $zrcUrl,
            method: 'POST',
            path: '/zaakinformatieobjecten',
            client: $client,
            body: [
                'zaak'             => (string) ($zaakMapping['zgwUrl'] ?? ''),
                'informatieobject' => (string) ($eioMapping['zgwUrl'] ?? ''),
            ]
        );

        return (string) ($response['headers']['location'] ?? $response['body']['url'] ?? '');
    }//end linkZaakinformatieobject()

    /**
     * Effective inline-upload threshold (bytes).
     *
     * @return int
     */
    public function inlineThreshold(): int
    {
        $value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.drc_inline_threshold', self::DEFAULT_INLINE_THRESHOLD);
        if ($value > 0) {
            return $value;
        }

        return self::DEFAULT_INLINE_THRESHOLD;
    }//end inlineThreshold()

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
        if ($path === false || $path === null || $path === '') {
            $path = '';
        }

        $tail = basename($path);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tail) === 1) {
            return $tail;
        }

        return '';
    }//end extractUuid()

    /**
     * Today's ISO date (UTC).
     *
     * @return string
     */
    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }//end today()

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
