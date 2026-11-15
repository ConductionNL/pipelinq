<?php

/**
 * Pipelinq ObjectArrayReads.
 *
 * Turns OpenRegister reads into plain arrays.
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

use Closure;

/**
 * One read object, or a list of found objects, as arrays through the host's
 * toArray(object:) step.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ObjectArrayReads {

	/**
	 * Run a single-object read and answer it as an array; a failed read or a miss is null.
	 *
	 * @param Closure $find The read, returning the object or null.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function foundAsArray(Closure $find): ?array {
		try {
			$object = $find();
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end foundAsArray()

	/**
	 * Every found object as an array; null is an empty list.
	 *
	 * @param iterable<mixed>|null $results The found objects.
	 *
	 * @return array<int, array<string, mixed>> The objects as arrays.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function allAsArrays(?iterable $results): array {
		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end allAsArrays()
}//end trait
