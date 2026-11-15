<?php

/**
 * Pipelinq PosObjectUpsert.
 *
 * A POS object saved under its id, or under a fresh UUID when it has none.
 *
 * @category Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

/**
 * Saves a payload into the register and schema that the host's config()
 * resolves, under the given id or a freshly generated UUID. The host class
 * provides config(), uuid(), getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosObjectUpsert {

	/**
	 * Create or update one object of a schema config key.
	 *
	 * @param string               $schemaKey The app-config schema key.
	 * @param string               $id        The object id, empty to create.
	 * @param array<string, mixed> $payload   The object data.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function upsertObject(string $schemaKey, string $id, array $payload): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$uuid = $id;
		if ($uuid === '') {
			$uuid = $this->uuid();
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}//end upsertObject()
}//end trait
