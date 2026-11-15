<?php

/**
 * Pipelinq ConfiguredObjectSave.
 *
 * A payload saved into a configured [register, schema], refused when either is unset.
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

use RuntimeException;

/**
 * Saves through the host's ObjectService into the given [register, schema]
 * ids and answers the saved row through the host's toArray(); an
 * unconfigured id refuses the save with a RuntimeException naming the
 * schema. The host class provides getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredObjectSave {

	/**
	 * Save a payload into the configured register and schema.
	 *
	 * @param array{0: string, 1: string} $ids     The [register, schema] ids.
	 * @param array<string, mixed>        $payload The payload.
	 * @param string|null                 $uuid    The object id, or null to create.
	 * @param string                      $label   The schema's name in the refusal.
	 *
	 * @return array<string, mixed> The saved row.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveConfigured(array $ids, array $payload, ?string $uuid, string $label): array {
		[$register, $schema] = $ids;
		if ($register === '' || $schema === '') {
			throw new RuntimeException($label.' schema is not configured.');
		}

		return $this->savedConfigured(ids: $ids, write: ['payload' => $payload, 'uuid' => $uuid]);
	}//end saveConfigured()

	/**
	 * Save a payload into a configured register and schema.
	 *
	 * @param array{0: string, 1: string}                           $ids   The [register, schema] ids, both set.
	 * @param array{payload: array<string, mixed>, uuid: ?string} $write The payload, and the object id or null to create.
	 *
	 * @return array<string, mixed> The saved row.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function savedConfigured(array $ids, array $write): array {
		[$register, $schema] = $ids;
		$saved = $this->getObjectService()->saveObject(
			object: $write['payload'],
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $write['uuid']
		);

		return $this->toArray(value: $saved);
	}//end savedConfigured()
}//end trait
