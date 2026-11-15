<?php

/**
 * Pipelinq ContainerObjectSave.
 *
 * One object's data saved through the container's ObjectService.
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
 * Saves one object's data by uuid, logging PERSIST_FAILURE_MESSAGE when the
 * save fails. The host class provides ContainerInterface `$container` and
 * LoggerInterface `$logger` properties and a PERSIST_FAILURE_MESSAGE constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ContainerObjectSave {

	/**
	 * Persist an object's mutated data back to OpenRegister.
	 *
	 * @param string               $register The register id.
	 * @param string               $schema   The schema id.
	 * @param string               $uuid     The object UUID.
	 * @param array<string, mixed> $data     The mutated data.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveObjectData(string $register, string $schema, string $uuid, array $data): void {
		$this->savedVia(
			uuid: $uuid,
			save: static fn (object $objectService): mixed => $objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			)
		);
	}//end saveObjectData()

	/**
	 * Run a save on OpenRegister's ObjectService, logging PERSIST_FAILURE_MESSAGE when it fails.
	 *
	 * @param string   $uuid The object UUID, for the log.
	 * @param \Closure $save Saves on the ObjectService it is given.
	 *
	 * @return void
	 */
	private function savedVia(string $uuid, \Closure $save): void {
		try {
			$save($this->container->get('OCA\OpenRegister\Service\ObjectService'));
		} catch (Throwable $e) {
			$this->logger->warning(
				self::PERSIST_FAILURE_MESSAGE,
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end savedVia()
}//end trait
