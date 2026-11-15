<?php

/**
 * Pipelinq SchemaRowQuery.
 *
 * The rows of the host's schema that match a filter, or null with a logged warning.
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
 * Queries the host's register and schema with extra filters ahead of them;
 * a failed read logs the given warning with the error under 'exception' and
 * answers null. The host class provides a LoggerInterface `$logger` property
 * and getRegisterSlug() and getSchemaSlug() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SchemaRowQuery {

	/**
	 * Rows of the host's schema matching the filters.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $filters       Filters ahead of the register and schema.
	 * @param string               $failure       Warning logged when the read fails.
	 * @param array<string, mixed> $context       Log context ahead of the error message.
	 *
	 * @return mixed The rows as OpenRegister returns them, or null when the read failed.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function findSchemaRows(object $objectService, array $filters, string $failure, array $context): mixed {
		try {
			return $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $this->getRegisterSlug(),
							'schema' => $this->getSchemaSlug(),
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning($failure, $context + ['exception' => $e->getMessage()]);
			return null;
		}
	}//end findSchemaRows()
}//end trait
