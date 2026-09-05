<?php

/**
 * Pipelinq SchemaEvolutionService.
 *
 * Detects column-level drift between a current pipelinq schema and the snapshot
 * captured at the previous export run (added / removed / type-changed columns)
 * so the export handles schema evolution gracefully rather than breaking or
 * silently dropping data.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

/**
 * Column-drift detection for the export schema snapshots.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
 */
class SchemaEvolutionService {
	/**
	 * Compare a current column definition map to the previous snapshot's.
	 *
	 * @param array<string, string> $current The current column => type map.
	 * @param array<string, string> $previous The previous column => type map.
	 *
	 * @return array<int, string> Change descriptions, e.g.
	 *                            ["added: phone", "removed: legacy", "changed: status (string -> enum)"].
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009-01
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009-02
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009-03
	 */
	public function compareColumns(array $current, array $previous): array {
		$changes = [];

		foreach ($current as $column => $type) {
			if (array_key_exists($column, $previous) === false) {
				$changes[] = "added: {$column}";
				continue;
			}

			if ((string)$previous[$column] !== (string)$type) {
				$changes[] = "changed: {$column} ({$previous[$column]} -> {$type})";
			}
		}

		foreach ($previous as $column => $type) {
			if (array_key_exists($column, $current) === false) {
				$changes[] = "removed: {$column}";
			}
		}

		return $changes;
	}//end compareColumns()

	/**
	 * Compare against a previous snapshot record (with columnDefinitionsJson).
	 *
	 * @param array<string, string> $current The current column => type map.
	 * @param array<string, mixed>|null $previous The previous exportSchemaSnapshot, or null.
	 *
	 * @return array<int, string> The change descriptions (empty when no prior snapshot).
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
	 */
	public function compareToSnapshot(array $current, ?array $previous): array {
		if ($previous === null) {
			return [];
		}

		$previousColumns = $previous['columnDefinitionsJson'] ?? [];
		if (is_array($previousColumns) === false) {
			return [];
		}

		$normalized = [];
		foreach ($previousColumns as $column => $type) {
			$normalized[(string)$column] = (string)$type;
		}

		return $this->compareColumns(current: $current, previous: $normalized);
	}//end compareToSnapshot()

	/**
	 * Derive a column => type map from a representative set of rows.
	 *
	 * Used to capture the schema structure of a schema at export time when an
	 * explicit definition is not otherwise available.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, string> The column => type map.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Per-column scalar type
	 *  inference enumerates the small fixed set of JSON scalar types.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function deriveColumnDefinitions(array $rows): array {
		$columns = [];
		foreach ($rows as $row) {
			foreach ($row as $column => $value) {
				$type = $this->typeOf(value: $value);
				$key = (string)$column;
				if (isset($columns[$key]) === false) {
					$columns[$key] = $type;
					continue;
				}

				if ($columns[$key] !== $type && $type !== 'null') {
					if ($columns[$key] === 'null') {
						$columns[$key] = $type;
					} elseif (str_contains($columns[$key], '|null') === false && $type === 'null') {
						$columns[$key] = $columns[$key] . '|null';
					} elseif ($columns[$key] !== $type) {
						$columns[$key] = 'string';
					}
				}
			}
		}

		return $columns;
	}//end deriveColumnDefinitions()

	/**
	 * Coarse type name for a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The type name.
	 */
	private function typeOf(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return 'boolean';
		}

		if (is_int($value) === true) {
			return 'integer';
		}

		if (is_float($value) === true) {
			return 'double';
		}

		if (is_array($value) === true) {
			return 'object';
		}

		return 'string';
	}//end typeOf()
}//end class
