<?php

/**
 * Pipelinq SlugRenameRun.
 *
 * One slug of the colliding-slug namespacing pass, while it is renamed.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Repair\Support\SlugMove;
use OCP\Migration\IOutput;

/**
 * The repair output and the slug move, for the steps of one
 * RenameCollidingSchemaSlugs rename.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
final class SlugRenameRun {
	/**
	 * Constructor.
	 *
	 * @param IOutput  $output Repair output.
	 * @param SlugMove $move   The slug being moved and the namespaced slug.
	 */
	public function __construct(
		public readonly IOutput $output,
		public readonly SlugMove $move,
	) {
	}//end __construct()
}//end class
