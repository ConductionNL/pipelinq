<?php

/**
 * Pipelinq CastSerialisedToArray.
 *
 * An array, serialisable entity or OpenRegister entity cast to an array.
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
 * Arrays pass through; an object's jsonSerialize() or getObject() result is
 * cast to an array; anything else is an empty array.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait CastSerialisedToArray {

	/**
	 * Normalise an OpenRegister entity (or array) into a plain array.
	 *
	 * @param mixed $value The entity or array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				return (array)$value->jsonSerialize();
			}

			if (method_exists($value, 'getObject') === true) {
				return (array)$value->getObject();
			}
		}

		return [];
	}//end toArray()
}//end trait
