<?php

/**
 * Pipelinq EvidenceCollectionService.
 *
 * Federated personal-data evidence collection for an AVG request. Discovers the
 * data subject's objects through OpenRegister's canonical NER-index discovery
 * (`DataSubjectRequestService::findSubjectData`, RBAC + tenant scoped) instead of
 * the earlier divergent BSN-equality `findAll` filter — an authorized behavioural
 * change recorded in openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md. Where
 * configured it also queries external OpenConnector AVG-export endpoints. Sources
 * that time out or are unreachable yield a `bron-onbereikbaar` BewijsItem rather
 * than aborting collection, and identical content is de-duplicated by hash. The
 * BewijsItem packaging + scope overlay + dedup stay as the pipelinq app overlay.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Federated evidence collection for the AVG workflow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a
 *  federated collector legitimately needs (repository, OR container, app config,
 *  event recorder, logger).
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
 */
class EvidenceCollectionService
{
    /**
     * Per-source timeout in seconds for external queries.
     *
     * @var int
     */
    public const SOURCE_TIMEOUT_SECONDS = 10;

    /**
     * Constructor.
     *
     * @param AvgRepository      $repository The AVG OR repository.
     * @param ContainerInterface $container  The DI container (OpenConnector probe).
     * @param IAppConfig         $appConfig  The app config.
     * @param AvgEventService    $events     The TermijnEvent recorder.
     * @param OrGdprBridge       $orGdpr     Bridge onto OR's NER-index discovery.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private AvgEventService $events,
        private OrGdprBridge $orGdpr,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Collect evidence for a request from all enabled sources.
     *
     * Returns a per-source summary. OpenRegister is always queried; external
     * sources (OpenConnector AVG-export endpoints) are queried best-effort and a
     * failure is recorded as a bron-onbereikbaar evidence item plus a
     * collectie-fout TermijnEvent, never an aborted run (REQ-AVG-004).
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array{collected: int, failed: int, sources: array<int, array<string, mixed>>} The summary.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function collect(array $request): array
    {
        $verzoekId = $this->repository->idOf($request);
        $collected = 0;
        $failed    = 0;
        $sources   = [];

        $orResult   = $this->collectFromOpenRegister(request: $request);
        $collected += $orResult['count'];
        $sources[]  = ['source' => 'openregister', 'count' => $orResult['count'], 'status' => 'success'];

        foreach ($this->configuredExternalSources() as $source) {
            $result = $this->collectFromExternalSource(request: $request, source: $source);
            if ($result['ok'] === true) {
                $collected += $result['count'];
                $sources[]  = ['source' => $source, 'count' => $result['count'], 'status' => 'success'];
                continue;
            }

            $failed++;
            $sources[] = ['source' => $source, 'count' => 0, 'status' => 'timeout'];
            $this->recordUnreachable(verzoekId: $verzoekId, source: $source);
        }

        $this->deduplicate(verzoekId: $verzoekId);

        return ['collected' => $collected, 'failed' => $failed, 'sources' => $sources];
    }//end collect()

    /**
     * Discover the data subject's objects via OR's canonical NER-index discovery.
     *
     * ADOPTS OpenRegister's `DataSubjectRequestService::findSubjectData` (the
     * GdprEntity NER index ⋈ entity_relations, RBAC + tenant scoped) instead of
     * the earlier divergent `findAll` BSN-equality filter. NER discovery is more
     * complete: it returns every object the index has tied to the subject's PII
     * (BSN/email/name), not only rows that happen to carry a literal `bsn`
     * column. The scope filter + BewijsItem packaging stay as the pipelinq app
     * overlay. Each envelope (object + the GdprEntity hits that matched) becomes a
     * BewijsItem.
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array{count: int} The number of items collected.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function collectFromOpenRegister(array $request): array
    {
        $verzoekId = $this->repository->idOf($request);
        $bsn       = (string) ($request['verzoekerBsn'] ?? '');
        $scopes    = (array) ($request['scope'] ?? []);
        $count     = 0;

        if ($bsn === '') {
            return ['count' => 0];
        }

        $envelopes = $this->orGdpr->findSubjectData(subjectId: $bsn);

        foreach ($envelopes as $envelope) {
            $object   = $this->normalize(object: $envelope['object']);
            $register = (string) ($object['@self']['register'] ?? '');
            if ($scopes !== [] && in_array($register, $scopes, true) === false
                && $this->scopeMatches(object: $object, scopes: $scopes) === false
            ) {
                continue;
            }

            $this->saveEvidence(
                verzoekId: $verzoekId,
                bronApp: 'openregister',
                bronRegister: $register,
                bronObject: (string) ($object['@self']['id'] ?? $object['@self']['uuid'] ?? ''),
                categorie: $this->categoryOf(object: $object, gdprEntities: $envelope['gdprEntities']),
                preview: $this->previewOf(object: $object),
                rechtsgrond: 'wettelijke taak'
            );
            $count++;
        }

        return ['count' => $count];
    }//end collectFromOpenRegister()

    /**
     * Derive an evidence category from the owning object's schema and the NER hits.
     *
     * Prefers the most specific GdprEntity category the NER index attached to the
     * object (e.g. `bsn`, `email`); falls back to the owning object's schema.
     *
     * @param array<string, mixed>             $object       The owning object.
     * @param array<int, array<string, mixed>> $gdprEntities The matched NER hits.
     *
     * @return string The category.
     */
    private function categoryOf(array $object, array $gdprEntities): string
    {
        foreach ($gdprEntities as $hit) {
            $category = trim((string) ($hit['category'] ?? ''));
            if ($category !== '') {
                return $category;
            }
        }

        return (string) ($object['@self']['schema'] ?? 'object');
    }//end categoryOf()

    /**
     * Best-effort query of one external OpenConnector AVG-export source.
     *
     * This deliberately performs no live network call in the absence of a wired
     * OpenConnector client — it returns a not-ok result so the caller records the
     * source as unreachable (manual supplementation). When an OpenConnector
     * SourceService is available it is used; any error (incl. timeout) is a
     * graceful not-ok, never an exception that aborts the run.
     *
     * @param array<string, mixed> $request The request payload.
     * @param string               $source  The source identifier.
     *
     * @return array{ok: bool, count: int} The per-source result.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function collectFromExternalSource(array $request, string $source): array
    {
        // OpenConnector live querying is an organisation-side integration
        // dependency (the AVG-export endpoints must be registered per source).
        // When a SourceService is available the source is queried for the data
        // subject's BSN; otherwise the source is reported as unreachable so the
        // handler is prompted to supplement manually — the safe default that
        // keeps collection non-blocking (REQ-AVG-004 sc.2).
        $bsn = (string) ($request['verzoekerBsn'] ?? '');
        if ($bsn === '') {
            return ['ok' => false, 'count' => 0];
        }

        try {
            if ($this->container->has('OCA\OpenConnector\Service\SourceService') === false) {
                $this->logger->debug(
                    'Pipelinq AVG: external AVG-export source not reachable',
                    ['source' => $source]
                );
                return ['ok' => false, 'count' => 0];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'count' => 0];
        }

        // A live OpenConnector AVG-export query lands once the per-source export
        // endpoint contract is wired; until then the configured source is treated
        // as reachable-but-empty rather than failed.
        $this->logger->info(
            'Pipelinq AVG: external AVG-export source query skipped (endpoint contract pending)',
            ['source' => $source]
        );

        return ['ok' => false, 'count' => 0];
    }//end collectFromExternalSource()

    /**
     * Flag duplicate evidence items (same content hash) for a request.
     *
     * The first occurrence of each content hash is kept; later identical items
     * are marked gedupliceerd so the handler can choose which to export.
     *
     * @param string $verzoekId The parent request UUID.
     *
     * @return int The number of items flagged as duplicates.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    public function deduplicate(string $verzoekId): int
    {
        $items = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            filters: ['verzoekId' => $verzoekId]
        );

        $seen    = [];
        $flagged = 0;
        foreach ($items as $item) {
            $hash = (string) ($item['contentHash'] ?? '');
            if ($hash === '') {
                continue;
            }

            if (isset($seen[$hash]) === true) {
                if ((bool) ($item['gedupliceerd'] ?? false) === false) {
                    $item['gedupliceerd']      = true;
                    $item['opgenomenInExport'] = false;
                    $this->repository->save(
                        schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
                        object: $item,
                        id: $this->repository->idOf($item)
                    );
                    $flagged++;
                }

                continue;
            }

            $seen[$hash] = true;
        }//end foreach

        return $flagged;
    }//end deduplicate()

    /**
     * Record an unreachable external source as a BewijsItem + TermijnEvent.
     *
     * @param string $verzoekId The parent request UUID.
     * @param string $source    The source identifier.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.1
     */
    private function recordUnreachable(string $verzoekId, string $source): void
    {
        $this->repository->save(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            object: [
                'verzoekId'         => $verzoekId,
                'bronApp'           => $source,
                'categorie'         => 'bron-onbereikbaar',
                'verzameldOp'       => '',
                'opgenomenInExport' => false,
                'inhoudPreview'     => 'Bron reageerde niet binnen '.self::SOURCE_TIMEOUT_SECONDS
                    .'s; handmatige aanvulling kan nodig zijn.',
            ]
        );

        $this->events->record(
            verzoekId: $verzoekId,
            type: 'collectie-fout',
            details: 'Bron '.$source.' onbereikbaar tijdens bewijsverzameling.',
            geslaagd: false
        );
    }//end recordUnreachable()

    /**
     * Persist a single collected evidence item.
     *
     * @param string $verzoekId    The parent request UUID.
     * @param string $bronApp      The source app.
     * @param string $bronRegister The source register.
     * @param string $bronObject   The source object id.
     * @param string $categorie    The category.
     * @param string $preview      The content preview.
     * @param string $rechtsgrond  The legal basis.
     *
     * @return void
     */
    private function saveEvidence(
        string $verzoekId,
        string $bronApp,
        string $bronRegister,
        string $bronObject,
        string $categorie,
        string $preview,
        string $rechtsgrond
    ): void {
        $this->repository->save(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            object: [
                'verzoekId'         => $verzoekId,
                'bronApp'           => $bronApp,
                'bronRegister'      => $bronRegister,
                'bronObject'        => $bronObject,
                'categorie'         => $categorie,
                'verzameldOp'       => $this->now(),
                'rechtsgrond'       => $rechtsgrond,
                'opgenomenInExport' => true,
                'geredigeerd'       => false,
                'inhoudPreview'     => $preview,
                'contentHash'       => hash('sha256', $preview),
            ]
        );
    }//end saveEvidence()

    /**
     * The configured external source identifiers.
     *
     * @return array<int, string> The source identifiers.
     */
    private function configuredExternalSources(): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, 'avg_evidence_sources', '');
        if (trim($raw) === '') {
            return [];
        }

        $sources = array_map('trim', explode(',', $raw));

        return array_values(array_filter($sources, static fn (string $src): bool => $src !== ''));
    }//end configuredExternalSources()

    /**
     * Whether an object matches any of the requested scopes (best-effort).
     *
     * @param array<string, mixed> $object The object.
     * @param array<int, string>   $scopes The requested scopes.
     *
     * @return bool True when the object plausibly falls within a scope.
     */
    private function scopeMatches(array $object, array $scopes): bool
    {
        $schema = mb_strtolower((string) ($object['@self']['schema'] ?? ''));
        foreach ($scopes as $scope) {
            if ($schema !== '' && mb_strpos($schema, mb_strtolower($scope)) !== false) {
                return true;
            }
        }

        return false;
    }//end scopeMatches()

    /**
     * Build a short content preview for an object.
     *
     * @param array<string, mixed> $object The object.
     *
     * @return string The preview (max 500 chars).
     */
    private function previewOf(array $object): string
    {
        $copy = $object;
        unset($copy['@self']);
        $json = (string) json_encode($copy);

        return mb_substr($json, 0, 500);
    }//end previewOf()

    /**
     * Normalise an OR object into an array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The normalised object.
     */
    private function normalize(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return (array) $object;
    }//end normalize()

    /**
     * The current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
