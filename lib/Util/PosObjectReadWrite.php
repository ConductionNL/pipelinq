<?php

/**
 * Pipelinq PosObjectReadWrite.
 *
 * Fetch-or-404 and save steps over a POS schema.
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

use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Reads one object (OCS not-found when absent) and saves one object by id
 * against the register and schema that the host's config() resolves. The host
 * class provides config(), getObjectService() and toArray().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosObjectReadWrite {

	/**
	 * Fetch a single object by UUID for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID.
	 * @param string $label The not-found error message.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws OCSNotFoundException If the object is not found.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function fetchOne(string $schemaKey, string $id, string $label): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}//end fetchOne()

	/**
	 * Persist an object via the OR ObjectService for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID (empty to create).
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveObjectFor(string $schemaKey, string $id, array $object): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveObjectFor()
}//end trait
