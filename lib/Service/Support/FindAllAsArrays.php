<?php

/**
 * Pipelinq FindAllAsArrays.
 *
 * An OpenRegister query's rows as arrays, or none with a logged warning.
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
 * Runs findAll() on the host's ObjectService and converts each row with the
 * host's toArray(); a failed query logs the given warning and answers no
 * rows. The host class provides a LoggerInterface `$logger` property and
 * getObjectService() and toArray() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait FindAllAsArrays {

	/**
	 * Run a query and answer its rows as arrays.
	 *
	 * @param array<string, mixed> $config  The findAll() configuration.
	 * @param string               $failure Warning logged when the query fails.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findAllAsArrays(array $config, string $failure): array {
		try {
			$results = $this->getObjectService()->findAll(config: $config);
		} catch (\Throwable $e) {
			$this->logger->warning($failure, ['exception' => $e->getMessage()]);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(value: $result);
		}

		return $objects;
	}//end findAllAsArrays()
}//end trait
