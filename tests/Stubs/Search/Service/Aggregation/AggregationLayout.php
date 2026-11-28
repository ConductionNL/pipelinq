<?php

/**
 * Test stub for OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout.
 *
 * The grouping, date-bucket, metric-list and running-total part of an
 * aggregation request, passed to `AggregationQuery::create(layout: ...)`.
 * Loaded via the autoload-dev PSR-4 mapping and a no-op when the real
 * openregister app is present (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

if (class_exists(AggregationLayout::class) === false) {
	/**
	 * Stub AggregationLayout — used only in standalone unit tests.
	 *
	 * Replaced by the real implementation when openregister is installed.
	 */
	final class AggregationLayout {
		/**
		 * Lay out a request.
		 *
		 * @param array<string, mixed>|null $groupBy Optional grouping spec.
		 * @param array<string, mixed>|null $dateBucket Optional date-bucket spec.
		 * @param array<int, array<string, mixed>>|null $metrics Optional multi-metric list.
		 * @param bool $cumulative Optional running-total flag.
		 */
		public function __construct(
			public readonly ?array $groupBy = null,
			public readonly ?array $dateBucket = null,
			public readonly ?array $metrics = null,
			public readonly bool $cumulative = false,
		) {
		}//end __construct()
	}//end class
}//end if
