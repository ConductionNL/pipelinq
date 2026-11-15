<?php

/**
 * Pipelinq RegisterSchemaObjects.
 *
 * Lists and saves the objects of one register and schema.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

/**
 * Runs the host's ObjectService findAll() over a register and schema plus
 * extra equality filters or a given config, and its saveObject() into a
 * register and schema.
 * The host class provides getObjectService().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait RegisterSchemaObjects {

	/**
	 * Find the objects of a register and schema; what the ObjectService throws is not caught.
	 *
	 * @param array{0: string, 1: string} $ids     The [register, schema] ids.
	 * @param array<string, mixed>        $filters Extra equality filters, after register and schema.
	 *
	 * @return mixed The ObjectService result.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findAllIn(array $ids, array $filters = []): mixed {
		[$register, $schema] = $ids;
		return $this->getObjectService()->findAll(
			config: [
				'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
			]
		);
	}//end findAllIn()

	/**
	 * Find up to a limit of the objects of a register and schema; what the ObjectService throws is not caught.
	 *
	 * @param array{0: string, 1: string} $ids   The [register, schema] ids.
	 * @param int                         $limit The row limit.
	 *
	 * @return mixed The ObjectService result.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findPageIn(array $ids, int $limit): mixed {
		[$register, $schema] = $ids;
		return $this->getObjectService()->findAll(
			config: [
				'filters' => ['register' => $register, 'schema' => $schema],
				'limit' => $limit,
			]
		);
	}//end findPageIn()

	/**
	 * Save an object into a register and schema; what the ObjectService throws is not caught.
	 *
	 * @param array{0: string, 1: string, 2: string|null} $target The register, schema and object uuid (null or '' to create).
	 * @param array<string, mixed>                        $object The object data.
	 *
	 * @return mixed The saved object.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveIn(array $target, array $object): mixed {
		[$register, $schema, $uuid] = $target;
		return $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);
	}//end saveIn()

	/**
	 * Run a findAll and answer its rows as a list; none when the read fails or answers no list.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function rowList(array $config): array {
		try {
			$rows = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		return $rowList;
	}//end rowList()
}//end trait
