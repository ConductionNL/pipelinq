<?php

/**
 * Pipelinq DataQualityScorer.
 *
 * Computes the per-Master-Entity dataQualityScore (0-1) from three weighted
 * components: completeness (required attributes filled), freshness
 * (exponential decay on the most recent source change) and agreement
 * (proportion of attributes whose sources do not conflict). The component
 * formulas are pure and unit-tested in isolation.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateTimeImmutable;
use Exception;

/**
 * Service for Master Entity data-quality scoring.
 */
class DataQualityScorer
{
    /**
     * Required attributes per entity type for the completeness component.
     *
     * @var array<string, array<int, string>>
     */
    public const REQUIRED_ATTRIBUTES = [
        'contact' => ['name', 'email'],
        'account' => ['name', 'kvkNumber'],
        'product' => ['name', 'sku', 'unitPrice'],
        'vendor'  => ['name', 'kvkNumber'],
    ];

    /**
     * Freshness half-life parameter (days) in the exp decay.
     *
     * @var float
     */
    private const FRESHNESS_TAU = 180.0;

    /**
     * Component weights (must sum to 1.0).
     *
     * @var array<string, float>
     */
    private const WEIGHTS = ['completeness' => 0.3, 'freshness' => 0.4, 'agreement' => 0.3];

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
     * @param array<string, mixed>             $entity        The master entity.
     * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
     * @param string|null                      $asOf          The as-of timestamp.
     *
     * @return float The overall score, rounded to 2 decimals.
     */
    public function score(array $entity, array $sourceRecords, ?string $asOf=null): float
    {
        $completeness = $this->completeness(entity: $entity);
        $freshness    = $this->freshness(entity: $entity, asOf: ($asOf ?? $this->repository->now()));
        $agreement    = $this->agreement(entity: $entity, sourceRecords: $sourceRecords);

        $overall = (
            ($completeness * self::WEIGHTS['completeness']) + ($freshness * self::WEIGHTS['freshness']) + ($agreement * self::WEIGHTS['agreement'])
        );

        return round($overall, 2);
    }//end score()

    /**
     * Completeness: filled required attributes / total required (pure).
     *
     * @param array<string, mixed> $entity The master entity.
     *
     * @return float The completeness component.
     */
    public function completeness(array $entity): float
    {
        $entityType = (string) ($entity['entityType'] ?? '');
        $required   = (self::REQUIRED_ATTRIBUTES[$entityType] ?? []);
        if (empty($required) === true) {
            return 1.0;
        }

        $golden = ($entity['goldenRecord'] ?? []);
        $filled = 0;
        foreach ($required as $attribute) {
            $value = ($golden[$attribute] ?? null);
            if ($value !== null && $value !== '' && $value !== []) {
                $filled++;
            }
        }

        return ($filled / count($required));
    }//end completeness()

    /**
     * Freshness: exp(-days_since_last_change / tau) (pure).
     *
     * Uses the most recent attributeProvenance.lastUpdated across attributes.
     *
     * @param array<string, mixed> $entity The master entity.
     * @param string               $asOf   The as-of timestamp.
     *
     * @return float The freshness component.
     */
    public function freshness(array $entity, string $asOf): float
    {
        $latest     = '';
        $provenance = ($entity['attributeProvenance'] ?? []);
        if (is_array($provenance) === true) {
            foreach ($provenance as $meta) {
                $lastUpdated = (string) ($meta['lastUpdated'] ?? '');
                if ($lastUpdated > $latest) {
                    $latest = $lastUpdated;
                }
            }
        }

        if ($latest === '') {
            return 0.0;
        }

        try {
            $changed = new DateTimeImmutable($latest);
            $now     = new DateTimeImmutable($asOf);
        } catch (Exception $e) {
            return 0.0;
        }

        $days = max(0.0, (($now->getTimestamp() - $changed->getTimestamp()) / 86400.0));

        return exp(-$days / self::FRESHNESS_TAU);
    }//end freshness()

    /**
     * Agreement: 1 - (conflicting_attributes / total_attributes) (pure).
     *
     * An attribute conflicts when two or more non-withdrawn source records
     * supply different non-empty mapped values for it.
     *
     * @param array<string, mixed>             $entity        The master entity (accepted for API
     *                                                        symmetry with the other components).
     * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
     *
     * @return float The agreement component.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $entity keeps the component
     *  signatures uniform (completeness / freshness / agreement all take $entity).
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
     * Score a Master Entity by id and persist the result.
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
