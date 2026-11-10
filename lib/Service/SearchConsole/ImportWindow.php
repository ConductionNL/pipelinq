<?php

/**
 * Pipelinq ImportWindow.
 *
 * The days one Search Console import reads: today (UTC) and how many days
 * back, with the first and last date of the window derived from them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use DateTimeImmutable;

/**
 * An import window ending today.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
final class ImportWindow {
	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $today Today, in UTC.
	 * @param int               $days  How many days back from today, inclusive of today.
	 */
	public function __construct(
		public readonly DateTimeImmutable $today,
		public readonly int $days,
	) {
	}//end __construct()

	/**
	 * The window start `YYYY-MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function from(): string {
		return $this->today->modify('-' . max(0, $this->days) . ' days')->format('Y-m-d');
	}//end from()

	/**
	 * The window end `YYYY-MM-DD`, inclusive.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function to(): string {
		return $this->today->format('Y-m-d');
	}//end to()
}//end class
