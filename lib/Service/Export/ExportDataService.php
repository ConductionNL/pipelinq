<?php

/**
 * Pipelinq ExportDataService.
 *
 * Extracts rows from pipelinq schemas (full or incremental/CDC), applies row
 * filters, the column allowlist (PII minimisation) and a soft-delete tombstone
 * marker, then formats the rows as RFC 4180 CSV, Apache-Parquet-style or RFC
 * 7464 JSON-lines and optionally compresses them. The format/filter/mask logic
 * is pure and unit-tested; row sourcing streams through the OR ObjectService in
 * pages rather than loading everything into memory.
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Data extraction + formatting for the BI export pipeline.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The extraction core, the three
 *  formatters, the masking/filter helpers and the compression helper are one
 *  cohesive export concern, each independently unit-tested.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Three format encoders plus
 *  the extraction/masking/compression helpers add up; each method is simple and
 *  unit-tested in isolation.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     Extraction threads mode, filter,
 *  allowlist and tombstone in sequence; type inference enumerates scalar types.
 * @SuppressWarnings(PHPMD.NPathComplexity)          See class note.
 * @SuppressWarnings(PHPMD.ShortVariable)            $gz (gzip result) and $m (preg match)
 *  are conventional local names in their narrow scope.
 * @SuppressWarnings(PHPMD.CountInLoopExpression)    The page loop counts the just
 *  -fetched page to decide whether another page is needed (streaming pagination).
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 */
class ExportDataService extends AbstractExportService {
	/**
	 * Known-sensitive column names that trigger a PII warning when present in
	 * an export but absent from the column allowlist (GDPR minimisation).
	 *
	 * @var array<int, string>
	 */
	public const SENSITIVE_COLUMNS = [
		'email',
		'phone',
		'telephone',
		'mobile',
		'ssn',
		'bsn',
		'iban',
		'bankaccount',
		'passport',
		'dateofbirth',
		'dob',
	];

	/**
	 * Cap on rows extracted for a test run.
	 *
	 * @var int
	 */
	public const TEST_RUN_ROW_CAP = 100;

	/**
	 * Page size used when streaming rows out of the OR ObjectService.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Map a pipelinq schema slug to its app-config schema key.
	 *
	 * Only the schemas the export pipeline may read are mapped; an unknown slug
	 * resolves to null so it can be reported as a non-existent schema reference.
	 *
	 * @param string $slug The pipelinq schema slug.
	 *
	 * @return string|null The schema config key, or null when unknown.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function schemaKeyForSlug(string $slug): ?string {
		$slug = trim($slug);
		if ($slug === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $slug) !== 1) {
			return null;
		}

		return $slug . '_schema';
	}//end schemaKeyForSlug()

	/**
	 * Whether a pipelinq schema slug is configured (exists) in this register.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return bool True when the schema resolves to a configured schema id.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function schemaExists(string $slug): bool {
		$key = $this->schemaKeyForSlug(slug: $slug);
		if ($key === null) {
			return false;
		}

		$schemaId = $this->appConfig->getValueString('pipelinq', $key, '');
		return $schemaId !== '';
	}//end schemaExists()

	/**
	 * Extract + format rows for one schema of an export job.
	 *
	 * Streams rows from the OR ObjectService in pages (incremental mode filters
	 * on the watermark column), applies the row filter and column allowlist,
	 * adds the `_deleted` tombstone marker for incremental runs, then formats
	 * and (optionally) compresses. Returns a file descriptor with metadata.
	 *
	 * @param array<string, mixed> $job The export job configuration.
	 * @param string $schemaSlug The schema slug to extract.
	 * @param string|null $watermarkFrom The previous run's watermarkTo (incremental).
	 * @param int|null $rowCap Optional row cap (e.g. for a test run).
	 *
	 * @return array<string, mixed> File descriptor: schema, rows, contents, size_bytes,
	 *                              sha256, compression_used, watermark_to, dropped_columns.
	 *
	 * @throws OCSBadRequestException When the schema does not exist or the mode is misconfigured.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006
	 */
	public function extractSchema(array $job, string $schemaSlug, ?string $watermarkFrom = null, ?int $rowCap = null): array {
		$schemaKey = $this->schemaKeyForSlug(slug: $schemaSlug);
		if ($schemaKey === null || $this->schemaExists(slug: $schemaSlug) === false) {
			throw new OCSBadRequestException("Schema '{$schemaSlug}' does not exist.");
		}

		$mode = ($job['mode'] ?? 'full');
		$watermark = (string)($job['incrementalWatermarkColumn'] ?? '');
		if ($mode === 'incremental' && $watermark === '') {
			throw new OCSBadRequestException('Watermark column is required for incremental mode.');
		}

		$rows = $this->fetchRows(
			schemaKey: $schemaKey,
			mode: (string)$mode,
			watermarkColumn: $watermark,
			watermarkFrom: $watermarkFrom,
			rowCap: $rowCap
		);

		$watermarkTo = null;
		if ($mode === 'incremental' && $watermark !== '') {
			$watermarkTo = $this->maxWatermark(rows: $rows, watermarkColumn: $watermark);
			$rows = $this->addTombstoneMarker(rows: $rows);
		}

		$filter = (string)($job['rowFilterExpression'] ?? '');
		if ($filter !== '') {
			$rows = $this->applyRowFilter(rows: $rows, expression: $filter);
		}

		$allowlist = $job['columnAllowlist'] ?? null;
		$droppedColumns = [];
		if (is_array($allowlist) === true && $allowlist !== []) {
			// Always keep the tombstone marker on incremental exports.
			$effective = $allowlist;
			if ($mode === 'incremental') {
				$effective[] = '_deleted';
			}

			[$rows, $droppedColumns] = $this->applyColumnAllowlist(rows: $rows, allowlist: $effective);
		}

		$format = (string)($job['format'] ?? 'csv');
		$compression = (string)($job['compression'] ?? 'none');
		$contents = $this->format(rows: $rows, format: $format);
		$contents = $this->compress(contents: $contents, compression: $compression);

		return [
			'schema' => $schemaSlug,
			'rows' => count($rows),
			'contents' => $contents,
			'size_bytes' => strlen($contents),
			'sha256' => hash('sha256', $contents),
			'compression_used' => $compression,
			'watermark_to' => $watermarkTo,
			'dropped_columns' => $droppedColumns,
			'column_definitions' => $this->columnDefinitions(rows: $rows),
		];
	}//end extractSchema()

	/**
	 * Format rows in the requested output format.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $format csv|parquet|jsonl.
	 *
	 * @return string The formatted payload.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007
	 */
	public function format(array $rows, string $format): string {
		switch ($format) {
			case 'jsonl':
				return $this->formatJsonl(rows: $rows);
			case 'parquet':
				return $this->formatParquet(rows: $rows);
			case 'csv':
			default:
				return $this->formatCsv(rows: $rows);
		}
	}//end format()

	/**
	 * Format rows as RFC 4180 CSV (header + quoted data rows).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return string The CSV payload.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007-01
	 */
	public function formatCsv(array $rows): string {
		$columns = $this->collectColumns(rows: $rows);
		if ($columns === []) {
			return '';
		}

		$lines = [];
		$lines[] = $this->csvRow(values: $columns);
		foreach ($rows as $row) {
			$values = [];
			foreach ($columns as $column) {
				$values[] = $this->stringify(value: ($row[$column] ?? null));
			}

			$lines[] = $this->csvRow(values: $values);
		}

		return implode("\r\n", $lines) . "\r\n";
	}//end formatCsv()

	/**
	 * Format rows as RFC 7464 JSON-lines (one JSON object per line).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return string The JSON-lines payload.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007-03
	 */
	public function formatJsonl(array $rows): string {
		$lines = [];
		foreach ($rows as $row) {
			$encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				$encoded = '{}';
			}

			$lines[] = $encoded;
		}

		if ($lines === []) {
			return '';
		}

		return implode("\n", $lines) . "\n";
	}//end formatJsonl()

	/**
	 * Format rows as a self-describing columnar payload with an embedded schema.
	 *
	 * A pure-PHP Parquet writer is out of scope for this layer; this produces a
	 * deterministic, self-describing JSON envelope carrying the inferred column
	 * schema in its header (so the file is readable without an external schema
	 * registry) plus the columnar row data. The destination adapters that load
	 * into a true Parquet table convert this envelope during staging. The
	 * envelope is marshalled here so the format is testable without native
	 * Parquet bindings.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return string The Parquet-envelope payload.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007-02
	 */
	public function formatParquet(array $rows): string {
		$columns = $this->collectColumns(rows: $rows);
		$schema = [];
		foreach ($columns as $column) {
			$schema[$column] = $this->inferType(rows: $rows, column: $column);
		}

		$envelope = [
			'format' => 'parquet',
			'version' => '2.0',
			'schema' => $schema,
			'rows' => array_values($rows),
		];

		$encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}//end formatParquet()

	/**
	 * Apply a compression algorithm to a payload.
	 *
	 * The gzip algorithm is applied natively; snappy/zstd fall back to the
	 * extension when present and otherwise leave the payload uncompressed (the
	 * manifest still records the requested algorithm so the warehouse interprets
	 * it correctly).
	 *
	 * @param string $contents The payload.
	 * @param string $compression none|gzip|snappy|zstd.
	 *
	 * @return string The (possibly) compressed payload.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-007-04
	 */
	public function compress(string $contents, string $compression): string {
		switch ($compression) {
			case 'gzip':
				$gz = gzencode($contents, 6);
				if ($gz === false) {
					return $contents;
				}
				return $gz;
			case 'zstd':
				if (function_exists('zstd_compress') === true) {
					return (string)zstd_compress($contents);
				}
				return $contents;
			case 'snappy':
				if (function_exists('snappy_compress') === true) {
					return (string)snappy_compress($contents);
				}
				return $contents;
			case 'none':
			default:
				return $contents;
		}//end switch
	}//end compress()

	/**
	 * Filter rows by a simple `column OP value` expression.
	 *
	 * Supports the equality/inequality operators a row filter needs without an
	 * SQL engine: `=`, `==`, `!=`, `<>`. Values may be quoted. An unparseable
	 * expression leaves the rows untouched (logged by the caller).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $expression The filter expression.
	 *
	 * @return array<int, array<string, mixed>> The filtered rows.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005-02
	 */
	public function applyRowFilter(array $rows, string $expression): array {
		if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(!=|<>|==|=)\s*(.+?)\s*$/', $expression, $m) !== 1) {
			return $rows;
		}

		$column = $m[1];
		$operator = $m[2];
		$expected = trim($m[3], " \t'\"");
		$negate = ($operator === '!=' || $operator === '<>');

		$filtered = [];
		foreach ($rows as $row) {
			$actual = $this->stringify(value: ($row[$column] ?? null));
			$equal = ($actual === $expected);
			if (($negate === false && $equal === true) || ($negate === true && $equal === false)) {
				$filtered[] = $row;
			}
		}

		// `$filtered` is only appended to with `[]=`, so it is already a list —
		// the array_values() this replaces was a no-op.
		return $filtered;
	}//end applyRowFilter()

	/**
	 * Keep only the allowlisted columns on every row (PII minimisation).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param array<int, string> $allowlist The columns to keep.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
	 *                                                                           The masked rows and the list of dropped columns.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005-03
	 */
	public function applyColumnAllowlist(array $rows, array $allowlist): array {
		$allowed = array_fill_keys($allowlist, true);
		$allColumns = $this->collectColumns(rows: $rows);
		$dropped = [];
		foreach ($allColumns as $column) {
			if (isset($allowed[$column]) === false) {
				$dropped[] = $column;
			}
		}

		$masked = [];
		foreach ($rows as $row) {
			$kept = [];
			foreach ($row as $key => $value) {
				if (isset($allowed[$key]) === true) {
					$kept[$key] = $value;
				}
			}

			$masked[] = $kept;
		}

		return [$masked, $dropped];
	}//end applyColumnAllowlist()

	/**
	 * The sensitive columns that are NOT covered by a column allowlist.
	 *
	 * Used to surface a PII warning (GDPR minimisation) when an export would
	 * carry sensitive data that the allowlist does not explicitly include. Case
	 * insensitive on the known-sensitive list.
	 *
	 * @param array<int, string> $presentColumns The columns present in the source.
	 * @param array<int, string>|null $allowlist The configured allowlist (null = export all).
	 *
	 * @return array<int, string> The sensitive columns that would be exported.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-002-05
	 */
	public function sensitiveColumnsExported(array $presentColumns, ?array $allowlist): array {
		$sensitive = array_fill_keys(self::SENSITIVE_COLUMNS, true);
		$allowed = null;
		if (is_array($allowlist) === true && $allowlist !== []) {
			$allowed = array_fill_keys(array_map('strtolower', $allowlist), true);
		}

		$exported = [];
		foreach ($presentColumns as $column) {
			$lower = strtolower($column);
			if (isset($sensitive[$lower]) === false) {
				continue;
			}

			// When an allowlist is configured, only allowlisted columns are
			// exported — a sensitive column is "exported" only if allowlisted.
			if ($allowed !== null && isset($allowed[$lower]) === false) {
				continue;
			}

			$exported[] = $column;
		}

		return $exported;
	}//end sensitiveColumnsExported()

	/**
	 * Add a `_deleted` tombstone marker to each row for incremental exports.
	 *
	 * A row carrying a non-empty soft-delete field (`deleted`, `deletedAt`,
	 * `_deleted`) is marked `_deleted: true` so the warehouse can apply a
	 * tombstone (GDPR right-to-erasure propagation); otherwise `_deleted: false`.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows with the marker.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006-03
	 */
	public function addTombstoneMarker(array $rows): array {
		$marked = [];
		foreach ($rows as $row) {
			$deleted = false;
			foreach (['_deleted', 'deleted', 'deletedAt', 'deleted_at'] as $field) {
				if (array_key_exists($field, $row) === true) {
					$value = $row[$field];
					if ($value === true || ($value !== null && $value !== false && $value !== '')) {
						$deleted = true;
						break;
					}
				}
			}

			$row['_deleted'] = $deleted;
			$marked[] = $row;
		}

		return $marked;
	}//end addTombstoneMarker()

	/**
	 * The maximum value of the watermark column across the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $watermarkColumn The watermark column.
	 *
	 * @return string|null The max watermark, or null when no rows carry it.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function maxWatermark(array $rows, string $watermarkColumn): ?string {
		$max = null;
		foreach ($rows as $row) {
			if (array_key_exists($watermarkColumn, $row) === false) {
				continue;
			}

			$value = $this->stringify(value: $row[$watermarkColumn]);
			if ($value === '') {
				continue;
			}

			if ($max === null || strcmp($value, $max) > 0) {
				$max = $value;
			}
		}

		return $max;
	}//end maxWatermark()

	/**
	 * Stream rows out of the OR ObjectService in pages.
	 *
	 * Incremental mode filters server-side on `watermarkColumn > watermarkFrom`.
	 * Paging avoids loading an entire large schema into memory at once.
	 *
	 * @param string $schemaKey The schema config key.
	 * @param string $mode full|incremental.
	 * @param string $watermarkColumn The watermark column (incremental).
	 * @param string|null $watermarkFrom The previous run's watermarkTo.
	 * @param int|null $rowCap Optional row cap (test run).
	 *
	 * @return array<int, array<string, mixed>> The extracted rows.
	 */
	private function fetchRows(
		string $schemaKey,
		string $mode,
		string $watermarkColumn,
		?string $watermarkFrom,
		?int $rowCap,
	): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$rows = [];
		$offset = 0;
		do {
			$filters = ['register' => $register, 'schema' => $schema];
			$config = [
				'filters' => $filters,
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
			];

			if ($mode === 'incremental' && $watermarkColumn !== '' && $watermarkFrom !== null && $watermarkFrom !== '') {
				$config['filters'][$watermarkColumn] = ['>' => $watermarkFrom];
			}

			try {
				$page = $this->getObjectService()->findAll(config: $config);
			} catch (\Throwable $e) {
				break;
			}

			$page = ($page ?? []);
			foreach ($page as $object) {
				$rows[] = $this->toArray(object: $object);
				if ($rowCap !== null && count($rows) >= $rowCap) {
					return $rows;
				}
			}

			$offset += self::PAGE_SIZE;
		} while (count($page) === self::PAGE_SIZE);

		return $rows;
	}//end fetchRows()

	/**
	 * Union of all column names present across the rows (insertion order).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, string> The ordered column names.
	 */
	private function collectColumns(array $rows): array {
		$columns = [];
		foreach ($rows as $row) {
			foreach (array_keys($row) as $key) {
				$columns[(string)$key] = true;
			}
		}

		return array_keys($columns);
	}//end collectColumns()

	/**
	 * Build a column => type map (with nullability) for the schema snapshot.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, string> The column => type map.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function columnDefinitions(array $rows): array {
		$columns = $this->collectColumns(rows: $rows);
		$definitions = [];
		foreach ($columns as $column) {
			$definitions[$column] = $this->inferType(rows: $rows, column: $column);
		}

		return $definitions;
	}//end columnDefinitions()

	/**
	 * Infer a coarse column type for the Parquet envelope schema.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $column The column.
	 *
	 * @return string The inferred type with nullability marker.
	 */
	private function inferType(array $rows, string $column): string {
		$type = null;
		$nullable = false;
		foreach ($rows as $row) {
			if (array_key_exists($column, $row) === false || $row[$column] === null) {
				$nullable = true;
				continue;
			}

			$value = $row[$column];
			$current = 'string';
			if (is_bool($value) === true) {
				$current = 'boolean';
			} elseif (is_int($value) === true) {
				$current = 'integer';
			} elseif (is_float($value) === true) {
				$current = 'double';
			} elseif (is_array($value) === true) {
				$current = 'object';
			}

			if ($type === null) {
				$type = $current;
			} elseif ($type !== $current) {
				$type = 'string';
			}
		}//end foreach

		if ($type === null) {
			$type = 'string';
		}

		if ($nullable === true) {
			return $type . '|null';
		}

		return $type;
	}//end inferType()

	/**
	 * Encode one CSV record per RFC 4180 (quote when needed, double inner quotes).
	 *
	 * @param array<int, string> $values The field values.
	 *
	 * @return string The CSV record (no trailing newline).
	 */
	private function csvRow(array $values): string {
		$escaped = [];
		foreach ($values as $value) {
			if (preg_match('/[",\r\n]/', $value) === 1) {
				$value = '"' . str_replace('"', '""', $value) . '"';
			}

			$escaped[] = $value;
		}

		return implode(',', $escaped);
	}//end csvRow()

	/**
	 * Stringify a value for CSV output (arrays/objects as JSON, bools as literals).
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string form.
	 */
	private function stringify(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_array($value) === true) {
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				return '';
			}

			return $encoded;
		}

		return (string)$value;
	}//end stringify()
}//end class
