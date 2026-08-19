<?php

/**
 * Test stub for OCA\OpenRegister\Service\Aggregation\AggregationQuery.
 *
 * Provides a minimal, behaviourally-faithful value object so unit tests running
 * in a bare environment (no Nextcloud server, no openregister installed) can
 * build the same query objects pipelinq's reporting services build, and a fake
 * AggregationRunner can read their fields back.
 *
 * Loaded via Composer's autoload-dev PSR-4 mapping
 * ("OCA\\OpenRegister\\" => "tests/Stubs/") and is a no-op when the real
 * openregister app is present (class_exists guard).
 *
 * Mirrors the real factory's validation surface (metric allow-list, field
 * required for non-count, groupBy requires a field) so a test that passes a
 * malformed query fails the same way production would.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;

if (class_exists(AggregationQuery::class) === false) {
    /**
     * Stub AggregationQuery — used only in standalone unit tests.
     *
     * Replaced by the real implementation when openregister is installed.
     */
    class AggregationQuery
    {
        /**
         * @var array<int, string> Allowed metric names.
         */
        private const METRICS = ['count', 'sum', 'avg', 'min', 'max'];

        /**
         * Constructor — use the static factory.
         *
         * @param string                    $metric  The metric.
         * @param string|null               $field   The field (null for count).
         * @param array<string, mixed>      $filter  The filter map.
         * @param array<string, mixed>|null $groupBy The optional groupBy spec.
         */
        private function __construct(
            public readonly string $metric,
            public readonly ?string $field,
            public readonly array $filter,
            public readonly ?array $groupBy
        ) {
        }//end __construct()

        /**
         * Build a query, validating the same invariants as the real factory.
         *
         * @param string                    $metric  One of count/sum/avg/min/max.
         * @param string|null               $field   Field for non-count metrics.
         * @param array<string, mixed>      $filter  Filter map.
         * @param array<string, mixed>|null $groupBy Optional groupBy spec.
         *
         * @return self
         *
         * @throws InvalidArgumentException When the input is invalid.
         */
        public static function create(
            string $metric,
            ?string $field=null,
            array $filter=[],
            ?array $groupBy=null
        ): self {
            if (in_array($metric, self::METRICS, true) === false) {
                throw new InvalidArgumentException('aggregation metric MUST be one of: '.implode(', ', self::METRICS));
            }

            if ($metric !== 'count' && ($field === null || $field === '')) {
                throw new InvalidArgumentException('aggregation metric "'.$metric.'" MUST specify a field');
            }

            if ($groupBy !== null && (isset($groupBy['field']) === false || $groupBy['field'] === '')) {
                throw new InvalidArgumentException('groupBy MUST include a non-empty `field`');
            }

            return new self(metric: $metric, field: $field, filter: $filter, groupBy: $groupBy);
        }//end create()

        /**
         * Whether the query carries a groupBy clause.
         *
         * @return bool
         */
        public function isGrouped(): bool
        {
            return ($this->groupBy !== null);
        }//end isGrouped()

        /**
         * The groupBy field, or null when ungrouped.
         *
         * @return string|null
         */
        public function getGroupByField(): ?string
        {
            if ($this->groupBy === null) {
                return null;
            }

            $field = ($this->groupBy['field'] ?? null);
            if (is_string($field) === true) {
                return $field;
            }

            return null;
        }//end getGroupByField()
    }//end class
}//end if
