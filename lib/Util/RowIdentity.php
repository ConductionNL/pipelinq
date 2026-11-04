<?php

/**
 * Pipelinq RowIdentity.
 *
 * Reads the identity of an OpenRegister row.
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
 * A row's identity is its `@self` id, its `@self` uuid, its id or its uuid, in that order.
 */
trait RowIdentity {
	/**
	 * The row's identity.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The identity, or null when it has none.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function rowIdentity(array $row): ?string {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
			if ($id !== null) {
				return (string) $id;
			}
		}

		$id = ($row['id'] ?? $row['uuid'] ?? null);
		if ($id === null) {
			return null;
		}

		return (string) $id;
	}//end rowIdentity()
}//end trait
