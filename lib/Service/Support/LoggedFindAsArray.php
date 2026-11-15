<?php

/**
 * Pipelinq LoggedFindAsArray.
 *
 * Reads one object as an array, reporting a failed read to the host.
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
use Throwable;

/**
 * One read object as an array through the host's toArray(value:) step; a
 * failed read goes to the host's failure callback and answers null, as a
 * miss does.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait LoggedFindAsArray {

	/**
	 * Run a single-object read and answer it as an array, or null.
	 *
	 * OpenRegister's find() may return null on a miss rather than throw
	 * (ADR-084); a null is answered as null, never normalised to `[]`, so
	 * callers can tell "no such object" from "an empty object".
	 *
	 * @param Closure $find      The read, returning the object or null.
	 * @param Closure $onFailure Called with the Throwable of a failed read.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function loggedFindAsArray(Closure $find, Closure $onFailure): ?array {
		try {
			$entity = $find();
		} catch (Throwable $e) {
			$onFailure($e);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loggedFindAsArray()
}//end trait
