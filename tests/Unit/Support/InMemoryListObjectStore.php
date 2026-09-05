<?php

/**
 * In-memory ListObjectStore for the campaign unit tests.
 *
 * Stands in for `OCA\Pipelinq\Service\Marketing\ListObjectStore` with real
 * behaviour rather than a mock's canned answers: it stores rows per schema,
 * hands out ids, and re-applies filters the way the real store does, so a
 * test that writes a lead can then read it back the way production would.
 *
 * It mirrors the one property of the real store the campaign code depends
 * on and a mock cannot express: `findAll()` matches on EXACT string
 * equality, so a case difference is a miss. The contact lookup is written
 * against that fact, and this double is what keeps the test honest about it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * A ListObjectStore backed by arrays.
 */
class InMemoryListObjectStore extends ListObjectStore {

	/**
	 * Schema slug to id to row.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * How many times save() was called, per schema.
	 *
	 * @var array<string, int>
	 */
	public array $writes = [];

	/**
	 * Next generated id.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Construct with a seeded set of rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seed Schema slug to rows.
	 */
	public function __construct(array $seed = []) {
		foreach ($seed as $schemaSlug => $rows) {
			foreach ($rows as $row) {
				$id = (string)($row['uuid'] ?? ($row['id'] ?? $this->nextId()));
				$row['uuid'] = $id;
				$this->rows[$schemaSlug][$id] = $row;
			}
		}
	}//end __construct()

	/**
	 * The schema slug, ignoring any configured override.
	 *
	 * @param string $configKey Ignored.
	 * @param string $default The slug.
	 *
	 * @return string The slug.
	 */
	public function schemaSlug(string $configKey, string $default): string {
		return $default;
	}//end schemaSlug()

	/**
	 * One row by id.
	 *
	 * @param string $schemaSlug The schema.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function find(string $schemaSlug, string $id): ?array {
		return ($this->rows[$schemaSlug][$id] ?? null);
	}//end find()

	/**
	 * Every row matching a filter set, matched on exact string equality.
	 *
	 * @param string $schemaSlug The schema.
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$out = [];
		foreach (($this->rows[$schemaSlug] ?? []) as $row) {
			$matches = true;
			foreach ($filters as $field => $value) {
				if ((string)($row[$field] ?? '') !== (string)$value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * Create or replace a row.
	 *
	 * @param string $schemaSlug The schema.
	 * @param array<string, mixed> $payload The row.
	 * @param string|null $id The id when updating.
	 *
	 * @return array<string, mixed>|null The saved row.
	 */
	public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
		$this->writes[$schemaSlug] = (($this->writes[$schemaSlug] ?? 0) + 1);

		$uuid = $id;
		if ($uuid === null || $uuid === '') {
			$uuid = $this->nextId();
		}

		$row = $payload;
		$row['uuid'] = $uuid;
		$this->rows[$schemaSlug][$uuid] = $row;

		return $row;
	}//end save()

	/**
	 * The canonical id of a row.
	 *
	 * @param array<string, mixed>|null $payload The row.
	 *
	 * @return string The id, or an empty string.
	 */
	public function idOf(?array $payload): string {
		if ($payload === null) {
			return '';
		}

		return (string)($payload['uuid'] ?? ($payload['id'] ?? ''));
	}//end idOf()

	/**
	 * How many rows one schema holds.
	 *
	 * @param string $schemaSlug The schema.
	 *
	 * @return int The count.
	 */
	public function countOf(string $schemaSlug): int {
		return count(($this->rows[$schemaSlug] ?? []));
	}//end countOf()

	/**
	 * The next generated id.
	 *
	 * @return string The id.
	 */
	private function nextId(): string {
		$this->sequence++;

		return ('obj-' . $this->sequence);
	}//end nextId()
}//end class
