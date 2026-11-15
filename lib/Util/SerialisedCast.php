<?php

/**
 * Pipelinq SerialisedCast.
 *
 * A row as an array, serialised when it can be.
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
 * A row with jsonSerialize() is cast from what it serialises to; any other
 * value is cast as it is.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SerialisedCast {

	/**
	 * A stored object as an array.
	 *
	 * @param mixed $object An array, or an entity that serialises to one.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return (array)$object;
	}//end asArray()
}//end trait
