<?php

/**
 * Pipelinq DuplicateDetectionService.
 *
 * Detects duplicate Master Entities by two complementary strategies:
 *   - Deterministic matching on natural keys (KvK, VAT, email, phone,
 *     registration number) → linkageConfidence 1.0.
 *   - Probabilistic fuzzy matching (Jaro-Winkler on name + TF-IDF cosine on
 *     address/phone) with configurable thresholds → computed confidence.
 *
 * Returns duplicate-candidate DTOs (never persisted schemas). High-confidence
 * candidates whose deciding attribute has a non-overridable trust rule can be
 * surfaced for same-day auto-merge.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-002
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

/**
 * Service for deterministic and probabilistic duplicate detection.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)           StringSimilarity is a pure, stateless
 *  algorithm helper; static access is the intended call style (no state to inject).
 */
class DuplicateDetectionService
{
    /**
     * Natural-key attributes used for deterministic matching.
     *
     * @var array<int, string>
     */
    public const NATURAL_KEYS = ['kvkNumber', 'vatNumber', 'email', 'phone', 'registrationNumber'];

    /**
     * Default Jaro-Winkler name-similarity threshold.
     *
     * @var float
     */
    public const DEFAULT_NAME_THRESHOLD = 0.88;

    /**
     * Default TF-IDF address-similarity threshold.
     *
     * @var float
     */
    public const DEFAULT_ADDRESS_THRESHOLD = 0.85;

    /**
     * Overall linkage-confidence threshold below which no candidate is emitted.
     *
     * @var float
     */
    public const DEFAULT_LINKAGE_THRESHOLD = 0.85;

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
     */
    public function __construct(
        private MasterEntityService $masterEntities,
        private TrustConfigurationService $trust,
    ) {
    }//end __construct()

    /**
     * Detect all duplicate candidates for an entity type (both strategies).
     *
     * @param string $entityType The entity type.
     *
     * @return array<int, array<string, mixed>> The candidate DTOs.
     */
    public function detectDuplicates(string $entityType): array
    {
        $entities = $this->activeEntities(entityType: $entityType);

        $deterministic = $this->detectDeterministicDuplicates(entityType: $entityType, entities: $entities);
        $probabilistic = $this->detectProbabilisticDuplicates(entityType: $entityType, entities: $entities);

        return $this->dedupeCandidates(candidates: array_merge($deterministic, $probabilistic));
    }//end detectDuplicates()

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
     * Deterministic detection: identical natural-key value across two entities.
     *
     * @param string                           $entityType The entity type.
     * @param array<int, array<string, mixed>> $entities   The candidate entities.
     *
     * @return array<int, array<string, mixed>> The deterministic candidates.
     */
    public function detectDeterministicDuplicates(string $entityType, array $entities): array
    {
        $candidates = [];
        foreach (self::NATURAL_KEYS as $key) {
            $byValue = [];
            foreach ($entities as $entity) {
                $value = $this->goldenValue(entity: $entity, attribute: $key);
                if ($value === '') {
                    continue;
                }

                $byValue[$value][] = $entity;
            }

            foreach ($byValue as $value => $group) {
                if (count($group) < 2) {
                    continue;
                }

                // Emit a candidate for each unordered pair in the collision group.
                $count = count($group);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = ($i + 1); $j < $count; $j++) {
                        $candidates[] = $this->buildCandidate(
                            entityType: $entityType,
                            from: $group[$j],
                            into: $group[$i],
                            method: 'deterministic-key',
                            confidence: 1.0,
                            matchedOn: $key,
                            matchedValue: (string) $value
                        );
                    }
                }
            }//end foreach
        }//end foreach

        return $candidates;
    }//end detectDeterministicDuplicates()

    /**
     * Probabilistic detection: fuzzy name + address/phone similarity.
     *
     * @param string                           $entityType The entity type.
     * @param array<int, array<string, mixed>> $entities   The candidate entities.
     * @param array<string, float>             $thresholds Optional threshold overrides.
     *
     * @return array<int, array<string, mixed>> The probabilistic candidates.
     */
    public function detectProbabilisticDuplicates(
        string $entityType,
        array $entities,
        array $thresholds=[]
    ): array {
        $nameThreshold    = (float) ($thresholds['name'] ?? self::DEFAULT_NAME_THRESHOLD);
        $addressThreshold = (float) ($thresholds['address'] ?? self::DEFAULT_ADDRESS_THRESHOLD);
        $linkageThreshold = (float) ($thresholds['linkage'] ?? self::DEFAULT_LINKAGE_THRESHOLD);

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

        return $candidates;
    }//end detectProbabilisticDuplicates()

    /**
     * Compute a combined linkage confidence for a candidate pair.
     *
     * The name similarity (Jaro-Winkler) is the dominant signal, boosted by
     * address agreement (TF-IDF) and an exact-phone bonus. The combined score
     * is only returned when the name passes its own threshold, so wildly
     * different names never reach the candidate stage regardless of address.
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

        $matchedOn  = (string) ($candidate['matchedOn'] ?? '');
        $entityType = (string) ($candidate['entityType'] ?? '');
        if ($matchedOn === '' || $entityType === '') {
            return false;
        }

        $config = $this->trust->getTrustConfig(
            entityType: $entityType,
            attribute: $matchedOn,
            sourceSystem: $this->bestSource(candidate: $candidate)
        );
        if ($config === null) {
            return false;
        }

        return (($config['manualOverrideAllowed'] ?? true) === false);
    }//end isAutoMergeEligible()

    /**
     * Best-effort source system for the matched value (for auto-merge lookup).
     *
     * @param array<string, mixed> $candidate The candidate DTO.
     *
     * @return string The source system, or empty string.
     */
    private function bestSource(array $candidate): string
    {
        $provenance = ($candidate['intoEntity']['attributeProvenance'] ?? []);
        $matchedOn  = (string) ($candidate['matchedOn'] ?? '');
        if (is_array($provenance) === true && isset($provenance[$matchedOn]['sourceSystem']) === true) {
            return (string) $provenance[$matchedOn]['sourceSystem'];
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
     * confidence (deterministic outranks a weaker probabilistic match).
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
     * Read a golden-record value as a trimmed string.
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
     * Reduce a string to its digits (for phone comparison).
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
