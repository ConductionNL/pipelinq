<?php

/**
 * Pipelinq CastingSerialisedToArray.
 *
 * Turns OpenRegister read results into plain arrays, casting plain objects.
 *
 * @category Service
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Util\SerialisedPayload;

/**
 * An array or jsonSerialize() payload as it is, any other object cast to an
 * array, anything else an empty array; and a result list mapped that way.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait CastingSerialisedToArray {
	use SerialisedPayload;

	/**
	 * Normalise an OpenRegister entity (object or array) to a plain array.
	 *
	 * @param mixed $object The object or array returned by ObjectService.
	 *
	 * @return array<string, mixed> Plain associative array.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function toArray(mixed $object): array {
		$serialised = $this->serialisedPayload(value: $object);
		if ($serialised === null && is_object($object) === true) {
			return (array)$object;
		}

		return ($serialised ?? []);
	}//end toArray()

	/**
	 * Every result of a findAll as a plain array.
	 *
	 * @param mixed $results The findAll results.
	 *
	 * @return array<int, array<string, mixed>> Plain object arrays.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function resultsToArrays(mixed $results): array {
		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}//end resultsToArrays()
}//end trait
