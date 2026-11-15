<?php

/**
 * Pipelinq SerialisedRecord.
 *
 * A stored record as an array, cast from its serialisation.
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
 * Arrays pass through; a record with jsonSerialize() is cast from what it
 * serialises to; anything else is an empty array.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SerialisedRecord {

	/**
	 * Coerce an OpenRegister record (array or entity) to a plain array.
	 *
	 * @param mixed $rec Record from ObjectService::findAll()/find().
	 *
	 * @return array<string, mixed> Array representation (empty when unusable).
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function recordToArray(mixed $rec): array {
		if (is_array($rec) === true) {
			return $rec;
		}

		if (method_exists($rec, 'jsonSerialize') === true) {
			return (array)$rec->jsonSerialize();
		}

		return [];
	}//end recordToArray()
}//end trait
