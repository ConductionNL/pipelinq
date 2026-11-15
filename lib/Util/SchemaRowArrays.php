<?php

/**
 * Pipelinq SchemaRowArrays.
 *
 * The rows of the host's schema that match a filter, as arrays.
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

/**
 * Reads matching rows through {@see SchemaRowQuery} and converts each with
 * the host's toArray(); no ObjectService or a failed read is an empty list.
 * The host class provides getObjectService() and toArray() steps on top of
 * what SchemaRowQuery needs.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SchemaRowArrays {
	use SchemaRowQuery;

	/**
	 * Rows of the host's schema matching the filters, as arrays.
	 *
	 * @param array<string, mixed> $filters Filters ahead of the register and schema.
	 * @param string               $failure Warning logged when the read fails.
	 * @param array<string, mixed> $context Log context ahead of the error message.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function schemaRowArrays(array $filters, string $failure, array $context): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->findSchemaRows(objectService: $objectService, filters: $filters, failure: $failure, context: $context);

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end schemaRowArrays()
}//end trait
