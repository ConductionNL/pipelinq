<?php

/**
 * Pipelinq EntityPayload.
 *
 * Reads the payload of an OpenRegister read result.
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
 * An array stays as it is, an entity gives its jsonSerialize() array or else
 * its getObject() array, and anything else gives null.
 */
trait EntityPayload {
	use SerialisedPayload;

	/**
	 * The value's payload.
	 *
	 * @param mixed $value The read result.
	 *
	 * @return array<string, mixed>|null The payload, or null when it has none.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function entityPayload(mixed $value): ?array {
		$serialised = $this->serialisedPayload(value: $value);
		if ($serialised !== null) {
			return $serialised;
		}

		if (is_object($value) === false || method_exists($value, 'getObject') === false) {
			return null;
		}

		$payload = $value->getObject();
		if (is_array($payload) === true) {
			return $payload;
		}

		return null;
	}//end entityPayload()
}//end trait
