<?php

/**
 * Pipelinq LoggedObjectSave.
 *
 * One object saved by id in a given schema, a failure logged rather than thrown.
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

use Throwable;

/**
 * Saves through the host's ObjectService into a register and schema and
 * answers the saved row through the host's toArray(); a failed save is
 * logged as "<host class>.saveObject: save failed" and answers null. The
 * host class provides a LoggerInterface `$logger` property and
 * getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait LoggedObjectSave {

	/**
	 * Save a payload, logging (not throwing) a failed save.
	 *
	 * @param array<string, mixed>                                                $payload The payload.
	 * @param array{0: string, 1: string, 2: string|null, 3: array<string, mixed>} $target  The register, schema, uuid (null creates) and log context.
	 *
	 * @return array<string, mixed>|null The saved row, or null when there is no ObjectService or the save fails.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveLogged(array $payload, array $target): ?array {
		[$register, $schema, $uuid, $context] = $target;
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				substr(strrchr(self::class, '\\'), 1).'.saveObject: save failed',
				$context + ['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveLogged()
}//end trait
