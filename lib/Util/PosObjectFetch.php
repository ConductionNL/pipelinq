<?php

/**
 * Pipelinq PosObjectFetch.
 *
 * Fetch-or-404 over a POS schema.
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
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Reads one object (OCS not-found when absent or unreadable) against the
 * register and schema that the host's config() resolves. The host class
 * provides config(), getObjectService() and toArray().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosObjectFetch {

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
			$object = $this->getObjectService()->find(id: $id, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}//end fetchOne()
}//end trait
