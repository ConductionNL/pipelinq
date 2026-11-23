<?php

/**
 * Pipelinq ContainerObjectFind.
 *
 * One object's data read through the container's ObjectService.
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
use Throwable;

/**
 * Reads one object's data by uuid, logging FETCH_FAILURE_MESSAGE when the
 * read fails. The host class provides ContainerInterface `$container` and
 * LoggerInterface `$logger` properties and a FETCH_FAILURE_MESSAGE constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ContainerObjectFind {

	/**
	 * Read an object's current data.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 * @param string $uuid     The object UUID.
	 *
	 * @return array<string, mixed>|null Object data, or null when gone or unreadable.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findObjectData(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, options: new ObjectFindOptions(register: $register, schema: $schema));
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (Throwable $e) {
			$this->logger->warning(
				self::FETCH_FAILURE_MESSAGE,
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end findObjectData()
}//end trait
