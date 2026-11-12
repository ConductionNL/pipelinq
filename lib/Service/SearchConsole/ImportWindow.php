<?php

/**
 * Pipelinq ImportWindow.
 *
 * The days one Search Console import reads: the moment it runs and how many
 * days back, with today (UTC) and the first and last date of the window
 * derived from them.
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
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use DateTimeImmutable;
use DateTimeZone;

/**
 * An import window ending today.
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
final class ImportWindow {
	/**
	 * Constructor.
	 *
	 * @param int $timestamp The moment of the import (Unix time).
	 * @param int $days      How many days back from today, inclusive of today.
	 */
	public function __construct(
		public readonly int $timestamp,
		public readonly int $days,
	) {
	}//end __construct()

	/**
	 * The moment of the import, in UTC.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function today(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->timestamp))->setTimezone(new DateTimeZone('UTC'));
	}//end today()

	/**
	 * The window start `YYYY-MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function firstDay(): string {
		return $this->today()->modify('-' . max(0, $this->days) . ' days')->format('Y-m-d');
	}//end firstDay()

	/**
	 * The window end `YYYY-MM-DD`, inclusive.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function lastDay(): string {
		return $this->today()->format('Y-m-d');
	}//end lastDay()
}//end class
