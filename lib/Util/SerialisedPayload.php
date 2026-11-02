<?php

/**
 * Pipelinq SerialisedPayload.
 *
 * Reads the array an OpenRegister read result already carries.
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
 * An array stays as it is, and an object answers with its jsonSerialize()
 * array. Anything else has no serialised payload.
 */
trait SerialisedPayload {
	/**
	 * The array a value carries, or null when it carries none.
	 *
	 * @param mixed $value Entity, array or unknown payload.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function serialisedPayload(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;
	}//end serialisedPayload()
}//end trait
