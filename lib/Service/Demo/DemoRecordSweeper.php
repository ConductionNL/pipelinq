<?php

/**
 * Pipelinq DemoRecordSweeper.
 *
 * Removes stored example records that still match the descriptor.
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
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Finds the descriptor's records by schema and slug in the pipelinq register
 * and removes those whose name still matches: a record somebody has since
 * renamed is theirs now. Append-only schemas keep their rows until the
 * retention cron expires them.
 *
 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoRecordSweeper {
	/**
	 * Fields that name a record, checked in order, to confirm a slug match.
	 *
	 * @var array<int, string>
	 */
	private const LABEL_FIELDS = ['name', 'title', 'label', 'displayName', 'reference', 'subject', 'code'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig     Holds the provisioned register id.
	 * @param SchemaMapper  $schemaMapper  Resolves a schema slug to its id.
	 * @param ObjectService $objectService Finds and deletes stored records.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Remove the stored records that match the descriptor's records.
	 *
	 * @param array<int, array<string, mixed>> $objects The descriptor's records.
	 *
	 * @return array{removed: int, retained: int}|null Null when no register is provisioned.
	 *
	 * @spec openspec/changes/example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function removeMatching(array $objects): ?array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return null;
		}

		$counts = ['removed' => 0, 'retained' => 0];
		foreach ($this->bySchema(objects: $objects) as $schemaSlug => $wanted) {
			$scope               = $this->scope(registerId: $registerId, schemaSlug: (string) $schemaSlug);
			$found               = $this->removeFromSchema(scope: $scope, wanted: $wanted);
			$counts['removed']  += $found['removed'];
			$counts['retained'] += $found['retained'];
		}

		return $counts;
	}//end removeMatching()

	/**
	 * The descriptor's records keyed by schema slug, then by record slug.
	 *
	 * @param array<int, array<string, mixed>> $objects The descriptor's records.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function bySchema(array $objects): array {
		$bySchema = [];
		foreach ($objects as $object) {
			$bySchema[(string) $object['@self']['schema']][(string) $object['@self']['slug']] = $object;
		}

		return $bySchema;
	}//end bySchema()

	/**
	 * The register and schema ids that select a schema's records, or null
	 * when this instance never had that schema (and so holds none of them).
	 *
	 * @param string $registerId The pipelinq register id.
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function scope(string $registerId, string $schemaSlug): ?array {
		try {
			$schema = $this->schemaMapper->find($schemaSlug, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		return ['register' => $registerId, 'schema' => (string) $schema->getId()];
	}//end scope()

	/**
	 * Delete the records of one schema whose slug and name match the descriptor;
	 * none for a schema this instance never had.
	 *
	 * @param array{register: string, schema: string}|null $scope  The register and schema ids.
	 * @param array<string, array<string, mixed>>           $wanted Descriptor records keyed by slug.
	 *
	 * @return array{removed: int, retained: int}
	 */
	private function removeFromSchema(?array $scope, array $wanted): array {
		$counts = ['removed' => 0, 'retained' => 0];
		if ($scope === null) {
			return $counts;
		}

		$rows = $this->objectService->findAll(['filters' => $scope, 'limit' => 5000]);
		foreach ((array) $rows as $row) {
			$data = $this->rowData(row: $row);
			if ($this->isWanted(found: $data, wanted: $wanted) === true) {
				$counts[$this->delete(scope: $scope, data: $data)]++;
			}
		}

		return $counts;
	}//end removeFromSchema()

	/**
	 * A stored row as data.
	 *
	 * @param mixed $row The row: an entity or an array.
	 *
	 * @return mixed
	 */
	private function rowData(mixed $row): mixed {
		if ($row instanceof \JsonSerializable === true) {
			return $row->jsonSerialize();
		}

		return $row;
	}//end rowData()

	/**
	 * Whether a stored record is one of the descriptor's, under the same name.
	 *
	 * @param mixed                               $found  The stored record.
	 * @param array<string, array<string, mixed>> $wanted Descriptor records keyed by slug.
	 *
	 * @return bool
	 */
	private function isWanted(mixed $found, array $wanted): bool {
		$slug = (string) ($found['@self']['slug'] ?? '');
		return isset($wanted[$slug]) === true && $this->sameLabel(found: $found, wanted: $wanted[$slug]) === true;
	}//end isWanted()

	/**
	 * Delete one record; an append-only schema keeps it until retention expires it.
	 *
	 * @param array{register: string, schema: string} $scope The register and schema ids.
	 * @param array<string, mixed>                    $data  The stored record.
	 *
	 * @return string `removed` or `retained`.
	 *
	 * @throws \Throwable When the delete fails for any other reason.
	 */
	private function delete(array $scope, array $data): string {
		try {
			$this->objectService->deleteObject((string) $data['id'], $scope['register'], $scope['schema']);
			return 'removed';
		} catch (\Throwable $e) {
			if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === false) {
				throw $e;
			}

			return 'retained';
		}
	}//end delete()

	/**
	 * Whether a found record still carries the descriptor's name.
	 *
	 * A record without any naming field matches on slug alone.
	 *
	 * @param array<string, mixed> $found  The stored record.
	 * @param array<string, mixed> $wanted The descriptor record.
	 *
	 * @return bool True when the first naming field the descriptor sets is unchanged.
	 */
	private function sameLabel(array $found, array $wanted): bool {
		foreach (self::LABEL_FIELDS as $field) {
			if (isset($wanted[$field]) === true && is_scalar($wanted[$field]) === true) {
				return (string) ($found[$field] ?? '') === (string) $wanted[$field];
			}
		}

		return true;
	}//end sameLabel()
}//end class
