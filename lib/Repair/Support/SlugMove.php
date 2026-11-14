<?php

/**
 * Pipelinq SlugMove.
 *
 * The slug a rename moves away from and the slug it moves to.
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
 * The slug a schema rename moves away from and the slug it moves to.
 *
 * @spec exclude No canonical spec covers the schema slug migrations. Pointing this
 *  at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
final class SlugMove {
	/**
	 * Constructor.
	 *
	 * @param string $from The slug being moved away from.
	 * @param string $to   The slug the schema gets.
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
