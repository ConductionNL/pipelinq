<?php

/**
 * Pipelinq ConfiguredObjectFind.
 *
 * One object read from a configured [register, schema], or null.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * Reads one object through the host's ObjectService from the given
 * [register, schema] ids and answers it through the host's toArray(); an
 * unconfigured id, an empty object id, a failed read or a miss is null. The
 * host class provides getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredObjectFind {

	/**
	 * Read one object from the configured register and schema.
	 *
	 * @param array{0: string, 1: string} $ids The [register, schema] ids.
	 * @param string                      $id  The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findConfigured(array $ids, string $id): ?array {
		[$register, $schema] = $ids;
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(value: $object);
	}//end findConfigured()
}//end trait
