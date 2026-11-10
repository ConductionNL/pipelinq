<?php

/**
 * Pipelinq DateBounds.
 *
 * The optional inclusive date bounds of an activity timeline.
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
 * An optional lower and upper date bound, both inclusive.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class DateBounds {
	/**
	 * Constructor.
	 *
	 * @param string|null $from The lower date bound, or null.
	 * @param string|null $to   The upper date bound, or null.
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}//end __construct()
}//end class
