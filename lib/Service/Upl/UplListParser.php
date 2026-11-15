<?php

/**
 * Pipelinq UplListParser.
 *
 * Reads the national list of government products (UPL) as it is published,
 * a CSV with a header row, into one row per product: its name, its URI and
 * the audiences it is marked for. The columns are found by their header, not
 * by position, so a column added to the published list does not shift them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Upl;

use InvalidArgumentException;

/**
 * Parses the UPL CSV into product rows.
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */
class UplListParser {

	/**
	 * Header names that hold the product's name, normalised.
	 *
	 * @var array<int, string>
	 */
	private const NAME_HEADERS = ['uniformeproductnaam', 'productnaam', 'naam', 'name', 'label', 'preflabel'];

	/**
	 * Header names that hold the product's URI, normalised.
	 *
	 * @var array<int, string>
	 */
	private const URI_HEADERS = ['uri', 'url', 'resource', 'identifier'];

	/**
	 * Audience columns and the product `audience` value each one marks.
	 *
	 * @var array<string, string>
	 */
	private const AUDIENCE_HEADERS = ['burger' => 'resident', 'bedrijf' => 'business'];

	/**
	 * Parse the published list.
	 *
	 * @param string $csv The CSV as published, header row first.
	 *
	 * @return array<int, array{name: string, uri: string, audience: array<int, string>}> One row per
	 *         product, each URI once (the first row wins), rows without a name or an http(s) URI skipped.
	 *
	 * @throws InvalidArgumentException When the header names no name or no URI column.
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function parse(string $csv): array {
		$lines = $this->lines(csv: $csv);
		if ($lines === []) {
			throw new InvalidArgumentException('The UPL list is empty.');
		}

		$delimiter = $this->delimiter(header: $lines[0]);
		$columns = $this->columns(header: str_getcsv($lines[0], $delimiter, '"', ''));

		$rows = [];
		foreach (array_slice($lines, 1) as $line) {
			$row = $this->row(cells: str_getcsv($line, $delimiter, '"', ''), columns: $columns);
			if ($row !== null && isset($rows[$row['uri']]) === false) {
				$rows[$row['uri']] = $row;
			}
		}

		return array_values($rows);
	}//end parse()

	/**
	 * The non-blank lines of the file, without a byte-order mark.
	 *
	 * @param string $csv The CSV.
	 *
	 * @return array<int, string>
	 */
	private function lines(string $csv): array {
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$lines = preg_split('/\r\n|\r|\n/', $csv);
		if ($lines === false) {
			return [];
		}

		return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
	}//end lines()

	/**
	 * The delimiter the header uses: a semicolon when it has more of those than commas.
	 *
	 * @param string $header The header line.
	 *
	 * @return string
	 */
	private function delimiter(string $header): string {
		if (substr_count($header, ';') > substr_count($header, ',')) {
			return ';';
		}

		return ',';
	}//end delimiter()

	/**
	 * Where the name, URI and audience columns are.
	 *
	 * @param array<int, string|null> $header The header cells.
	 *
	 * @return array{name: int, uri: int, audience: array<string, int>}
	 *
	 * @throws InvalidArgumentException When no name or no URI column is found.
	 */
	private function columns(array $header): array {
		$found = ['name' => null, 'uri' => null, 'audience' => []];
		foreach ($header as $index => $cell) {
			$key = strtolower((string)preg_replace('/[^a-zA-Z]/', '', (string)$cell));
			if ($found['name'] === null && in_array($key, self::NAME_HEADERS, true) === true) {
				$found['name'] = $index;
			}

			if ($found['uri'] === null && in_array($key, self::URI_HEADERS, true) === true) {
				$found['uri'] = $index;
			}

			if (isset(self::AUDIENCE_HEADERS[$key]) === true) {
				$found['audience'][self::AUDIENCE_HEADERS[$key]] = $index;
			}
		}

		if ($found['name'] === null || $found['uri'] === null) {
			throw new InvalidArgumentException('The UPL list has no product name or URI column in its header.');
		}

		return $found;
	}//end columns()

	/**
	 * One product row, or null when it has no name or no http(s) URI.
	 *
	 * @param array<int, string|null>                                     $cells   The row's cells.
	 * @param array{name: int, uri: int, audience: array<string, int>} $columns Where the columns are.
	 *
	 * @return array{name: string, uri: string, audience: array<int, string>}|null
	 */
	private function row(array $cells, array $columns): ?array {
		$name = trim((string)($cells[$columns['name']] ?? ''));
		$uri = trim((string)($cells[$columns['uri']] ?? ''));
		if ($name === '' || preg_match('#^https?://\S+$#i', $uri) !== 1) {
			return null;
		}

		$audience = [];
		foreach ($columns['audience'] as $value => $index) {
			if ($this->marked(cell: ($cells[$index] ?? null)) === true) {
				$audience[] = $value;
			}
		}

		return ['name' => $name, 'uri' => $uri, 'audience' => $audience];
	}//end row()

	/**
	 * Whether a flag cell is set: the list marks a column with an X (or yes, ja, true, 1).
	 *
	 * @param string|null $cell The cell.
	 *
	 * @return bool
	 */
	private function marked(?string $cell): bool {
		return in_array(strtolower(trim((string)$cell)), ['x', 'ja', 'yes', 'true', '1'], true);
	}//end marked()
}//end class
