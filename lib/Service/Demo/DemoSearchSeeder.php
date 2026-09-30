<?php

/**
 * Pipelinq DemoSearchSeeder.
 *
 * Seeds and removes the demo Search Console rows and keyword targets, for
 * DemoSeedService.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryDailyStore;

/**
 * Demo Search Console data, written the way an import writes it.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSearchSeeder {
	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Session-free object access.
	 * @param DemoSeedValues $values Placeholder resolution.
	 */
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly DemoSeedValues $values,
	) {
	}//end __construct()

	/**
	 * Seed the demo Search Console rows and keyword targets.
	 *
	 * The rows stand in for an import, so they are written as the import
	 * writes them (property, date, query, page, clicks, impressions, ctr,
	 * position), with the click rate derived here so it can never disagree
	 * with the counts. Query text has to read as real search, so the rows
	 * carry the demo property and source as their marker instead of a prefix,
	 * and are seeded as one set: when any demo row exists, none is written.
	 *
	 * @param array<string, mixed> $search The seed file's `search` section.
	 *
	 * @return array{rows: int, targets: int, skipped: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(array $search): array {
		$counts = ['rows' => 0, 'targets' => 0, 'skipped' => 0];
		$property = (string)($search['property'] ?? '');
		$source = (string)($search['source'] ?? '');
		if ($property === '' || $source === '') {
			return $counts;
		}

		[$rowSchema, $targetSchema] = $this->schemas();
		$now = gmdate('Y-m-d\TH:i:s\Z');

		// The rows are seeded as one set: when any demo row exists, none is written.
		$rowsSeeded = ($this->demoRows(schemaSlug: $rowSchema, property: $property, source: $source) !== []);
		if ($rowsSeeded === true) {
			$counts['skipped']++;
		}

		if ($rowsSeeded === false) {
			foreach (($search['rows'] ?? []) as $row) {
				$payload = $this->rowPayload(row: $row, property: $property, source: $source, importedAt: $now);
				if ($this->store->save(schemaSlug: $rowSchema, payload: $payload) !== null) {
					$counts['rows']++;
				}
			}
		}

		$targets = $this->seedTargets(
			definitions: ($search['targets'] ?? []),
			schemaSlug: $targetSchema,
			property: $property,
			createdAt: $now,
		);
		$counts['targets'] = $targets['created'];
		$counts['skipped'] += $targets['skipped'];

		return $counts;
	}//end seed()

	/**
	 * Remove the demo Search Console rows and keyword targets.
	 *
	 * A row goes only when it carries both the demo property and the demo
	 * source, and a target only when it carries the demo property and a note
	 * with the demo marker, so a real import or a real target is never touched.
	 *
	 * @param array<string, mixed> $search The seed file's `search` section.
	 *
	 * @return array{rows: int, targets: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(array $search): array {
		$counts = ['rows' => 0, 'targets' => 0];
		$property = (string)($search['property'] ?? '');
		$source = (string)($search['source'] ?? '');
		if ($property === '' || $source === '') {
			return $counts;
		}

		[$rowSchema, $targetSchema] = $this->schemas();

		foreach ($this->demoRows(schemaSlug: $rowSchema, property: $property, source: $source) as $row) {
			if ($this->store->delete(schemaSlug: $rowSchema, id: $this->store->idOf(payload: $row)) === true) {
				$counts['rows']++;
			}
		}

		foreach ($this->store->findAll(schemaSlug: $targetSchema) as $target) {
			if ((string)($target['property'] ?? '') !== $property
				|| str_starts_with((string)($target['notes'] ?? ''), DemoSeedService::DEMO_PREFIX) === false
			) {
				continue;
			}

			if ($this->store->delete(schemaSlug: $targetSchema, id: $this->store->idOf(payload: $target)) === true) {
				$counts['targets']++;
			}
		}

		return $counts;
	}//end remove()

	/**
	 * One seed row as the import writes it, with its click rate derived.
	 *
	 * @param array<string, mixed> $row The seed-file row.
	 * @param string $property The demo property.
	 * @param string $source The demo source.
	 * @param string $importedAt When the demo import ran.
	 *
	 * @return array<string, mixed> The row payload.
	 */
	private function rowPayload(array $row, string $property, string $source, string $importedAt): array {
		$row = $this->values->resolvePlaceholders(data: $row);
		$clicks = (int)($row['clicks'] ?? 0);
		$impressions = (int)($row['impressions'] ?? 0);
		$ctr = 0.0;
		if ($impressions > 0) {
			$ctr = round(($clicks / $impressions), 4);
		}

		return [
			'property' => $property,
			'date' => (string)($row['date'] ?? ''),
			'query' => (string)($row['query'] ?? ''),
			'page' => (string)($row['page'] ?? ''),
			'clicks' => $clicks,
			'impressions' => $impressions,
			'ctr' => $ctr,
			'position' => (float)($row['position'] ?? 0),
			'source' => $source,
			'importedAt' => $importedAt,
		];
	}//end rowPayload()

	/**
	 * Seed the demo keyword targets, skipping one whose note already exists.
	 *
	 * @param array<int, array<string, mixed>> $definitions The `search.targets` definitions.
	 * @param string $schemaSlug The keyword target schema.
	 * @param string $property The demo property.
	 * @param string $createdAt When the targets are created.
	 *
	 * @return array{created: int, skipped: int} Counts.
	 */
	private function seedTargets(array $definitions, string $schemaSlug, string $property, string $createdAt): array {
		$result = ['created' => 0, 'skipped' => 0];

		$notes = [];
		foreach ($this->store->findAll(schemaSlug: $schemaSlug) as $target) {
			$notes[(string)($target['notes'] ?? '')] = true;
		}

		foreach ($definitions as $definition) {
			$data = ($definition['data'] ?? []);
			if (isset($notes[(string)($data['notes'] ?? '')]) === true) {
				$result['skipped']++;
				continue;
			}

			$data['property'] = $property;
			$data['createdAt'] = $createdAt;
			if ($this->store->save(schemaSlug: $schemaSlug, payload: $data) !== null) {
				$result['created']++;
			}
		}

		return $result;
	}//end seedTargets()

	/**
	 * The Search Console row and keyword target schema slugs, as their services resolve them.
	 *
	 * @return array{0: string, 1: string} The two slugs.
	 */
	private function schemas(): array {
		return [
			$this->store->schemaSlug(SearchQueryDailyStore::SCHEMA . '_schema', SearchQueryDailyStore::SCHEMA),
			$this->store->schemaSlug(KeywordTargetService::SCHEMA_CONFIG_KEY, KeywordTargetService::SCHEMA_SLUG),
		];
	}//end schemas()

	/**
	 * The Search Console rows carrying both the demo property and the demo source.
	 *
	 * Filtered on the server, because a real install can hold far more imported
	 * rows than a whole-schema scan should read; the store re-checks every
	 * filter on the returned rows, so a match is exact either way.
	 *
	 * @param string $schemaSlug The row schema.
	 * @param string $property The demo property.
	 * @param string $source The demo source.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function demoRows(string $schemaSlug, string $property, string $source): array {
		return $this->store->findAll(schemaSlug: $schemaSlug, filters: ['property' => $property, 'source' => $source]);
	}//end demoRows()
}//end class
