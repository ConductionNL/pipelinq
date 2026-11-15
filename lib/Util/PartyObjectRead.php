<?php

/**
 * Pipelinq PartyObjectRead.
 *
 * One OpenRegister object read by id, as the array it serialises to.
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

use Throwable;

/**
 * Reads an object by id through the host's injected ObjectService; a failed
 * read is logged at debug level and is null, as is a miss or an entity that
 * does not serialise to an array. The host class provides
 * ObjectServiceInterface `$objectService` and LoggerInterface `$logger`
 * properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PartyObjectRead {

	/**
	 * Read one object, logging a failed read.
	 *
	 * @param string $id      The object id.
	 * @param string $failure Debug line logged when the read fails.
	 * @param string $idKey   Log-context key that carries the id.
	 *
	 * @return array<string, mixed>|null The object data, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function readLogged(string $id, string $failure, string $idKey): ?array {
		try {
			$entity = $this->objectService->find(id: $id);
		} catch (Throwable $e) {
			$this->logger->debug($failure, [$idKey => $id, 'exception' => $e->getMessage()]);

			return null;
		}

		return $this->serialisedEntity(entity: $entity);
	}//end readLogged()

	/**
	 * An entity's serialised data, or null when there is none.
	 *
	 * @param mixed $entity The entity, or null.
	 *
	 * @return array<string, mixed>|null The data, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function serialisedEntity(mixed $entity): ?array {
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end serialisedEntity()
}//end trait
