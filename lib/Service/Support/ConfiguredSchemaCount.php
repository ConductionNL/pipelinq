<?php

/**
 * Pipelinq ConfiguredSchemaCount.
 *
 * The number of objects in a configured schema that match a filter.
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
 * Counts up to 1000 matching objects against the [register, schema] the
 * host's config() resolves; an unconfigured schema or a failed read counts as
 * 0. The host class provides config() and getObjectService() steps.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ConfiguredSchemaCount {

	/**
	 * Count objects in a schema matching the filters.
	 *
	 * @param string               $schemaKey AppConfig key for the schema id.
	 * @param array<string, mixed> $filters   Filters ahead of the register and schema.
	 *
	 * @return int
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function countMatching(string $schemaKey, array $filters): int {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => $filters + [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		if (is_array($rows) === true) {
			return count($rows);
		}

		return 0;
	}//end countMatching()
}//end trait
