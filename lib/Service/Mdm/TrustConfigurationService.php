<?php

/**
 * Pipelinq TrustConfigurationService.
 *
 * Owns the per-(entityType, attribute, sourceSystem) trust-tier rules that the
 * golden-record recomputation uses to pick the winning value for each
 * attribute. Provides tier lookup (respecting effectiveFrom), CRUD over the
 * trustConfiguration schema and the freshness-decay degradation that lowers a
 * source's tier once it has gone stale.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use DateTimeImmutable;
use Exception;

/**
 * Service for trust-tier configuration and freshness decay.
 */
class TrustConfigurationService
{
    /**
     * The trustConfiguration schema slug.
     *
     * @var string
     */
    private const SCHEMA = 'trustConfiguration';

    /**
     * Ordered trust tiers, strongest first.
     *
     * @var array<int, string>
     */
    public const TIER_ORDER = ['gold', 'silver', 'bronze', 'discard'];

    /**
     * Constructor.
     *
     * @param MdmObjectRepository $repository The MDM object repository.
     */
    public function __construct(
        private MdmObjectRepository $repository,
    ) {
    }//end __construct()

    /**
     * Numeric rank of a tier (higher = more trusted). Unknown tiers rank lowest.
     *
     * @param string|null $tier The tier name.
     *
     * @return int The rank (gold=3 … discard=0; unknown=-1).
     */
    public function tierRank(?string $tier): int
    {
        if ($tier === null) {
            return -1;
        }

        $index = array_search($tier, self::TIER_ORDER, true);
        if ($index === false) {
            return -1;
        }

        return (count(self::TIER_ORDER) - 1 - (int) $index);
    }//end tierRank()

    /**
     * Lower a tier by one level (gold→silver→bronze→discard). Discard stays.
     *
     * @param string $tier The starting tier.
     *
     * @return string The degraded tier.
     */
    public function degradeTier(string $tier): string
    {
        $index = array_search($tier, self::TIER_ORDER, true);
        if ($index === false || $index >= (count(self::TIER_ORDER) - 1)) {
            return 'discard';
        }

        return self::TIER_ORDER[((int) $index + 1)];
    }//end degradeTier()

    /**
     * Fetch the trust-configuration row for a tuple, honouring effectiveFrom.
     *
     * When several rows match (e.g. historical effectiveFrom changes) the most
     * recent rule whose effectiveFrom is on/before $asOfDate wins.
     *
     * @param string      $entityType   The entity type.
     * @param string      $attribute    The attribute name.
     * @param string      $sourceSystem The source system slug.
     * @param string|null $asOfDate     The as-of date (ISO 8601). Null = now.
     *
     * @return array<string, mixed>|null The config row, or null if none applies.
     */
    public function getTrustConfig(
        string $entityType,
        string $attribute,
        string $sourceSystem,
        ?string $asOfDate=null
    ): ?array {
        $asOf = ($asOfDate ?? $this->repository->now());

        $rows = $this->repository->findAll(
            self::SCHEMA,
            [
                'entityType'   => $entityType,
                'attribute'    => $attribute,
                'sourceSystem' => $sourceSystem,
            ]
        );

        $best     = null;
        $bestFrom = '';
        foreach ($rows as $row) {
            $effectiveFrom = (string) ($row['effectiveFrom'] ?? '');
            if ($effectiveFrom !== '' && $effectiveFrom > substr($asOf, 0, 10)) {
                continue;
            }

            if ($best === null || $effectiveFrom >= $bestFrom) {
                $best     = $row;
                $bestFrom = $effectiveFrom;
            }
        }

        return $best;
    }//end getTrustConfig()

    /**
     * Resolve the effective trust tier for a tuple, after freshness decay.
     *
     * @param string      $entityType   The entity type.
     * @param string      $attribute    The attribute name.
     * @param string      $sourceSystem The source system slug.
     * @param string|null $lastChange   The source-record lastChange timestamp.
     * @param string|null $asOfDate     The as-of date. Null = now.
     *
     * @return string|null The effective tier, or null when no config exists.
     */
    public function getTrustTier(
        string $entityType,
        string $attribute,
        string $sourceSystem,
        ?string $lastChange=null,
        ?string $asOfDate=null
    ): ?string {
        $config = $this->getTrustConfig(
            entityType: $entityType,
            attribute: $attribute,
            sourceSystem: $sourceSystem,
            asOfDate: $asOfDate
        );
        if ($config === null) {
            return null;
        }

        $tier = (string) ($config['trustTier'] ?? '');
        if ($tier === '') {
            return null;
        }

        return $this->applyFreshnessDecay(
            tier: $tier,
            decayDays: $config['freshnessDecayDays'] ?? null,
            lastChange: $lastChange,
            asOfDate: $asOfDate
        );
    }//end getTrustTier()

    /**
     * Apply freshness decay to a tier given the source-record's lastChange.
     *
     * Pure function: if more than decayDays have elapsed since lastChange, the
     * tier is lowered one level. Null decayDays or null lastChange = no decay.
     *
     * @param string      $tier       The configured tier.
     * @param int|null    $decayDays  The decay window in days.
     * @param string|null $lastChange The source-record lastChange timestamp.
     * @param string|null $asOfDate   The as-of date. Null = now.
     *
     * @return string The (possibly degraded) tier.
     */
    public function applyFreshnessDecay(
        string $tier,
        ?int $decayDays,
        ?string $lastChange,
        ?string $asOfDate=null
    ): string {
        if ($decayDays === null || $decayDays <= 0 || $lastChange === null || $lastChange === '') {
            return $tier;
        }

        $asOf = ($asOfDate ?? $this->repository->now());

        try {
            $changed = new DateTimeImmutable($lastChange);
            $now     = new DateTimeImmutable($asOf);
        } catch (Exception $e) {
            return $tier;
        }

        $elapsedDays = (int) floor(($now->getTimestamp() - $changed->getTimestamp()) / 86400);
        if ($elapsedDays > $decayDays) {
            return $this->degradeTier(tier: $tier);
        }

        return $tier;
    }//end applyFreshnessDecay()

    /**
     * Create or update a trust-configuration entry.
     *
     * @param array<string, mixed> $config The configuration data.
     * @param string|null          $id     Optional uuid to update.
     *
     * @return array<string, mixed> The saved configuration.
     */
    public function updateTrustConfig(array $config, ?string $id=null): array
    {
        return $this->repository->save(self::SCHEMA, $config, $id);
    }//end updateTrustConfig()

    /**
     * List all trust-configuration entries, optionally filtered by entityType.
     *
     * @param string|null $entityType Optional entity-type filter.
     *
     * @return array<int, array<string, mixed>> The configuration rows.
     */
    public function listTrustConfigs(?string $entityType=null): array
    {
        $filters = [];
        if ($entityType !== null && $entityType !== '') {
            $filters['entityType'] = $entityType;
        }

        return $this->repository->findAll(self::SCHEMA, $filters);
    }//end listTrustConfigs()

    /**
     * Delete a trust-configuration entry.
     *
     * @param string $id The entry uuid.
     *
     * @return void
     */
    public function deleteTrustConfig(string $id): void
    {
        $this->repository->delete(self::SCHEMA, $id);
    }//end deleteTrustConfig()
}//end class
