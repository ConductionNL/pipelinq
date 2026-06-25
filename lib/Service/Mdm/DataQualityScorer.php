<?php

/**
 * Pipelinq DataQualityScorer.
 *
 * Computes the per-Master-Entity dataQualityScore (0-1) as a blend of two
 * terms:
 *   - OpenRegister's materialised `qualityScore` — the generic field-quality
 *     dimensions (completeness, format, freshness) declared on the masterEntity
 *     schema via the `x-openregister-quality` annotation and written on save by
 *     OR's QualityScoreOnSaveListener.
 *   - The cross-source *agreement* (conflict) term, which depends on the linked
 *     source records (multiple objects, their mappedAttributes) and therefore
 *     cannot be expressed as a single-object OR rule — it stays in pipelinq.
 *
 * The completeness / format / freshness formulas previously hand-rolled here
 * have been delegated to OpenRegister (ADR-022); only the agreement term and
 * the blend remain app-side.
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
 * @spec openspec/changes/pipelinq-mdm-consume-or/specs/master-data-management/spec.md#requirement-or-materialised-data-quality
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

/**
 * Service for Master Entity data-quality scoring.
 *
 * The overall score blends OpenRegister's materialised `qualityScore`
 * (completeness/format/freshness) with the app-side agreement term.
 */
class DataQualityScorer
{
    /**
     * Component weights for the blend of OR quality vs app-side agreement
     * (must sum to 1.0).
     *
     * @var array<string, float>
     */
    private const WEIGHTS = ['orQuality' => 0.7, 'agreement' => 0.3];

    /**
     * Significance threshold for updating lastReviewedAt on score change.
     *
     * @var float
     */
    public const SIGNIFICANCE = 0.05;

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository     The MDM object repository.
     * @param MasterEntityService $masterEntities The master-entity service.
     */
    public function __construct(
        private MdmObjectRepository $repository,
        private MasterEntityService $masterEntities,
    ) {
    }//end __construct()

    /**
     * Compute the overall data-quality score for a Master Entity (0-1).
     *
     * Blends OpenRegister's materialised `qualityScore` (completeness / format /
     * freshness) with the app-side cross-source agreement term.
     *
     * @param array<string, mixed>             $entity        The master entity (carries OR's qualityScore).
     * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
     *
     * @return float The overall score, rounded to 2 decimals.
     */
    public function score(array $entity, array $sourceRecords): float
    {
        $orQuality = $this->orQuality(entity: $entity);
        $agreement = $this->agreement(entity: $entity, sourceRecords: $sourceRecords);

        $overall = (
            ($orQuality * self::WEIGHTS['orQuality']) + ($agreement * self::WEIGHTS['agreement'])
        );

        return round($overall, 2);
    }//end score()

    /**
     * Read OpenRegister's materialised per-object quality score (0-1).
     *
     * Falls back to 0.0 when absent (e.g. the schema annotation has not yet
     * materialised a score onto the object).
     *
     * @param array<string, mixed> $entity The master entity.
     *
     * @return float The OR quality score, clamped to [0, 1].
     */
    public function orQuality(array $entity): float
    {
        $value = ($entity['qualityScore'] ?? null);
        if (is_int($value) === false && is_float($value) === false) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }//end orQuality()

    /**
     * Agreement: 1 - (conflicting_attributes / total_attributes) (pure).
     *
     * An attribute conflicts when two or more non-withdrawn source records
     * supply different non-empty mapped values for it. This term depends on the
     * linked source records and is not expressible as a single-object OR rule,
     * so it stays in pipelinq.
     *
     * @param array<string, mixed>             $entity        The master entity (accepted for API
     *                                                        symmetry; unused).
     * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
     *
     * @return float The agreement component.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $entity keeps the component
     *  signature uniform with score()'s callers.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  The conflict tally walks the
     *  source records with the necessary null/withdrawn/empty guards; the branches
     *  are flat validation, not tangled logic.
     */
    public function agreement(array $entity, array $sourceRecords): float
    {
        unset($entity);

        $valuesByAttribute = [];
        foreach ($sourceRecords as $record) {
            if (($record['withdrawn'] ?? false) === true) {
                continue;
            }

            $mapped = ($record['mappedAttributes'] ?? []);
            if (is_array($mapped) === false) {
                continue;
            }

            foreach ($mapped as $attribute => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $valuesByAttribute[$attribute][] = $value;
            }
        }

        $total = count($valuesByAttribute);
        if ($total === 0) {
            return 1.0;
        }

        $conflicting = 0;
        foreach ($valuesByAttribute as $values) {
            if (count(array_unique($values, SORT_REGULAR)) > 1) {
                $conflicting++;
            }
        }

        return (1.0 - ($conflicting / $total));
    }//end agreement()

    /**
     * Score a Master Entity by id and persist the blended result.
     *
     * Reads OpenRegister's materialised `qualityScore` off the entity, blends it
     * with the app-side agreement term, and writes the result into the
     * `dataQualityScore` field the MDM views read.
     *
     * @param string $masterId The master entity uuid.
     *
     * @return float|null The new score, or null if the entity is absent.
     */
    public function scoreEntity(string $masterId): ?float
    {
        $entity = $this->masterEntities->find($masterId);
        if ($entity === null) {
            return null;
        }

        $sources  = $this->masterEntities->linkedSourceRecords(masterId: $masterId);
        $previous = (float) ($entity['dataQualityScore'] ?? 0.0);
        $score    = $this->score(entity: $entity, sourceRecords: $sources);

        $entity['dataQualityScore'] = $score;
        if (abs($score - $previous) >= self::SIGNIFICANCE) {
            $entity['lastReviewedAt'] = $this->repository->now();
        }

        $this->repository->save(MasterEntityService::SCHEMA, $entity, $masterId);

        return $score;
    }//end scoreEntity()
}//end class
