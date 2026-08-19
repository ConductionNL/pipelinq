<?php

/**
 * In-memory fake of the OpenRegister AggregationRunner for unit tests.
 *
 * Computes count / sum (grouped + ungrouped) over an in-memory row set using the
 * same filter operator vocabulary the real runner's PHP-fallback path uses
 * (scalar equality, `in`, `gte`, `lte`). This lets the query-pushdown tests
 * assert that the pushed-down aggregation returns the SAME number the prior
 * PHP reduce produced over the identical rows.
 *
 * String-vs-scalar equality is compared loosely (cast both sides to string) to
 * mirror the real runner's magic-table semantics where column values come back
 * as strings; ordered comparisons use numeric/string `<=>`.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;

/**
 * Fake aggregation runner backed by an in-memory row list.
 */
class FakeAggregationRunner
{
    /**
     * @var array<int, array<string, mixed>> The rows to aggregate over.
     */
    private array $rows;

    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $rows The rows.
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }//end __construct()

    /**
     * Run an ad-hoc aggregation over the in-memory rows.
     *
     * @param string           $registerRef The register ref (ignored by the fake).
     * @param string           $schemaRef   The schema ref (ignored by the fake).
     * @param AggregationQuery $query       The query.
     *
     * @return array<string, mixed> The result envelope (mirrors the real shape).
     */
    public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array
    {
        $matched = array_values(
            array_filter($this->rows, fn(array $row): bool => $this->matches(row: $row, filter: $query->filter))
        );

        $groupField = $query->getGroupByField();
        if ($groupField !== null) {
            $buckets = [];
            foreach ($matched as $row) {
                $key = $row[$groupField] ?? null;
                if ($key === null) {
                    $bk = '__NULL__';
                } else {
                    $bk = (string) $key;
                }

                $buckets[$bk]['key']    = $key;
                $buckets[$bk]['rows'][] = $row;
            }

            $groups = [];
            foreach ($buckets as $bucket) {
                $groups[] = [
                    'key'   => $bucket['key'],
                    'value' => $this->metric(rows: $bucket['rows'], metric: $query->metric, field: $query->field),
                ];
            }

            return ['groups' => $groups, 'backend' => 'fake', 'cached' => false];
        }//end if

        return [
            'value'   => $this->metric(rows: $matched, metric: $query->metric, field: $query->field),
            'backend' => 'fake',
            'cached'  => false,
        ];
    }//end runAdhocByRef()

    /**
     * Compute a scalar metric over rows.
     *
     * @param array<int, array<string, mixed>> $rows   The rows.
     * @param string                           $metric count|sum.
     * @param string|null                      $field  The metric field.
     *
     * @return int|float|null The metric value (null sum over an empty/numeric-free set).
     */
    private function metric(array $rows, string $metric, ?string $field): int|float|null
    {
        if ($metric === 'count') {
            return count($rows);
        }

        // sum.
        $acc   = null;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_numeric($value) === false) {
                continue;
            }

            $count++;
            $acc = ((float) ($acc ?? 0.0) + (float) $value);
        }

        if ($count === 0) {
            return null;
        }

        return $acc;
    }//end metric()

    /**
     * Apply the filter map to a row.
     *
     * @param array<string, mixed> $row    The row.
     * @param array<string, mixed> $filter The filter map.
     *
     * @return bool Whether the row matches.
     */
    private function matches(array $row, array $filter): bool
    {
        foreach ($filter as $field => $criterion) {
            $value = $row[$field] ?? null;
            if (is_array($criterion) === false) {
                if ((string) $value !== (string) $criterion) {
                    return false;
                }

                continue;
            }

            foreach ($criterion as $op => $operand) {
                if ($this->checkOp(value: $value, op: (string) $op, operand: $operand) === false) {
                    return false;
                }
            }
        }

        return true;
    }//end matches()

    /**
     * Apply a single operator.
     *
     * @param mixed  $value   The row value.
     * @param string $op      in|gte|lte.
     * @param mixed  $operand The operand.
     *
     * @return bool Whether the operator is satisfied.
     */
    private function checkOp(mixed $value, string $op, mixed $operand): bool
    {
        if ($op === 'in') {
            return is_array($operand) === true && in_array($value, $operand, true);
        }

        if ($value === null) {
            return false;
        }

        // Date-like operands compare as integer timestamps, mirroring both the
        // CashShift PHP path (strtotime) and OpenRegister's native date-range
        // semantics. Non-date scalars fall back to string comparison.
        $lhs = $this->normalise(value: $value);
        $rhs = $this->normalise(value: $operand);

        return match ($op) {
            'gte'   => $lhs >= $rhs,
            'lte'   => $lhs <= $rhs,
            default => true,
        };
    }//end checkOp()

    /**
     * Normalise a value for ordered comparison: date-like strings become
     * integer timestamps, everything else stays a string.
     *
     * @param mixed $value The value.
     *
     * @return int|string The comparable form.
     */
    private function normalise(mixed $value): int|string
    {
        if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return (string) $value;
    }//end normalise()
}//end class
