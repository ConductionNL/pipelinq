<?php

/**
 * Unit tests for LoyaltyReportingController.
 *
 * Wire contract tests for the read-only loyalty reporting surface: programme
 * KPIs, the IFRS 15 / RJ 270 liability snapshot, the tier distribution and the
 * points expiry forecast. Every test asserts the HTTP status code AND the
 * response body — and the value-bearing reports are driven through the REAL
 * LoyaltyReportingService over seeded accounts so a report that silently
 * answers a healthy 200 with zeros cannot pass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\Pipelinq\Controller\LoyaltyReportingController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\LoyaltyReportingService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for every network-facing LoyaltyReportingController endpoint.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the reporting service
 *  and the collaborators it aggregates over.
 */
class LoyaltyReportingControllerTest extends TestCase {
	/**
	 * Build the controller over a given reporting service.
	 *
	 * @param LoyaltyReportingService $reportingService The reporting service.
	 * @param bool $authenticated Whether a session user is present.
	 * @param string $uid The session user id.
	 *
	 * @return LoyaltyReportingController
	 */
	private function buildController(
		LoyaltyReportingService $reportingService,
		bool $authenticated = true,
		string $uid = 'programme-manager',
		bool $privileged = true,
	): LoyaltyReportingController {
		$userSession = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new LoyaltyReportingController($this->createMock(IRequest::class),
			$reportingService,
			$userSession,
			$this->createConfiguredMock(
				ObjectOwnerAccessPolicy::class,
				['isPrivileged' => $privileged, 'mayAccess' => $privileged]
			)
		);
	}//end buildController()

	/**
	 * Build an IAppConfig stub mapping every *_schema key to itself.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'register') {
					return 'reg';
				}

				return $key;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Wire the REAL reporting service over seeded accounts and programme.
	 *
	 * The AggregationRunner is INJECTED (ADR-083/084) rather than pulled from
	 * a container, so a test that wants the OpenRegister grouped-count
	 * pushdown hands its own runner in; omitting it leaves a bare double whose
	 * empty envelope drives the documented PHP fallback.
	 *
	 * @param array<int, array<string, mixed>> $accounts The seeded accounts.
	 * @param array<string, mixed> $programme The seeded programme.
	 * @param ?AggregationRunner $runner Optional aggregation runner.
	 *
	 * @return LoyaltyReportingService
	 */
	private function realReportingService(
		array $accounts,
		array $programme = [],
		?AggregationRunner $runner = null,
	): LoyaltyReportingService {
		$accountService = $this->createMock(LoyaltyAccountService::class);
		$accountService->method('listAccountsForProgramme')->willReturn($accounts);

		$programmeService = $this->createMock(LoyaltyProgrammeService::class);
		$programmeService->method('getProgramme')->willReturn($programme);

		return new LoyaltyReportingService(
			appConfig: $this->appConfig(),
			accountService: $accountService,
			ledgerService: $this->createMock(PointsLedgerService::class),
			programmeService: $programmeService,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
			aggregationRunner: ($runner ?? $this->createMock(AggregationRunner::class)),
		);
	}//end realReportingService()

	/*
	 * ---------------------------------------------------------------------
	 * liability
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The liability snapshot answers 200 with the SEEDED outstanding points and
	 * the money value they represent — not a healthy 200 over zeros.
	 *
	 * @return void
	 */
	public function testLiabilityReturnsTheSeededOutstandingPointsAndValue(): void {
		$service = $this->realReportingService(
			accounts: [
				['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 100, 'status' => 'active'],
				['@self' => ['id' => 'acc-2'], 'programmeId' => 'prog-1', 'currentBalance' => 250, 'status' => 'active'],
			],
			programme: ['pointValue' => 0.02]
		);

		$response = $this->buildController($service)->liability(programmeId: 'prog-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertSame(350, $data['outstandingPoints']);
		$this->assertSame(7.0, $data['estimatedLiability']);
		$this->assertSame(0.02, $data['pointValue']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)$data['calculationDate']);
	}//end testLiabilityReturnsTheSeededOutstandingPointsAndValue()

	/**
	 * A negative denormalised balance must never reduce the reported liability
	 * below the sum of the positive balances.
	 *
	 * @return void
	 */
	public function testLiabilityIgnoresNegativeBalances(): void {
		$service = $this->realReportingService(
			accounts: [
				['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 500],
				['@self' => ['id' => 'acc-2'], 'programmeId' => 'prog-1', 'currentBalance' => -200],
			],
			programme: ['pointValue' => 0.01]
		);

		$response = $this->buildController($service)->liability(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(500, $response->getData()['outstandingPoints']);
		$this->assertSame(5.0, $response->getData()['estimatedLiability']);
	}//end testLiabilityIgnoresNegativeBalances()

	/**
	 * A programme with no configured pointValue falls back to the documented
	 * default of EUR 0.01 per point rather than valuing the liability at zero.
	 *
	 * @return void
	 */
	public function testLiabilityFallsBackToTheDefaultPointValue(): void {
		$service = $this->realReportingService(
			accounts: [['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 1000]],
			programme: []
		);

		$response = $this->buildController($service)->liability(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1000, $response->getData()['outstandingPoints']);
		$this->assertSame(0.01, $response->getData()['pointValue']);
		$this->assertSame(10.0, $response->getData()['estimatedLiability']);
	}//end testLiabilityFallsBackToTheDefaultPointValue()

	/**
	 * An anonymous caller is refused with 401 and no figures are computed.
	 *
	 * @return void
	 */
	public function testLiabilityRequiresAuthentication(): void {
		$service = $this->createMock(LoyaltyReportingService::class);
		$service->expects($this->never())->method('getLiabilitySnapshot');

		$response = $this->buildController($service, authenticated: false)->liability(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testLiabilityRequiresAuthentication()

	/**
	 * A rank-and-file authenticated user must not be able to read a programme's
	 * financial liability.
	 *
	 * The skip this replaces claimed the endpoint 'carries NoAdminRequired and
	 * no role check'. It does carry the role check, at all four reporting
	 * methods. What it did not have was any test that could SEE the check: the
	 * fixture hardcoded `isPrivileged => true`, so every caller was privileged
	 * and the 403 path was unreachable. Same fixture flaw as the three loyalty
	 * 'IDOR' skips.
	 *
	 * @return void
	 */
	public function testLiabilityRefusesANonPrivilegedUser(): void {
		$service = $this->realReportingService(
			accounts: [['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 100000]],
			programme: ['pointValue' => 0.05]
		);

		$response = $this->buildController(
			$service,
			uid: 'random-customer',
			privileged: false
		)->liability(programmeId: 'prog-1');

		// Contract: programme finance is management data, not customer data.
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testLiabilityRefusesANonPrivilegedUser()

	/*
	 * ---------------------------------------------------------------------
	 * tierDistribution
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The tier distribution answers 200 with the SEEDED per-tier counts from
	 * the OpenRegister grouped-count pushdown.
	 *
	 * @return void
	 */
	public function testTierDistributionReturnsTheSeededBuckets(): void {
		$runner = new LoyaltyGroupedCountRunnerFake(
			[
				['key' => 'gold', 'value' => 2],
				['key' => 'silver', 'value' => 1],
				['key' => null, 'value' => 3],
			]
		);
		$service = $this->realReportingService(accounts: [], runner: $runner);

		$response = $this->buildController($service)->tierDistribution(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('tiers', $data);

		$byTier = [];
		foreach ($data['tiers'] as $row) {
			$this->assertArrayHasKey('tierId', $row);
			$this->assertArrayHasKey('accountCount', $row);
			$byTier[(string)$row['tierId']] = (int)$row['accountCount'];
		}

		$this->assertSame(['gold' => 2, 'silver' => 1, 'unassigned' => 3], $byTier);
	}//end testTierDistributionReturnsTheSeededBuckets()

	/**
	 * When the OpenRegister aggregation runner is unavailable the endpoint
	 * still answers 200 with the real per-tier counts from the PHP fallback,
	 * not an empty list.
	 *
	 * @return void
	 */
	public function testTierDistributionFallsBackToRealCounts(): void {
		// The runner is injected now (ADR-083), so "unavailable" can no longer
		// be modelled by a container that refuses to resolve it. It is modelled
		// where the service actually observes it: getTierReport() falls back
		// only when runAdhocByRef() RAISES, which is what an unreachable
		// OpenRegister aggregation backend does.
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')
			->willThrowException(new \RuntimeException('OpenRegister aggregation unavailable'));

		$service = $this->realReportingService(
			accounts: [
				['programmeId' => 'prog-1', 'currentTierId' => 'gold'],
				['programmeId' => 'prog-1', 'currentTierId' => 'gold'],
				['programmeId' => 'prog-1', 'currentTierId' => 'silver'],
				['programmeId' => 'prog-1'],
			],
			runner: $runner,
		);

		$response = $this->buildController($service)->tierDistribution(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$byTier = [];
		foreach ($response->getData()['tiers'] as $row) {
			$byTier[(string)$row['tierId']] = (int)$row['accountCount'];
		}

		$this->assertSame(['gold' => 2, 'silver' => 1, 'unassigned' => 1], $byTier);
	}//end testTierDistributionFallsBackToRealCounts()

	/**
	 * A programme with no accounts answers 200 with an empty `tiers` list.
	 *
	 * @return void
	 */
	public function testTierDistributionReturnsEmptyListForAnEmptyProgramme(): void {
		$service = $this->realReportingService(accounts: []);

		$response = $this->buildController($service)->tierDistribution(programmeId: 'prog-empty');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['tiers' => []], $response->getData());
	}//end testTierDistributionReturnsEmptyListForAnEmptyProgramme()

	/**
	 * An anonymous caller is refused with 401 and no report is computed.
	 *
	 * @return void
	 */
	public function testTierDistributionRequiresAuthentication(): void {
		$service = $this->createMock(LoyaltyReportingService::class);
		$service->expects($this->never())->method('getTierReport');

		$response = $this->buildController($service, authenticated: false)->tierDistribution(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testTierDistributionRequiresAuthentication()

	/*
	 * ---------------------------------------------------------------------
	 * expiryForecast
	 * ---------------------------------------------------------------------
	 */

	/**
	 * With an inactivity expiry policy the forecast answers 200 with the points
	 * and account count actually at risk in the window — not a constant zero.
	 *
	 * @return void
	 */
	public function testExpiryForecastReturnsThePointsAtRisk(): void {
		$stale = (new \DateTimeImmutable('-24 months', new \DateTimeZone('UTC')))->format('c');
		$recent = (new \DateTimeImmutable('-1 month', new \DateTimeZone('UTC')))->format('c');

		$service = $this->realReportingService(
			accounts: [
				['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 400, 'lastActivityDate' => $stale],
				['@self' => ['id' => 'acc-2'], 'programmeId' => 'prog-1', 'currentBalance' => 250, 'lastActivityDate' => $recent],
				['@self' => ['id' => 'acc-3'], 'programmeId' => 'prog-1', 'currentBalance' => 0, 'lastActivityDate' => $stale],
			],
			programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 12]]
		);

		$response = $this->buildController($service)->expiryForecast(programmeId: 'prog-1', days: 30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertSame(400, $data['points']);
		$this->assertSame(1, $data['accounts']);
		$this->assertArrayHasKey('until', $data);
		$this->assertGreaterThan(
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
			(string)$data['until']
		);
	}//end testExpiryForecastReturnsThePointsAtRisk()

	/**
	 * A programme without an inactivity policy forecasts nothing expiring, and
	 * says so with an explicit zero rather than omitting the keys.
	 *
	 * @return void
	 */
	public function testExpiryForecastIsZeroWithoutAnExpiryPolicy(): void {
		$stale = (new \DateTimeImmutable('-24 months', new \DateTimeZone('UTC')))->format('c');

		$service = $this->realReportingService(
			accounts: [['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 400, 'lastActivityDate' => $stale]],
			programme: ['expiryPolicy' => ['type' => 'none']]
		);

		$response = $this->buildController($service)->expiryForecast(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['points']);
		$this->assertSame(0, $response->getData()['accounts']);
		$this->assertArrayHasKey('until', $response->getData());
	}//end testExpiryForecastIsZeroWithoutAnExpiryPolicy()

	/**
	 * A wider window covers strictly more points than a narrow one.
	 *
	 * @return void
	 */
	public function testExpiryForecastWindowWidensTheHorizon(): void {
		$elevenMonthsAgo = (new \DateTimeImmutable('-11 months', new \DateTimeZone('UTC')))->format('c');

		$service = $this->realReportingService(
			accounts: [['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 900, 'lastActivityDate' => $elevenMonthsAgo]],
			programme: ['expiryPolicy' => ['type' => 'inactivityMonths', 'value' => 12]]
		);

		$controller = $this->buildController($service);

		$narrow = $controller->expiryForecast(programmeId: 'prog-1', days: 1);
		$wide = $controller->expiryForecast(programmeId: 'prog-1', days: 90);

		$this->assertSame(Http::STATUS_OK, $narrow->getStatus());
		$this->assertSame(Http::STATUS_OK, $wide->getStatus());
		$this->assertSame(0, $narrow->getData()['points']);
		$this->assertSame(900, $wide->getData()['points']);
	}//end testExpiryForecastWindowWidensTheHorizon()

	/**
	 * An anonymous caller is refused with 401 and no forecast is computed.
	 *
	 * @return void
	 */
	public function testExpiryForecastRequiresAuthentication(): void {
		$service = $this->createMock(LoyaltyReportingService::class);
		$service->expects($this->never())->method('getExpiryForecast');

		$response = $this->buildController($service, authenticated: false)->expiryForecast(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testExpiryForecastRequiresAuthentication()

	/*
	 * ---------------------------------------------------------------------
	 * kpis
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The KPI endpoint answers 200 with the full KPI envelope computed over the
	 * seeded accounts, echoing the requested period back.
	 *
	 * @return void
	 */
	public function testKpisReturnsTheFullEnvelopeOverSeededAccounts(): void {
		$service = $this->realReportingService(
			accounts: [
				['@self' => ['id' => 'acc-1'], 'programmeId' => 'prog-1', 'currentBalance' => 100, 'currentTierId' => 'gold', 'status' => 'active'],
				['@self' => ['id' => 'acc-2'], 'programmeId' => 'prog-1', 'currentBalance' => 250, 'currentTierId' => 'gold', 'status' => 'active'],
				['@self' => ['id' => 'acc-3'], 'programmeId' => 'prog-1', 'currentBalance' => 50, 'status' => 'geblokkeerd'],
			],
			programme: ['pointValue' => 0.04]
		);

		$response = $this->buildController($service)->kpis(
			programmeId: 'prog-1',
			from: '2026-01-01',
			to: '2026-06-30'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertSame(2, $data['activeAccounts']);
		$this->assertSame(400, $data['outstandingPoints']);
		$this->assertSame(0.04, $data['pointValue']);
		$this->assertSame(16.0, $data['estimatedLiability']);
		$this->assertSame(['gold' => 2, 'unassigned' => 1], $data['tierDistribution']);
		$this->assertSame(['from' => '2026-01-01', 'to' => '2026-06-30'], $data['period']);
		$this->assertSame(0, $data['pointsIssued']);
		$this->assertSame(0.0, $data['breakagePercent']);
		$this->assertSame(0.0, $data['redemptionRate']);
	}//end testKpisReturnsTheFullEnvelopeOverSeededAccounts()

	/**
	 * An anonymous caller is refused with 401 and no KPIs are computed.
	 *
	 * @return void
	 */
	public function testKpisRequiresAuthentication(): void {
		$service = $this->createMock(LoyaltyReportingService::class);
		$service->expects($this->never())->method('getKpis');

		$response = $this->buildController($service, authenticated: false)->kpis(programmeId: 'prog-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testKpisRequiresAuthentication()
}//end class

/**
 * Grouped-count aggregation runner fake returning a fixed bucket list.
 *
 * Mirrors the envelope shape LoyaltyReportingService::getTierReport() reads
 * (`groups` of `key`/`value` pairs) so the pushdown path — not only the PHP
 * fallback — is exercised against known buckets.
 */
class LoyaltyGroupedCountRunnerFake extends AggregationRunner {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $groups The buckets to return.
	 */
	public function __construct(
		private array $groups,
	) {
	}//end __construct()

	/**
	 * Return the configured buckets.
	 *
	 * @param string $registerRef The register ref (unused).
	 * @param string $schemaRef The schema ref (unused).
	 * @param AggregationQuery $query The query (unused).
	 *
	 * @return array<string, mixed>
	 */
	public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array {
		return ['groups' => $this->groups, 'backend' => 'fake', 'cached' => false];
	}//end runAdhocByRef()
}//end class
