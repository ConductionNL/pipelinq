<?php

/**
 * Unit tests for the three search-intelligence controllers.
 *
 * These are contract tests in the plain sense: they call each endpoint and
 * assert its status and its response shape. Three properties matter here and
 * none of them needs a credential to check.
 *
 * Authentication is not authorization. Search demand, competitor activity and
 * the follow audit are marketing data, a CRM capability, so an anonymous
 * caller is 401 and an authenticated but unprivileged one is 403 on every
 * method, including the reads.
 *
 * Reading proposals writes nothing. The keyword derivations are recomputed on
 * every read, and a service that created records from them would create and
 * delete targets under the marketer's hands.
 *
 * A page's read is ONE request. Each response carries everything its page
 * renders, which is what keeps the pages off the per-object fan-out that
 * pipelinq#1781 removed from the blast performance page.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CompetitorWatchController;
use OCA\Pipelinq\Controller\KeywordIntelligenceController;
use OCA\Pipelinq\Controller\MarketingConnectorController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for KeywordIntelligenceController, CompetitorWatchController and
 * MarketingConnectorController.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
class MarketingIntelligenceControllerTest extends TestCase {

	/**
	 * How many times `KeywordTargetService::confirm()` was called.
	 *
	 * @var int
	 */
	private int $confirmCalls = 0;

	/**
	 * A session, signed in or not, privileged or not.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return IUserSession
	 */
	private function session(bool $signedIn): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($signedIn === false) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The CRM privilege check.
	 *
	 * @param bool $privileged Whether the caller is privileged.
	 *
	 * @return ObjectOwnerAccessPolicy
	 */
	private function policy(bool $privileged): ObjectOwnerAccessPolicy {
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		return $policy;
	}//end policy()

	/**
	 * A keyword controller over mocked collaborators.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether the caller is privileged.
	 * @param array<int, array<string, mixed>> $rows The window's rows.
	 * @param bool $crawled Whether the crawl ran.
	 *
	 * @return KeywordIntelligenceController
	 */
	private function keywords(
		bool $signedIn = true,
		bool $privileged = true,
		array $rows = [],
		bool $crawled = false,
	): KeywordIntelligenceController {
		$report = $this->createMock(SearchQueryReportService::class);
		$report->method('rows')->willReturn(['from' => '2026-08-08', 'to' => '2026-09-05', 'rows' => $rows]);

		$crawler = $this->createMock(SiteContentCrawler::class);
		$crawler->method('crawl')->willReturn(
			[
				'crawled' => $crawled,
				'failure' => ($crawled === true ? null : 'not_configured'),
				'reason' => ($crawled === true ? '' : 'No crawl source is configured, so the content gap check did not run.'),
				'pages' => [],
			]
		);

		$targets = $this->createMock(KeywordTargetService::class);
		$targets->method('confirmedTerms')->willReturn([]);
		$targets->method('all')->willReturn([]);
		$targets->method('confirm')->willReturnCallback(
			function (array $payload, string $uid): array {
				$this->confirmCalls++;
				if (in_array($payload['status'], ['use-more', 'use-less', 'watch'], true) === false) {
					throw new UnexpectedValueException('The status must be one of use-more, use-less, watch.');
				}

				return ($payload + ['id' => 'target-1', 'createdBy' => $uid]);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new KeywordIntelligenceController(
			appName: 'pipelinq',
			request: $this->createMock(IRequest::class),
			report: $report,
			analysis: new KeywordAnalysisService(),
			crawler: $crawler,
			targets: $targets,
			appConfig: $appConfig,
			userSession: $this->session(signedIn: $signedIn),
			policy: $this->policy(privileged: $privileged)
		);
	}//end keywords()

	/**
	 * An anonymous caller is refused on every keyword method.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedOnTheKeywordEndpoints(): void {
		$controller = $this->keywords(signedIn: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->proposals()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->targets()->getStatus());
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->confirm(term: 'woo', status: 'watch')->getStatus()
		);
		$this->assertSame(0, $this->confirmCalls);
	}//end testAnAnonymousCallerIsRefusedOnTheKeywordEndpoints()

	/**
	 * An authenticated caller without the CRM privilege is refused too, and
	 * nothing is created.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedCallerIsForbiddenAndCreatesNothing(): void {
		$controller = $this->keywords(privileged: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->proposals()->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->confirm(term: 'woo', status: 'watch')->getStatus()
		);
		$this->assertSame(0, $this->confirmCalls);
	}//end testAnUnprivilegedCallerIsForbiddenAndCreatesNothing()

	/**
	 * One read carries every section the page renders, and the four buckets
	 * are always present so the page never has to invent one.
	 *
	 * @return void
	 */
	public function testOneReadCarriesEverySectionThePageRenders(): void {
		$rows = [
			['query' => 'woo verzoek', 'page' => '/woo', 'clicks' => 1, 'impressions' => 900, 'position' => 12.0],
		];

		$body = $this->keywords(rows: $rows)->proposals()->getData();

		$this->assertSame('2026-08-08', $body['from']);
		$this->assertCount(4, $body['buckets']);
		$this->assertArrayHasKey('strikingDistance', $body);
		$this->assertArrayHasKey('cannibalisation', $body);
		$this->assertArrayHasKey('gaps', $body);
		$this->assertArrayHasKey('crawl', $body);
		$this->assertArrayHasKey('confirmedTerms', $body);
	}//end testOneReadCarriesEverySectionThePageRenders()

	/**
	 * Reading proposals writes nothing, however many times it is read.
	 *
	 * @return void
	 */
	public function testReadingProposalsCreatesNothing(): void {
		$controller = $this->keywords();

		$controller->proposals();
		$controller->proposals();

		$this->assertSame(0, $this->confirmCalls);
	}//end testReadingProposalsCreatesNothing()

	/**
	 * Without a crawl the gap section reports the reason, and claims no gaps.
	 *
	 * @return void
	 */
	public function testWithoutACrawlTheGapSectionSaysSo(): void {
		$body = $this->keywords(
			rows: [['query' => 'woo verzoek', 'page' => '/woo', 'clicks' => 0, 'impressions' => 900, 'position' => 30.0]]
		)->proposals()->getData();

		$this->assertFalse($body['crawl']['crawled']);
		$this->assertSame('not_configured', $body['crawl']['failure']);
		$this->assertNotSame('', $body['crawl']['reason']);
		$this->assertSame([], $body['gaps']);
	}//end testWithoutACrawlTheGapSectionSaysSo()

	/**
	 * A confirmation creates one target, stamped with the caller and the
	 * derivation that proposed it.
	 *
	 * @return void
	 */
	public function testAConfirmationCreatesOneStampedTarget(): void {
		$response = $this->keywords()->confirm(
			term: 'woo verzoek indienen',
			status: 'use-more',
			proposalKind: 'striking-distance'
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('woo verzoek indienen', $response->getData()['term']);
		$this->assertSame('striking-distance', $response->getData()['proposalKind']);
		$this->assertSame('marketer', $response->getData()['createdBy']);
		$this->assertSame(1, $this->confirmCalls);
	}//end testAConfirmationCreatesOneStampedTarget()

	/**
	 * A value outside the vocabulary is a 400 carrying the service's own
	 * message, not a 500.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsABadRequest(): void {
		$response = $this->keywords()->confirm(term: 'woo', status: 'gebruik-meer');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('use-more', $response->getData()['error']);
	}//end testAnUnknownStatusIsABadRequest()

	/**
	 * A competitor controller over mocked collaborators.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether the caller is privileged.
	 * @param bool $configured Whether an egress source is configured.
	 * @param array<int, array<string, mixed>> $watch The watch the run acts on, or none.
	 *
	 * @return CompetitorWatchController
	 */
	private function competitors(
		bool $signedIn = true,
		bool $privileged = true,
		bool $configured = false,
		array $watch = [],
	): CompetitorWatchController {
		$watches = $this->createMock(CompetitorWatchService::class);
		$watches->method('competitors')->willReturn([['id' => 'c1', 'name' => 'Voorbeeld B.V.']]);
		$watches->method('watches')->willReturn([['id' => 'w1', 'competitorId' => 'c1', 'kind' => 'rss']]);
		$watches->method('watch')->willReturn(($watch === [] ? null : $watch));
		$watches->method('runOne')->willReturn(['outcome' => 'ok', 'reason' => '', 'events' => 2]);

		$events = $this->createMock(WatchEventStore::class);
		$events->method('all')->willReturn(
			[
				['id' => 'e1', 'seenAt' => '2026-09-01T00:00:00Z', 'url' => 'https://example.org/1'],
				['id' => 'e2', 'seenAt' => '2026-09-04T00:00:00Z', 'url' => 'https://example.org/2'],
			]
		);

		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('isConfigured')->willReturn($configured);

		return new CompetitorWatchController(
			appName: 'pipelinq',
			request: $this->createMock(IRequest::class),
			watches: $watches,
			events: $events,
			egress: $egress,
			userSession: $this->session(signedIn: $signedIn),
			policy: $this->policy(privileged: $privileged)
		);
	}//end competitors()

	/**
	 * The competitor endpoints refuse an anonymous and an unprivileged
	 * caller, including the run.
	 *
	 * @return void
	 */
	public function testTheCompetitorEndpointsRefuseAnUnprivilegedCaller(): void {
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->competitors(signedIn: false)->index()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->competitors(privileged: false)->index()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->competitors(privileged: false)->run(id: 'w1')->getStatus()
		);
	}//end testTheCompetitorEndpointsRefuseAnUnprivilegedCaller()

	/**
	 * With no egress source the read says so, rather than answering an empty
	 * event list that reads as "they published nothing".
	 *
	 * @return void
	 */
	public function testTheCompetitorReadSaysWhenNothingIsConfigured(): void {
		$body = $this->competitors(configured: false)->index()->getData();

		$this->assertFalse($body['configured']);
		$this->assertNotSame('', $body['reason']);

		$configured = $this->competitors(configured: true)->index()->getData();
		$this->assertTrue($configured['configured']);
		$this->assertSame('', $configured['reason']);
	}//end testTheCompetitorReadSaysWhenNothingIsConfigured()

	/**
	 * Events come back newest first, so the page renders what changed
	 * recently without sorting them itself.
	 *
	 * @return void
	 */
	public function testEventsComeBackNewestFirst(): void {
		$body = $this->competitors()->index()->getData();

		$this->assertSame(['e2', 'e1'], array_column($body['events'], 'id'));
	}//end testEventsComeBackNewestFirst()

	/**
	 * Running an unknown watch is a 404, never a 500.
	 *
	 * @return void
	 */
	public function testRunningAnUnknownWatchIsNotFound(): void {
		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->competitors(watch: [])->run(id: 'ghost')->getStatus()
		);

		$found = $this->competitors(watch: ['id' => 'w1', 'kind' => 'rss'])->run(id: 'w1');
		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(2, $found->getData()['events']);
	}//end testRunningAnUnknownWatchIsNotFound()

	/**
	 * A connector controller over mocked collaborators.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $privileged Whether the caller is privileged.
	 * @param array<string, mixed> $report What the Matomo service answers.
	 *
	 * @return MarketingConnectorController
	 */
	private function connectors(
		bool $signedIn = true,
		bool $privileged = true,
		array $report = ['connected' => false, 'reason' => 'No Matomo source is configured under matomo.source_id.'],
	): MarketingConnectorController {
		$matomo = $this->createMock(MatomoReportService::class);
		$matomo->method('report')->willReturn($report);

		$audit = $this->createMock(ConnectionAuditService::class);
		$audit->method('rows')->willReturn(
			[['network' => 'linkedin', 'counterpartHandle' => 'voorbeeld-bv', 'weFollowThem' => 'unknown']]
		);
		$audit->method('run')->willReturn(['pairs' => 1, 'answered' => 0, 'unknown' => 1]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1788600000);

		return new MarketingConnectorController(
			appName: 'pipelinq',
			request: $this->createMock(IRequest::class),
			matomo: $matomo,
			audit: $audit,
			time: $time,
			userSession: $this->session(signedIn: $signedIn),
			policy: $this->policy(privileged: $privileged)
		);
	}//end connectors()

	/**
	 * The connector endpoints refuse an anonymous and an unprivileged caller.
	 *
	 * @return void
	 */
	public function testTheConnectorEndpointsRefuseAnUnprivilegedCaller(): void {
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->connectors(signedIn: false)->matomo()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->connectors(signedIn: false)->connectionAudit()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->connectors(privileged: false)->matomo()->getStatus()
		);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->connectors(privileged: false)->connectionAudit()->getStatus()
		);
	}//end testTheConnectorEndpointsRefuseAnUnprivilegedCaller()

	/**
	 * An unconnected Matomo answers its reason with a 200, so the page can
	 * render the message rather than an error.
	 *
	 * @return void
	 */
	public function testAnUnconnectedMatomoAnswersItsReason(): void {
		$response = $this->connectors()->matomo();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['connected']);
		$this->assertStringContainsString('matomo.source_id', $response->getData()['reason']);
	}//end testAnUnconnectedMatomoAnswersItsReason()

	/**
	 * An explicit window is honoured, and a malformed one falls back to the
	 * default rather than being passed through.
	 *
	 * @return void
	 */
	public function testTheMatomoWindowIsValidated(): void {
		$matomo = $this->createMock(MatomoReportService::class);
		$seen = [];
		$matomo->method('report')->willReturnCallback(
			static function (string $from, string $to) use (&$seen): array {
				$seen = ['from' => $from, 'to' => $to];

				return ['connected' => true, 'reason' => '', 'campaigns' => []];
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1788600000);
		$controller = new MarketingConnectorController(
			appName: 'pipelinq',
			request: $this->createMock(IRequest::class),
			matomo: $matomo,
			audit: $this->createMock(ConnectionAuditService::class),
			time: $time,
			userSession: $this->session(signedIn: true),
			policy: $this->policy(privileged: true)
		);

		$controller->matomo(from: '2026-08-01', to: '2026-08-31');
		$this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], $seen);

		$controller->matomo(from: 'gisteren', to: '2026-13-45');
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $seen['from']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $seen['to']);
	}//end testTheMatomoWindowIsValidated()

	/**
	 * The audit is read without re-running it unless asked, so opening the
	 * page does not go out to a network.
	 *
	 * @return void
	 */
	public function testTheAuditIsReadWithoutReRunningIt(): void {
		$plain = $this->connectors()->connectionAudit();

		$this->assertSame(Http::STATUS_OK, $plain->getStatus());
		$this->assertNull($plain->getData()['summary']);
		$this->assertCount(1, $plain->getData()['rows']);

		$refreshed = $this->connectors()->connectionAudit(refresh: 'true');
		$this->assertSame(1, $refreshed->getData()['summary']['unknown']);
	}//end testTheAuditIsReadWithoutReRunningIt()
}//end class
