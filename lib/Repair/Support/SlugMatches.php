<?php

/**
 * Pipelinq SlugMatches.
 *
 * The schema rows found under a rename's old and new slug.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair\Support
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

namespace OCA\Pipelinq\Repair\Support;

/**
 * The ids found under the slug a rename moves away from and the slug it moves to;
 * null for a lookup the schema table could not answer.
 *
 * @spec exclude No canonical spec covers the schema slug migrations. Pointing this
 *  at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
final class SlugMatches {
	/**
	 * Constructor.
	 *
	 * @param array<int, mixed>|null $old Ids under the old slug, or null when unreadable.
	 * @param array<int, mixed>|null $new Ids under the new slug, or null when unreadable.
	 */
	public function __construct(
		public readonly ?array $old,
		public readonly ?array $new,
	) {
	}//end __construct()

	/**
	 * Whether either lookup failed.
	 *
	 * @return bool True when the schema table could not be read.
	 *
	 * @spec exclude No canonical spec covers the schema slug migrations.
	 */
	public function isUnreadable(): bool {
		return $this->old === null || $this->new === null;
	}//end isUnreadable()

	/**
	 * The first id under the old slug, the one a rename moves.
	 *
	 * @return mixed The schema id, or null when there is none.
	 *
	 * @spec exclude No canonical spec covers the schema slug migrations.
	 */
	public function oldId(): mixed {
		return ($this->old[0] ?? null);
	}//end oldId()
}//end class
