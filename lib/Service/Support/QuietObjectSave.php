<?php

/**
 * Pipelinq QuietObjectSave.
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
 * Saves through the host's ObjectService into the host's register and the
 * given schema; no ObjectService skips the save, a failed save logs the
 * given warning with the id. The host class provides a LoggerInterface
 * `$logger` property and getObjectService() and getRegisterSlug() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait QuietObjectSave {

	/**
	 * Persist a row, logging a failed save.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string               $schema  The schema slug.
	 * @param string               $id      The row's id.
	 * @param string               $failure Warning logged when the save fails.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function saveQuietly(array $payload, string $schema, string $id, string $failure): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning($failure, ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}//end saveQuietly()
}//end trait
