<?php

/**
 * Pipelinq PosObjectReadWrite.
 *
 * Fetch-or-404 and save steps over a POS schema.
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

use OCA\Pipelinq\Util\PosObjectFetch;

/**
 * Reads one object through {@see PosObjectFetch} and saves one object by id
 * against the register and schema that the host's config() resolves. The host
 * class provides config(), getObjectService() and toArray().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosObjectReadWrite {
	use PosObjectFetch;

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
		return $this->savedInto(location: $this->config(schemaKey: $schemaKey), object: ['id' => $id, 'data' => $object]);
	}//end saveObjectFor()

	/**
	 * Persist an object into a register and schema under its UUID, without its `@self`.
	 *
	 * @param array{0: string, 1: string}                 $location The register and the schema.
	 * @param array{id: string, data: array<string, mixed>} $object   The object UUID (empty to create) and its data.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function savedInto(array $location, array $object): array {
		[$register, $schema] = $location;
		$id = $object['id'];
		$object = $object['data'];

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end savedInto()
}//end trait
