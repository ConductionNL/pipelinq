<?php

/**
 * Pipelinq ObjectToPlainArray.
 *
 * Turns an object without a payload into a plain array.
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
 * An object gives its toArray() array or else is cast to an array.
 */
trait ObjectToPlainArray {
	/**
	 * The object as an array.
	 *
	 * @param object $object The object.
	 *
	 * @return array<string|int, mixed>
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function objectToPlainArray(object $object): array {
		$array = null;
		if (method_exists($object, 'toArray') === true) {
			$array = $object->toArray();
		}

		return is_array($array) === true ? $array : (array) $object;
	}//end objectToPlainArray()
}//end trait
