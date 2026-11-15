<?php

/**
 * Pipelinq NullablePayloadToArray.
 *
 * Turns an OpenRegister read result into a plain array.
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
 * Null stays null, an array stays as it is, and an entity becomes its
 * jsonSerialize() array, else its toArray() array, else its public
 * properties. Any other value becomes null.
 */
trait NullablePayloadToArray {
	use ObjectToPlainArray;
	use SerialisedPayload;

	/**
	 * Normalise an OpenRegister entity or array to a plain array.
	 *
	 * @param mixed $object Entity object, array or null.
	 *
	 * @return array<string, mixed>|null Plain payload, or null for a non-object.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function toArray(mixed $object): ?array {
		$serialised = $this->serialisedPayload(value: $object);
		if ($serialised !== null) {
			return $serialised;
		}

		if (is_object($object) === false) {
			return null;
		}

		return $this->objectToPlainArray(object: $object);
	}//end toArray()
}//end trait
