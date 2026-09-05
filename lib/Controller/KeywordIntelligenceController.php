<?php

/**
 * Pipelinq KeywordIntelligenceController.
 *
 * The read side of the keyword derivations, and the single write that turns a
 * proposal into a `keywordTarget`.
 *
 * ONE READ SERVES THE PAGE. The Keywords page renders four sections and every
 * one of them comes out of this single response. Fanning out one request per
 * proposal before the page paints is the failure pipelinq#1781 already removed
 * from the blast performance page, and the derivations all run over the same
 * rows anyway.
 *
 * Authentication is not authorization: search demand is marketing data, a CRM
 * capability, so the same privilege check the blast and Search Console
 * endpoints apply is applied here.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use UnexpectedValueException;

/**
 * Keyword proposals, and the confirmation that turns one into a target.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
class KeywordIntelligenceController extends Controller {

	/**
	 * App-config key overriding the impression floor every derivation uses.
	 *
	 * @var string
	 */
	public const FLOOR_KEY = 'search.striking_min_impressions';

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param SearchQueryReportService $report The paged read over `searchQueryDaily`.
	 * @param KeywordAnalysisService $analysis The four derivations.
	 * @param SiteContentCrawler $crawler Our own pages, for the gap check.
	 * @param KeywordTargetService $targets The single write path.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param IUserSession $userSession Session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SearchQueryReportService $report,
		private readonly KeywordAnalysisService $analysis,
		private readonly SiteContentCrawler $crawler,
		private readonly KeywordTargetService $targets,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every derivation for a window, in one response.
	 *
	 * @param string|null $from Window start `YYYY-MM-DD`.
	 * @param string|null $to Window end `YYYY-MM-DD`.
	 * @param string|null $property Restrict to one property.
	 *
	 * @return JSONResponse `{from, to, buckets[], strikingDistance[], cannibalisation[], gaps[], crawl, confirmedTerms[]}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets
	 */
	#[NoAdminRequired]
	public function proposals(?string $from = null, ?string $to = null, ?string $property = null): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$window = $this->report->rows(from: $from, to: $to, property: $property);
		$rows = $window['rows'];
		$floor = $this->floor();
		$crawl = $this->crawler->crawl(rows: $rows);

		return new JSONResponse(
			[
				'from' => $window['from'],
				'to' => $window['to'],
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
				'confirmedTerms' => $this->targets->confirmedTerms(),
			]
		);
	}//end proposals()

	/**
	 * The keyword targets already confirmed.
	 *
	 * @return JSONResponse `{targets[]}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	#[NoAdminRequired]
	public function targets(): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['targets' => $this->targets->all()]);
	}//end targets()

	/**
	 * Confirm a proposal into a keyword target.
	 *
	 * @param string $term The search term.
	 * @param string $status What we decided: `use-more`, `use-less` or `watch`.
	 * @param string $proposalKind Which derivation proposed it.
	 * @param string $intent What somebody typing it is trying to do.
	 * @param string $targetPageRef The page that should win it.
	 * @param string $property The property the proposal came from.
	 * @param string $notes Why it matters.
	 *
	 * @return JSONResponse The stored target, or the refusal.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	#[NoAdminRequired]
	public function confirm(
		string $term = '',
		string $status = 'watch',
		string $proposalKind = 'manual',
		string $intent = '',
		string $targetPageRef = '',
		string $property = '',
		string $notes = '',
	): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$user = $this->userSession->getUser();
		$uid = '';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		try {
			$saved = $this->targets->confirm(
				payload: [
					'term' => $term,
					'status' => $status,
					'proposalKind' => $proposalKind,
					'intent' => $intent,
					'targetPageRef' => $targetPageRef,
					'property' => $property,
					'notes' => $notes,
				],
				uid: $uid
			);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($saved === null) {
			return new JSONResponse(['error' => 'The keyword target could not be stored.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end confirm()

	/**
	 * Refuse a caller without the CRM privilege.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function refuseUnprivileged(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnprivileged()

	/**
	 * The impression floor: the configured value when it is a positive
	 * number, the documented default otherwise.
	 *
	 * @return int
	 */
	private function floor(): int {
		$configured = (int)$this->appConfig->getValueString(Application::APP_ID, self::FLOOR_KEY, '');
		if ($configured < 1) {
			return KeywordAnalysisService::DEFAULT_MIN_IMPRESSIONS;
		}

		return $configured;
	}//end floor()
}//end class
