<?php

/**
 * Pipelinq AnyPayloadToArray.
 *
 * Turns any OpenRegister read result into a plain array.
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
 * Like {@see OptionalPayloadToArray}, but an object without a payload gives its
 * toArray() array or else is cast to an array.
 */
trait AnyPayloadToArray {
	use ObjectToPlainArray;
	use EntityPayload;

	/**
	 * The value as an array.
	 *
	 * @param mixed $object The read result.
	 *
	 * @return array<string|int, mixed>|null
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function toArray(mixed $object): ?array {
		$payload = $this->entityPayload(value: $object);
		if ($payload !== null || is_object($object) === false) {
			return $payload;
		}

		return $this->objectToPlainArray(object: $object);
	}//end toArray()
}//end trait
