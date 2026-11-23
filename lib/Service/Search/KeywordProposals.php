<?php

/**
 * Pipelinq KeywordProposals.
 *
 * Every keyword derivation for one search-console window, in one answer.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\KeywordProposalsInterface;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\IAppConfig;

/**
 * Reads the window's rows, crawls our own pages for the gap check, and runs
 * the four derivations under the configured impression floor.
 *
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
 */
class KeywordProposals implements KeywordProposalsInterface {

	/**
	 * Constructor.
	 *
	 * @param SearchQueryReportService $report    The paged read over `searchQueryDaily`.
	 * @param KeywordAnalysisService   $analysis  The four derivations.
	 * @param SiteContentCrawler       $crawler   Our own pages, for the gap check.
	 * @param IAppConfig               $appConfig Pipelinq app config.
	 */
	public function __construct(
		private readonly SearchQueryReportService $report,
		private readonly KeywordAnalysisService $analysis,
		private readonly SiteContentCrawler $crawler,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every derivation for a window.
	 *
	 * @param array{from: ?string, to: ?string, property: ?string} $window Window start and end (`YYYY-MM-DD`) and an optional property.
	 *
	 * @return array<string, mixed> `{from, to, minImpressions, buckets[], strikingDistance[], cannibalisation[], gaps[], crawl}`.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	public function forWindow(array $window): array {
		$read = $this->report->rows(from: $window['from'], to: $window['to'], property: $window['property']);
		$rows = $read['rows'];
		$floor = $this->floor();
		$crawl = $this->crawler->crawl(rows: $rows);

		return [
			'from' => $read['from'],
			'to' => $read['to'],
			'minImpressions' => $floor,
			'buckets' => $this->analysis->positionBuckets(rows: $rows),
			'strikingDistance' => $this->analysis->strikingDistance(rows: $rows, minImpressions: $floor),
			'cannibalisation' => $this->analysis->cannibalisation(rows: $rows, minImpressions: $floor),
			'gaps' => $this->analysis->contentGaps(rows: $rows, pages: $crawl['pages'], minImpressions: $floor),
			'crawl' => [
				'crawled' => $crawl['crawled'],
				'failure' => $crawl['failure'],
				'reason' => $crawl['reason'],
				'pages' => count($crawl['pages']),
			],
		];
	}//end forWindow()

	/**
	 * The impression floor: the configured value when it is a positive
	 * number, the documented default otherwise.
	 *
	 * @return int
	 */
	private function floor(): int {
		$configured = (int)$this->appConfig->getValueString(AppIdentity::APP_ID, self::FLOOR_KEY, '');
		if ($configured < 1) {
			return KeywordAnalysisService::DEFAULT_MIN_IMPRESSIONS;
		}

		return $configured;
	}//end floor()
}//end class
