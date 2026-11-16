<?php

/**
 * Pipelinq AppointmentServiceLookup.
 *
 * One appointment Service object by id, or null with a logged warning.
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


use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
/**
 * Reads a Service from the configured register and 'service_schema'; an
 * unconfigured register or schema or an empty id is null, a failed read logs
 * the given warning. The host class provides a LoggerInterface `$logger`
 * property and registerId(), schemaId(), getObjectService() and toArray()
 * steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait AppointmentServiceLookup {

	/**
	 * Load a Service by id without throwing.
	 *
	 * @param string $serviceId Service UUID/slug.
	 * @param string $failure   Warning logged when the read fails.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findServiceObject(string $serviceId, string $failure): ?array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'service_schema');
		if ($register === '' || $schema === '' || $serviceId === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				options: new ObjectFindOptions(register: $register, schema: $schema)
			);
		} catch (\Throwable $e) {
			$this->logger->warning($failure, ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}//end findServiceObject()
}//end trait
