<?php

/**
 * Pipelinq PayloadToArray.
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
 * An array stays as it is, an entity becomes its jsonSerialize() array or
 * else its getObject() array, and anything else becomes an empty array.
 */
trait PayloadToArray {
	use SerialisedPayload;

	/**
	 * Normalise an OpenRegister entity or array to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function toArray(mixed $value): array {
		$serialised = $this->serialisedPayload(value: $value);
		if ($serialised !== null) {
			return $serialised;
		}

		if (is_object($value) === false || method_exists($value, 'getObject') === false) {
			return [];
		}

		$payload = $value->getObject();
		if (is_array($payload) === true) {
			return $payload;
		}

		return [];
	}//end toArray()
}//end trait
