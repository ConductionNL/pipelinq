<?php

/**
 * Pipelinq PageRequest.
 *
 * The page of a listing a request asks for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A 1-based page number and a page size.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class PageRequest {
	/**
	 * Constructor.
	 *
	 * @param int $page  The 1-based requested page.
	 * @param int $limit The page size.
	 */
	public function __construct(
		public readonly int $page,
		public readonly int $limit,
	) {
	}//end __construct()
}//end class
