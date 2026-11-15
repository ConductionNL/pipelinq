<?php

/**
 * Pipelinq TimelineSource.
 *
 * The raw objects of one activity source.
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
 * The raw objects one source (a schema config key or an extra source) answered.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class TimelineSource {
	/**
	 * Constructor.
	 *
	 * @param string          $sourceType The source type / schema config key.
	 * @param iterable<mixed> $objects    The raw objects.
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly iterable $objects,
	) {
	}//end __construct()
}//end class
