<?php

/**
 * Pipelinq SerialisedEntity.
 *
 * An OpenRegister entity as the array it serialises to.
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
 * A missing entity, or one that does not serialise to an array, is null.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SerialisedEntity {

	/**
	 * An entity's serialised data, or null when there is none.
	 *
	 * @param mixed $entity The entity, or null.
	 *
	 * @return array<string, mixed>|null The data, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function serialisedEntity(mixed $entity): ?array {
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end serialisedEntity()
}//end trait
