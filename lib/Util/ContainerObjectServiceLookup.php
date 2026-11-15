<?php

/**
 * Pipelinq ContainerObjectServiceLookup.
 *
 * The OpenRegister ObjectService from the container, or null with a logged warning.
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
 * Fetches the ObjectService from the container on each call; when that fails
 * it logs the given warning with the error message under the given context
 * key and answers null. The host class provides ContainerInterface
 * `$container` and LoggerInterface `$logger` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ContainerObjectServiceLookup {

	/**
	 * Resolve the OpenRegister ObjectService, or null when it is unavailable.
	 *
	 * @param string $failure  Warning logged when the container cannot supply it.
	 * @param string $errorKey Log-context key that carries the error message.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function objectServiceOrNull(string $failure, string $errorKey): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning($failure, [$errorKey => $e->getMessage()]);
			return null;
		}
	}//end objectServiceOrNull()
}//end trait
