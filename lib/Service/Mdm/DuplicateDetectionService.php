<?php

/**
 * Pipelinq DuplicateDetectionService.
 *
 * Detects duplicate Master Entities by delegating the candidate comparison to
 * OpenRegister's foundational, RBAC/tenant-scoped
 * {@see \OCA\OpenRegister\Service\Quality\DuplicateDetectionService::findDuplicates()}
 * — driven by the `x-openregister-dedup` annotation on the masterEntity schema
 * (blocking key + per-field exact / normalized / levenshtein match rules). The
 * raw OR result (`{objectA, objectB, score, matchedOn[]}`) is adapted into the
 * pipelinq candidate DTO the Duplicate Candidates dashboard and the auto-merge
 * job consume, so those surfaces are unchanged.
 *
 * Kept app-side (not OR primitives): the `autoMergeEligible` decision (depends
 * on the trust-tier rule's `manualOverrideAllowed`) and the higher-confidence
 * pair de-duplication. The hand-rolled deterministic + probabilistic O(n^2)
 * comparison loops were deleted; the pure {@see StringSimilarity} helper is
 * retained only as a noted in-process fallback for when OpenRegister is
 * unavailable (and as the bridge for Jaro-Winkler/TF-IDF, which OR does not yet
 * model).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-mdm-consume-or/specs/master-data-management/spec.md#requirement-or-backed-duplicate-detection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adapts OpenRegister's duplicate-detection service to the pipelinq MDM model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)           StringSimilarity is a pure, stateless
 *  algorithm helper used only in the OR-unavailable fallback path; static access is
 *  the intended call style (no state to inject).
 */
class DuplicateDetectionService
{
    /**
     * Natural-key attributes — when one of these is in OR's matchedOn the
     * candidate is treated as a deterministic match (confidence-1.0 class).
     *
     * @var array<int, string>
     */
    public const NATURAL_KEYS = ['matchKvkNumber', 'matchEmail', 'kvkNumber', 'vatNumber', 'email', 'phone', 'registrationNumber'];

    /**
     * Flattened top-level match fields OpenRegister's similarity reads
     * (mirrors the x-openregister-dedup annotation on the masterEntity schema).
     *
     * @var array<int, array<string, mixed>>
     */
    private const MATCH_RULES = [
        ['field' => 'matchKvkNumber', 'method' => 'exact', 'weight' => 0.4],
        ['field' => 'matchEmail', 'method' => 'exact', 'weight' => 0.3],
        ['field' => 'matchName', 'method' => 'normalized', 'weight' => 0.2],
        ['field' => 'matchName', 'method' => 'levenshtein', 'weight' => 0.1],
    ];

    /**
     * Default Jaro-Winkler name-similarity threshold (fallback path).
     *
     * @var float
     */
    public const DEFAULT_NAME_THRESHOLD = 0.88;

    /**
     * Default TF-IDF address-similarity threshold (fallback path).
     *
     * @var float
     */
    public const DEFAULT_ADDRESS_THRESHOLD = 0.85;

    /**
     * Overall linkage-confidence threshold below which no candidate is emitted
     * by the in-process fallback path (name-dominant blend).
     *
     * @var float
     */
    public const DEFAULT_LINKAGE_THRESHOLD = 0.85;

    /**
     * Threshold for the OpenRegister path. Mirrors the schema's
     * x-openregister-dedup `threshold`. Lower than the fallback gate because
     * OR's score is a weight-normalised mean: a pair agreeing on both natural
     * keys (kvk weight 0.4 + email weight 0.3 = 0.7) but differing on the name
     * formatting still scores ~0.7, so 0.7 captures natural-key collisions that
     * the old deterministic loop flagged at confidence 1.0 without surfacing
     * false positives (a single weak field cannot reach 0.7).
     *
     * @var float
     */
    public const OR_LINKAGE_THRESHOLD = 0.7;

    /**
     * Confidence at/above which a candidate is eligible for auto-merge.
     *
     * @var float
     */
    public const AUTO_MERGE_THRESHOLD = 0.95;

    /**
     * Constructor.
     *
     * @param MasterEntityService       $masterEntities The master-entity service.
     * @param TrustConfigurationService $trust          The trust-tier service.
     * @param MdmObjectRepository       $repository     The MDM object repository (register/schema ids).
     * @param ContainerInterface        $container      The DI container (OR DuplicateDetectionService).
     * @param LoggerInterface           $logger         The logger.
     */
    public function __construct(
        private MasterEntityService $masterEntities,
        private TrustConfigurationService $trust,
        private MdmObjectRepository $repository,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Detect all duplicate candidates for an entity type via OpenRegister.
     *
     * Delegates to OR's findDuplicates() and adapts the result. Falls back to
     * the in-process probabilistic path when OpenRegister is unavailable.
     *
     * @param string $entityType The entity type.
     *
     * @return array<int, array<string, mixed>> The candidate DTOs.
     */
    public function detectDuplicates(string $entityType): array
    {
        $entities = $this->activeEntities(entityType: $entityType);

        // Key by the OpenRegister object uuid (the `id` / `@self.id` field),
        // because OR's findDuplicates() returns those uuids as objectA/objectB
        // — not the domain masterId.
        $byId = [];
        foreach ($entities as $entity) {
            $orId = (string) ($entity['id'] ?? ($entity['@self']['id'] ?? ''));
            if ($orId !== '') {
                $byId[$orId] = $entity;
            }
        }

        $pairs = $this->findOrDuplicates(entityType: $entityType);
        if ($pairs === null) {
            // OpenRegister unavailable — degrade to the in-process fallback.
            return $this->fallbackDetect(entityType: $entityType, entities: $entities);
        }

        $candidates = [];
        foreach ($pairs as $pair) {
            $candidate = $this->adaptPair(entityType: $entityType, pair: $pair, byId: $byId);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $this->dedupeCandidates(candidates: $candidates);
    }//end detectDuplicates()

    /**
     * Call OpenRegister's findDuplicates() for the masterEntity schema.
     *
     * @param string $entityType The entity type (used to scope returned pairs).
     *
     * @return array<int, array<string, mixed>>|null The scored pairs, or null when OR is unavailable.
     */
    private function findOrDuplicates(string $entityType): ?array
    {
        try {
            $service = $this->container->get('OCA\OpenRegister\Service\Quality\DuplicateDetectionService');
        } catch (Throwable $e) {
            $this->logger->warning('Pipelinq MDM: OpenRegister duplicate-detection service unavailable', ['exception' => $e->getMessage()]);
            return null;
        }

        try {
            $pairs = $service->findDuplicates(
                $this->repository->register(),
                $this->repository->schema(schemaSlug: MasterEntityService::SCHEMA),
                self::MATCH_RULES,
                self::OR_LINKAGE_THRESHOLD
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: OpenRegister findDuplicates failed',
                ['entityType' => $entityType, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_array($pairs) === false) {
            return [];
        }

        return $pairs;
    }//end findOrDuplicates()

    /**
     * Adapt one OR pair ({objectA, objectB, score, matchedOn}) into the DTO.
     *
     * Skips pairs whose entities are not both active in the requested type.
     *
     * @param string                             $entityType The entity type.
     * @param array<string, mixed>               $pair       The OR result pair.
     * @param array<string, array<string,mixed>> $byId       Active entities keyed by master id.
     *
     * @return array<string, mixed>|null The candidate DTO, or null when not applicable.
     */
    private function adaptPair(string $entityType, array $pair, array $byId): ?array
    {
        $idA = (string) ($pair['objectA'] ?? '');
        $idB = (string) ($pair['objectB'] ?? '');
        if (isset($byId[$idA]) === false || isset($byId[$idB]) === false) {
            return null;
        }

        $score     = (float) ($pair['score'] ?? 0.0);
        $matchedOn = ($pair['matchedOn'] ?? []);
        if (is_array($matchedOn) === false) {
            $matchedOn = [];
        }

        $isDeterministic = false;
        foreach ($matchedOn as $field) {
            if (in_array((string) $field, self::NATURAL_KEYS, true) === true) {
                $isDeterministic = true;
                break;
            }
        }

        $method     = 'probabilistic-match';
        $confidence = round($score, 2);
        if ($isDeterministic === true) {
            $method     = 'deterministic-key';
            $confidence = 1.0;
        }

        $primaryMatchedOn = '';
        if (count($matchedOn) > 0) {
            $primaryMatchedOn = (string) $matchedOn[0];
        }

        return $this->buildCandidate(
            entityType: $entityType,
            from: $byId[$idB],
            into: $byId[$idA],
            method: $method,
            confidence: $confidence,
            matchedOn: $primaryMatchedOn,
            matchedValue: ''
        );
    }//end adaptPair()

    /**
     * Load the active Master Entities for a type (skips merged / deleted).
     *
     * @param string $entityType The entity type.
     *
     * @return array<int, array<string, mixed>> The active entities.
     */
    private function activeEntities(string $entityType): array
    {
        $entities = $this->masterEntities->findAll($entityType);

        return array_values(
            array_filter(
                $entities,
                static fn (array $e): bool => (($e['status'] ?? 'active') === 'active')
            )
        );
    }//end activeEntities()

    /**
     * In-process fallback when OpenRegister is unavailable.
     *
     * Uses the retained pure {@see StringSimilarity} helper (Jaro-Winkler on
     * name + TF-IDF on address), which OpenRegister does not yet model. This
     * keeps the daily job degrading gracefully rather than producing no
     * candidates at all when the OR service cannot be resolved.
     *
     * @param string                           $entityType The entity type.
     * @param array<int, array<string, mixed>> $entities   The active entities.
     *
     * @return array<int, array<string, mixed>> The candidate DTOs.
     */
    private function fallbackDetect(string $entityType, array $entities): array
    {
        $nameThreshold    = self::DEFAULT_NAME_THRESHOLD;
        $addressThreshold = self::DEFAULT_ADDRESS_THRESHOLD;
        $linkageThreshold = self::DEFAULT_LINKAGE_THRESHOLD;

        $candidates = [];
        $count      = count($entities);
        for ($i = 0; $i < $count; $i++) {
            for ($j = ($i + 1); $j < $count; $j++) {
                $confidence = $this->scorePair(
                    a: $entities[$i],
                    b: $entities[$j],
                    nameThreshold: $nameThreshold,
                    addressThreshold: $addressThreshold
                );

                if ($confidence < $linkageThreshold) {
                    continue;
                }

                $candidates[] = $this->buildCandidate(
                    entityType: $entityType,
                    from: $entities[$j],
                    into: $entities[$i],
                    method: 'probabilistic-match',
                    confidence: round($confidence, 2),
                    matchedOn: 'name+address',
                    matchedValue: ''
                );
            }//end for
        }//end for

        return $this->dedupeCandidates(candidates: $candidates);
    }//end fallbackDetect()

    /**
     * Compute a combined linkage confidence for a candidate pair (fallback).
     *
     * @param array<string, mixed> $a                The first entity.
     * @param array<string, mixed> $b                The second entity.
     * @param float                $nameThreshold    The name gate.
     * @param float                $addressThreshold The address gate.
     *
     * @return float The combined confidence (0 when below the name gate).
     */
    public function scorePair(array $a, array $b, float $nameThreshold, float $addressThreshold): float
    {
        $nameSim = StringSimilarity::jaroWinkler(
            a: $this->goldenValue(entity: $a, attribute: 'name'),
            b: $this->goldenValue(entity: $b, attribute: 'name')
        );

        if ($nameSim < $nameThreshold) {
            return 0.0;
        }

        $addressSim = StringSimilarity::tfidfCosine(
            a: $this->goldenValue(entity: $a, attribute: 'billingAddress').' '.$this->goldenValue(entity: $a, attribute: 'address'),
            b: $this->goldenValue(entity: $b, attribute: 'billingAddress').' '.$this->goldenValue(entity: $b, attribute: 'address')
        );

        $phoneA = $this->digits(value: $this->goldenValue(entity: $a, attribute: 'phone'));
        $phoneB = $this->digits(value: $this->goldenValue(entity: $b, attribute: 'phone'));

        $phoneBonus = 0.0;
        if ($phoneA !== '' && $phoneA === $phoneB) {
            $phoneBonus = 0.05;
        }

        $addressTerm = ($addressSim * 0.5);
        if ($addressSim >= $addressThreshold) {
            $addressTerm = $addressSim;
        }

        // Weighted blend: name dominant, address supportive, phone a small bonus.
        $confidence = (($nameSim * 0.7) + ($addressTerm * 0.3) + $phoneBonus);

        return min(1.0, $confidence);
    }//end scorePair()

    /**
     * Determine whether a candidate is eligible for automatic merge.
     *
     * Eligible when confidence is at/above the auto-merge threshold AND the
     * matched natural key has a trust rule with manualOverrideAllowed=false
     * (i.e. the data model treats it as authoritative and non-negotiable).
     * Stays app-side: depends on the trust configuration, not OpenRegister.
     *
     * @param array<string, mixed> $candidate The candidate DTO.
     *
     * @return bool True when auto-merge is permitted.
     */
    public function isAutoMergeEligible(array $candidate): bool
    {
        $confidence = (float) ($candidate['linkageConfidence'] ?? 0.0);
        if ($confidence < self::AUTO_MERGE_THRESHOLD) {
            return false;
        }

        $matchedOn  = $this->canonicalAttribute(matchedOn: (string) ($candidate['matchedOn'] ?? ''));
        $entityType = (string) ($candidate['entityType'] ?? '');
        if ($matchedOn === '' || $entityType === '') {
            return false;
        }

        $config = $this->trust->getTrustConfig(
            entityType: $entityType,
            attribute: $matchedOn,
            sourceSystem: $this->bestSource(candidate: $candidate, attribute: $matchedOn)
        );
        if ($config === null) {
            return false;
        }

        return (($config['manualOverrideAllowed'] ?? true) === false);
    }//end isAutoMergeEligible()

    /**
     * Map a (possibly flattened) match field back to its canonical attribute.
     *
     * OpenRegister matches on the flattened `match*` projections; the trust
     * configuration keys on the canonical golden-record attribute.
     *
     * @param string $matchedOn The matched-on field.
     *
     * @return string The canonical attribute name.
     */
    private function canonicalAttribute(string $matchedOn): string
    {
        $map = [
            'matchKvkNumber' => 'kvkNumber',
            'matchEmail'     => 'email',
            'matchName'      => 'name',
            'matchPhone'     => 'phone',
        ];

        return ($map[$matchedOn] ?? $matchedOn);
    }//end canonicalAttribute()

    /**
     * Best-effort source system for the matched value (for auto-merge lookup).
     *
     * @param array<string, mixed> $candidate The candidate DTO.
     * @param string               $attribute The canonical attribute name.
     *
     * @return string The source system, or empty string.
     */
    private function bestSource(array $candidate, string $attribute): string
    {
        $provenance = ($candidate['intoEntity']['attributeProvenance'] ?? []);
        if (is_array($provenance) === true && isset($provenance[$attribute]['sourceSystem']) === true) {
            return (string) $provenance[$attribute]['sourceSystem'];
        }

        return '';
    }//end bestSource()

    /**
     * Build a duplicate-candidate DTO.
     *
     * @param string               $entityType   The entity type.
     * @param array<string, mixed> $from         The merged-away entity.
     * @param array<string, mixed> $into         The surviving entity.
     * @param string               $method       The linkage method.
     * @param float                $confidence   The linkage confidence.
     * @param string               $matchedOn    The deciding attribute.
     * @param string               $matchedValue The matched value (deterministic).
     *
     * @return array<string, mixed> The candidate DTO.
     */
    private function buildCandidate(
        string $entityType,
        array $from,
        array $into,
        string $method,
        float $confidence,
        string $matchedOn,
        string $matchedValue
    ): array {
        return [
            'entityType'        => $entityType,
            'fromMasterId'      => (string) ($from['masterId'] ?? ($from['id'] ?? '')),
            'intoMasterId'      => (string) ($into['masterId'] ?? ($into['id'] ?? '')),
            'fromEntity'        => $from,
            'intoEntity'        => $into,
            'linkageMethod'     => $method,
            'linkageConfidence' => $confidence,
            'matchedOn'         => $matchedOn,
            'matchedValue'      => $matchedValue,
            'autoMergeEligible' => false,
        ];
    }//end buildCandidate()

    /**
     * De-duplicate candidates by unordered entity pair, keeping the higher
     * confidence, and apply the (app-side) auto-merge eligibility gate.
     *
     * @param array<int, array<string, mixed>> $candidates The raw candidates.
     *
     * @return array<int, array<string, mixed>> The de-duplicated candidates.
     */
    private function dedupeCandidates(array $candidates): array
    {
        $byPair = [];
        foreach ($candidates as $candidate) {
            $ids = [(string) $candidate['fromMasterId'], (string) $candidate['intoMasterId']];
            sort($ids);
            $key = implode('::', $ids);

            if (isset($byPair[$key]) === false
                || $candidate['linkageConfidence'] > $byPair[$key]['linkageConfidence']
            ) {
                $candidate['autoMergeEligible'] = $this->isAutoMergeEligible(candidate: $candidate);
                $byPair[$key] = $candidate;
            }
        }

        return array_values($byPair);
    }//end dedupeCandidates()

    /**
     * Read a golden-record value as a trimmed string (fallback path).
     *
     * @param array<string, mixed> $entity    The master entity.
     * @param string               $attribute The attribute name.
     *
     * @return string The value, or empty string.
     */
    private function goldenValue(array $entity, string $attribute): string
    {
        $value = ($entity['goldenRecord'][$attribute] ?? '');
        if (is_scalar($value) === false) {
            return '';
        }

        return trim((string) $value);
    }//end goldenValue()

    /**
     * Reduce a string to its digits (for phone comparison, fallback path).
     *
     * @param string $value The value.
     *
     * @return string The digits only.
     */
    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }//end digits()
}//end class
