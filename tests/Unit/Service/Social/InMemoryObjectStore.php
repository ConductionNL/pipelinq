<?php

/**
 * An in-memory `ListObjectStore` for the social publishing tests.
 *
 * A real subclass rather than a mock returning canned rows, for the same
 * reason `ArticleServiceTest` uses one: every rule under test reads back what
 * the step before it WROTE. A publish reads the publication row it opened, a
 * settle reads the publications the publish recorded, and a second approval
 * reads the first one. A mock would agree with the caller whatever the service
 * actually stored, which is precisely the class of test that cannot fail.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * Rows in memory, keyed by schema and id.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class InMemoryObjectStore extends ListObjectStore {
	/**
	 * The rows, keyed by schema slug and then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * The id counter.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * The schema slug, ignoring any app-config override.
	 *
	 * @param string $configKey Ignored.
	 * @param string $default The slug.
	 *
	 * @return string The slug.
	 */
	public function schemaSlug(string $configKey, string $default): string {
		return $default;
	}

	/**
	 * One row, or null.
	 *
	 * @param string $schemaSlug The schema.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function find(string $schemaSlug, string $id): ?array {
		return ($this->rows[$schemaSlug][$id] ?? null);
	}

	/**
	 * Every row matching the filters.
	 *
	 * @param string $schemaSlug The schema.
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$out = [];
		foreach (($this->rows[$schemaSlug] ?? []) as $row) {
			foreach ($filters as $field => $value) {
				if ((string)($row[$field] ?? '') !== (string)$value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Store a row, minting an id when it has none.
	 *
	 * @param string $schemaSlug The schema.
	 * @param array<string, mixed> $payload The payload.
	 * @param string|null $id The existing id.
	 *
	 * @return array<string, mixed>|null The stored row.
	 */
	public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
		$key = $id;
		if ($key === null || $key === '') {
			$this->sequence++;
			$key = $schemaSlug . '-' . $this->sequence;
		}

		$payload['id'] = $key;
		$this->rows[$schemaSlug][$key] = $payload;

		return $payload;
	}

	/**
	 * Seed one row directly, without going through the service under test.
	 *
	 * @param string $schemaSlug The schema.
	 * @param string $id The id to give it.
	 * @param array<string, mixed> $payload The row.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function seed(string $schemaSlug, string $id, array $payload): array {
		$payload['id'] = $id;
		$this->rows[$schemaSlug][$id] = $payload;

		return $payload;
	}
}//end class
