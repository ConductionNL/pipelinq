<?php

/**
 * Golden test for KeywordIntelligenceController, called as Nextcloud dispatches it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KeywordIntelligenceController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Search\KeywordAnalysisService;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Throwable;
use UnexpectedValueException;

/**
 * Every action of KeywordIntelligenceController over the session, privilege,
 * parameter type and outcome cases: the response and every call made to the
 * policy, the report, the crawler, the targets and the app config, in order.
 * The analysis service is the real one. The controller is built by
 * autowiring from the leaf doubles only. Compared with
 * KeywordIntelligenceControllerGolden.json; set GOLDEN_RECORD=1 to rewrite
 * it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\KeywordIntelligenceController
 * @covers \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 * @covers \OCA\Pipelinq\Service\Search\KeywordProposals
 * @covers \OCA\Pipelinq\Util\ReadsTypedRequestParameters
 * @uses   \OCA\Pipelinq\Service\Search\KeywordAnalysisService
 * @uses   \OCA\Pipelinq\Service\Search\ExpectedCtrCurve
 */
class KeywordIntelligenceControllerGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * What the current case did, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		$this->assertGolden(file: __DIR__.'/KeywordIntelligenceControllerGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * Forged identity parameters change nothing: the actor is the session user.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		$forged = ['uid' => 'mallory', 'userId' => 'mallory', 'createdBy' => 'mallory', 'user' => 'mallory'];
		foreach ($this->cases() as $name => $case) {
			$plain = $this->runCase(case: $case);
			$case['params'] = (($case['params'] ?? []) + $forged);
			$this->assertSame($plain, $this->runCase(case: $case), $name);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * The cases: action, caller, request parameters and how the doubles answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$full = ['term' => 'woo verzoek', 'status' => 'use-more', 'proposalKind' => 'striking', 'intent' => 'informational', 'targetPageRef' => '/woo', 'property' => 'sc-domain:example.nl', 'notes' => 'Rising'];
		return [
			'proposals no session'          => ['action' => 'proposals', 'user' => null],
			'proposals unprivileged'        => ['action' => 'proposals', 'user' => 'intern'],
			'proposals defaults'            => ['action' => 'proposals'],
			'proposals window'              => ['action' => 'proposals', 'params' => ['from' => '2026-08-01', 'to' => '2026-08-31', 'property' => 'sc-domain:example.nl']],
			'proposals floor configured'    => ['action' => 'proposals', 'floor' => '5'],
			'proposals floor zero'          => ['action' => 'proposals', 'floor' => '0'],
			'proposals floor text'          => ['action' => 'proposals', 'floor' => 'many'],
			'proposals floor high'          => ['action' => 'proposals', 'floor' => '100000'],
			'proposals crawled pages'       => ['action' => 'proposals', 'crawled' => true],
			'proposals crawled low floor'   => ['action' => 'proposals', 'crawled' => true, 'floor' => '5'],
			'proposals array from'          => ['action' => 'proposals', 'params' => ['from' => ['x']]],
			'proposals integer to'          => ['action' => 'proposals', 'params' => ['to' => 20260801]],
			'proposals no session array'    => ['action' => 'proposals', 'params' => ['property' => ['x']], 'user' => null],
			'targets no session'            => ['action' => 'targets', 'user' => null],
			'targets unprivileged'          => ['action' => 'targets', 'user' => 'intern'],
			'targets listed'                => ['action' => 'targets'],
			'confirm no session'            => ['action' => 'confirm', 'params' => $full, 'user' => null],
			'confirm unprivileged'          => ['action' => 'confirm', 'params' => $full, 'user' => 'intern'],
			'confirm full'                  => ['action' => 'confirm', 'params' => $full],
			'confirm defaults'              => ['action' => 'confirm'],
			'confirm term only'             => ['action' => 'confirm', 'params' => ['term' => 'zaak']],
			'confirm bad status'            => ['action' => 'confirm', 'params' => ['term' => 'zaak', 'status' => 'gebruik-meer']],
			'confirm store unavailable'     => ['action' => 'confirm', 'params' => $full, 'unavailable' => true],
			'confirm null values'           => ['action' => 'confirm', 'params' => ['term' => 'zaak', 'status' => null, 'notes' => null]],
			'confirm array term'            => ['action' => 'confirm', 'params' => ['term' => ['zaak']]],
			'confirm integer notes'         => ['action' => 'confirm', 'params' => ['term' => 'zaak', 'notes' => 7]],
			'confirm boolean status'        => ['action' => 'confirm', 'params' => ['term' => 'zaak', 'status' => true]],
			'confirm no session array'      => ['action' => 'confirm', 'params' => ['intent' => ['x']], 'user' => null],
			'confirm each field'            => ['action' => 'confirm', 'params' => ['term' => 't', 'status' => 'watch', 'proposalKind' => 'gap', 'intent' => 'i', 'targetPageRef' => 'p', 'property' => 'q', 'notes' => 'n']],
		];
	}//end cases()

	/**
	 * Run one case against a freshly autowired controller.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$params = ($case['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->trace[] = ['getValueString' => [$app, $key, $default]];
				return ($case['floor'] ?? '');
			}
		);

		$doubles = [
			'appName' => 'pipelinq',
			IRequest::class => $request,
			IUserSession::class => $this->session(uid: (array_key_exists('user', $case) === true ? $case['user'] : 'marketer')),
			ObjectOwnerAccessPolicy::class => $this->policy(),
			SearchQueryReportService::class => $this->report(),
			KeywordAnalysisService::class => new KeywordAnalysisService(),
			SiteContentCrawler::class => $this->crawler(case: $case),
			KeywordTargetService::class => $this->targets(case: $case),
			IAppConfig::class => $appConfig,
		];
		$controller = Autowire::build(class: KeywordIntelligenceController::class, doubles: $doubles);

		try {
			$response = NextcloudDispatch::call(controller: $controller, method: $case['action'], params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $response->getData();
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['trace' => $this->trace];
	}//end runCase()

	/**
	 * A session for the given user (or none).
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A policy under which only `marketer` holds the CRM privilege.
	 *
	 * @return ObjectOwnerAccessPolicy
	 */
	private function policy(): ObjectOwnerAccessPolicy {
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(
			function (string $uid): bool {
				$this->trace[] = ['isPrivileged' => $uid];
				return $uid === 'marketer';
			}
		);
		return $policy;
	}//end policy()

	/**
	 * A report whose window holds two queries over two pages.
	 *
	 * @return SearchQueryReportService
	 */
	private function report(): SearchQueryReportService {
		$report = $this->createMock(SearchQueryReportService::class);
		$report->method('rows')->willReturnCallback(
			function (?string $from = null, ?string $to = null, ?string $property = null): array {
				$this->trace[] = ['rows' => [$from, $to, $property]];
				$rows = [
					['query' => 'woo verzoek indienen', 'page' => 'https://example.nl/woo', 'impressions' => 400, 'clicks' => 8, 'position' => 11.5, 'property' => 'p'],
					['query' => 'woo verzoek indienen', 'page' => 'https://example.nl/news', 'impressions' => 300, 'clicks' => 3, 'position' => 14.0, 'property' => 'p'],
					['query' => 'parkeervergunning', 'page' => 'https://example.nl/parkeren', 'impressions' => 60, 'clicks' => 20, 'position' => 2.1, 'property' => 'p'],
					['query' => 'afvalkalender', 'page' => 'https://example.nl/afval', 'impressions' => 9, 'clicks' => 0, 'position' => 35.0, 'property' => 'p'],
				];
				return ['from' => ($from ?? '2026-08-08'), 'to' => ($to ?? '2026-09-05'), 'rows' => $rows];
			}
		);
		return $report;
	}//end report()

	/**
	 * A crawler that crawled one page, or was not configured.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return SiteContentCrawler
	 */
	private function crawler(array $case): SiteContentCrawler {
		$crawler = $this->createMock(SiteContentCrawler::class);
		$crawler->method('crawl')->willReturnCallback(
			function (array $rows) use ($case): array {
				$this->trace[] = ['crawl' => count($rows)];
				if (($case['crawled'] ?? false) === true) {
					return ['crawled' => true, 'failure' => null, 'reason' => '', 'pages' => [['url' => 'https://example.nl/parkeren', 'text' => 'parkeervergunning aanvragen']]];
				}

				return ['crawled' => false, 'failure' => 'not_configured', 'reason' => 'No crawl source is configured.', 'pages' => []];
			}
		);
		return $crawler;
	}//end crawler()

	/**
	 * A target store with one confirmed target.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return KeywordTargetService
	 */
	private function targets(array $case): KeywordTargetService {
		$targets = $this->createMock(KeywordTargetService::class);
		$targets->method('confirmedTerms')->willReturnCallback(
			function (): array {
				$this->trace[] = ['confirmedTerms' => true];
				return ['parkeervergunning'];
			}
		);
		$targets->method('all')->willReturnCallback(
			function (): array {
				$this->trace[] = ['all' => true];
				return [['term' => 'parkeervergunning', 'status' => 'watch']];
			}
		);
		$targets->method('confirm')->willReturnCallback(
			function (array $payload, string $uid) use ($case): ?array {
				$this->trace[] = ['confirm' => [$payload, $uid]];
				if (in_array($payload['status'], ['use-more', 'use-less', 'watch'], true) === false) {
					throw new UnexpectedValueException('The status must be one of use-more, use-less, watch.');
				}

				if (($case['unavailable'] ?? false) === true) {
					return null;
				}

				return ($payload + ['id' => 'target-1', 'createdBy' => $uid]);
			}
		);
		return $targets;
	}//end targets()
}//end class
