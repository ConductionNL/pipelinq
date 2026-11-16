<?php

/**
 * Pipelinq SchemaRowArrays.
 *
 * The rows of the host's schema that match a filter, as arrays.
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

use OCA\Pipelinq\Util\SchemaRowQuery;

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
		return $this->rowArraysOrLog(filters: $filters, log: ['failure' => $failure, 'context' => $context]);
	}//end schemaRowArrays()

	/**
	 * Rows of the host's schema matching the filters, as arrays; none when OpenRegister is unavailable.
	 *
	 * @param array<string, mixed>                                $filters Filters ahead of the register and schema.
	 * @param array{failure: string, context: array<string, mixed>} $log     The warning logged when the read fails, and its context.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function rowArraysOrLog(array $filters, array $log): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$rows = $this->readSchemaRowsOrLog(read: ['objectService' => $objectService, 'filters' => $filters], log: $log);

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end rowArraysOrLog()
}//end trait
