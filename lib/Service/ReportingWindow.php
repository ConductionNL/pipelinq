<?php

/**
 * Pipelinq ReportingWindow.
 *
 * The span of time a commercial figure or series counts.
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
 * @spec openspec/specs/commercial-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A window of unix timestamps with both bounds inclusive.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
final class ReportingWindow {
	/**
	 * Constructor.
	 *
	 * @param int $startTs The first timestamp inside the window.
	 * @param int $lastTs  The last timestamp inside the window.
	 */
	public function __construct(
		public readonly int $startTs,
		public readonly int $lastTs,
	) {
	}//end __construct()

	/**
	 * Whether a timestamp lies inside the window; a missing one never does.
	 *
	 * @param int|null $timestamp The timestamp, null when the row has none.
	 *
	 * @return bool True when inside.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function contains(?int $timestamp): bool {
		return $timestamp !== null && $timestamp >= $this->startTs && $timestamp <= $this->lastTs;
	}//end contains()
}//end class
