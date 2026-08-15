<?php

/**
 * Unit tests for the Pipelinq AnalyticsController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\AnalyticsController;
use OCA\Pipelinq\Service\AnalyticsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts the new overview/trends/funnels surface — happy path, bad metric,
 * missing auth, server failure — all return the documented HTTP status codes
 * and static error envelopes (no `getMessage()` leak).
 */
class AnalyticsControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock service.
	 *
	 * @var AnalyticsService&MockObject
	 */
	private AnalyticsService $service;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AnalyticsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Build a controller with the standard mocks.
	 *
	 * @return AnalyticsController
	 */
	private function buildController(): AnalyticsController {
		return new AnalyticsController(
			request: $this->request,
			analyticsService: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}

	/**
	 * GET /api/analytics/overview returns 200 with the documented JSON shape.
	 *
	 * @return void
	 */
	public function testOverviewReturnsOkWithKpiShape(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');

		$this->service->method('getOverview')->willReturn([
			'leadConversionRate' => 20.0,
			'avgRequestResolutionTime' => 4.0,
			'contactMomentVolume' => 12,
			'customerSatisfactionScore' => 4.5,
			'period' => 'month',
			'previousPeriod' => [],
		]);

		$response = $this->buildController()->overview();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(20.0, $data['leadConversionRate']);
		$this->assertSame('month', $data['period']);
	}

	/**
	 * Unauthenticated request returns 401 with a static message.
	 *
	 * @return void
	 */
	public function testOverviewReturnsUnauthorizedWithoutSession(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->overview();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Unauthorized', $response->getData()['message']);
	}

	/**
	 * trends with unsupported metric returns 400 with `Unsupported metric`.
	 *
	 * @return void
	 */
	public function testTrendsRejectsUnsupportedMetric(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('unknown');

		$this->service->method('getTrends')->willThrowException(new InvalidArgumentException('Unsupported metric'));

		$response = $this->buildController()->trends();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unsupported metric', $response->getData()['message']);
	}

	/**
	 * trends returns 200 with a `series` array.
	 *
	 * @return void
	 */
	public function testTrendsReturnsOkWithSeries(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('leads');

		$this->service->method('getTrends')->willReturn([
			'metric' => 'leads',
			'period' => 'month',
			'series' => [['date' => '2026-04-01', 'value' => 2]],
		]);

		$response = $this->buildController()->trends();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('series', $response->getData());
	}

	/**
	 * Backend failure returns 500 with `Analytics unavailable`, never the
	 * underlying exception text.
	 *
	 * @return void
	 */
	public function testOverviewReturnsServerErrorOnFailure(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');

		$this->service->method('getOverview')->willThrowException(new \RuntimeException('boom'));

		$response = $this->buildController()->overview();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$payload = $response->getData();
		$this->assertSame('Analytics unavailable', $payload['message']);
		$this->assertStringNotContainsString('boom', $payload['message']);
	}

	/**
	 * funnels returns 200 with both lead and request funnel blocks.
	 *
	 * @return void
	 */
	public function testFunnelsReturnsBothFunnels(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->service->method('getFunnels')->willReturn([
			'leadFunnel' => ['open' => 1, 'won' => 1, 'lost' => 0, 'conversionRate' => 50.0],
			'requestFunnel' => ['new' => 0, 'in_progress' => 0, 'completed' => 1, 'rejected' => 0, 'resolutionRate' => 100.0],
		]);

		$response = $this->buildController()->funnels();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('leadFunnel', $data);
		$this->assertArrayHasKey('requestFunnel', $data);
	}

	/**
	 * Build an in-memory OpenRegister ObjectService double.
	 *
	 * Register/schema context is taken ONLY from `$config['filters']`, exactly
	 * as ObjectService::prepareFindAllConfig() does; the remaining filter keys
	 * are object-field equality filters; soft-deleted rows are excluded.
	 *
	 * @return object The store.
	 */
	private function buildObjectStore(): object {
		return new class extends \OCA\OpenRegister\Service\ObjectService {
			/**
			 * Rows keyed by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Seed one row.
			 *
			 * @param string $uuid Row uuid.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $data Row body.
			 *
			 * @return void
			 */
			public function seed(string $uuid, string $register, string $schema, array $data): void {
				$data['id'] = $uuid;
				$data['@self'] = ['id' => $uuid, 'register' => $register, 'schema' => $schema];
				$this->store[$uuid] = $data;
			}

			/**
			 * Query rows.
			 *
			 * @param array<string, mixed> $config Query config.
			 * @param bool $_rbac RBAC posture.
			 * @param bool $_multitenancy Tenancy posture.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = ($config['filters'] ?? []);
				$register = (string)($filters['register'] ?? '');
				$schema = (string)($filters['schema'] ?? '');
				if ($register === '' || $schema === '') {
					return [];
				}

				$reserved = ['register', 'schema', 'registers', 'schemas', 'extend'];
				$fields = [];
				foreach ($filters as $key => $value) {
					if (in_array($key, $reserved, true) === true || str_starts_with((string)$key, '_') === true) {
						continue;
					}

					$fields[$key] = $value;
				}

				$out = [];
				foreach ($this->store as $row) {
					if (($row['_deleted'] ?? null) !== null) {
						continue;
					}

					if ((string)($row['@self']['register'] ?? '') !== $register) {
						continue;
					}

					if ((string)($row['@self']['schema'] ?? '') !== $schema) {
						continue;
					}

					$matches = true;
					foreach ($fields as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				return $out;
			}

			/**
			 * Count rows.
			 *
			 * @param array<string, mixed> $config Query config.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				return count($this->findAll(config: $config));
			}
		};
	}

	/**
	 * Build a controller wired to the REAL AnalyticsService over the given
	 * object store, so the commercial aggregate is measured end to end.
	 *
	 * @param object $store The ObjectService double.
	 *
	 * @return AnalyticsController
	 */
	private function buildRealController(object $store): AnalyticsController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$map = [
					'register' => 'pipelinq',
					'lead_schema' => 'lead',
					'posTransaction_schema' => 'posTransaction',
					'ticket_schema' => 'ticket',
				];

				return ($map[$key] ?? $default);
			}
		);

		return new AnalyticsController(
			request: $this->request,
			analyticsService: new AnalyticsService(
				appConfig: $appConfig,
				logger: $this->logger,
				ticketService: new TicketService(
					appConfig: $appConfig,
					logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		),
			),
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}

	/**
	 * GET /api/analytics/commercial returns 200 with every documented KPI key.
	 *
	 * @return void
	 */
	public function testCommercialReturnsOkWithTheDocumentedKpiShape(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');

		$this->service->method('getCommercialOverview')->willReturn([
			'revenue' => 12000.0,
			'wonValue' => 9000.0,
			'winRate' => 60.0,
			'avgDealSize' => 3000.0,
			'weightedForecast' => 4500.0,
			'openPipelineValue' => 15000.0,
			'period' => 'month',
			'previousPeriod' => ['revenue' => 8000.0, 'wonValue' => 6000.0, 'winRate' => 50.0, 'avgDealSize' => 2000.0],
		]);

		$response = $this->buildController()->commercial();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(
			[
				'revenue',
				'wonValue',
				'winRate',
				'avgDealSize',
				'weightedForecast',
				'openPipelineValue',
				'period',
				'previousPeriod',
			],
			array_keys($data)
		);
		$this->assertSame(12000.0, $data['revenue']);
		$this->assertSame(60.0, $data['winRate']);
		$this->assertSame('month', $data['period']);
		$this->assertSame(8000.0, $data['previousPeriod']['revenue']);
	}

	/**
	 * An unsupported period is refused with 400 and a static message.
	 *
	 * @return void
	 */
	public function testCommercialRejectsAnInvalidPeriod(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('fortnight');
		$this->service->method('getCommercialOverview')
			->willThrowException(new InvalidArgumentException('Invalid period'));

		$response = $this->buildController()->commercial();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid period'], $response->getData());
	}

	/**
	 * Unauthenticated commercial access is refused with 401.
	 *
	 * @return void
	 */
	public function testCommercialReturnsUnauthorizedWithoutSession(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->commercial();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}

	/**
	 * A backend failure is mapped to a 500 with a static message — no internal
	 * exception text on the wire.
	 *
	 * @return void
	 */
	public function testCommercialMapsBackendFailureToAStaticServerError(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');
		$this->service->method('getCommercialOverview')
			->willThrowException(new \RuntimeException('postgres: FATAL password authentication failed'));

		$response = $this->buildController()->commercial();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$payload = $response->getData();
		$this->assertSame('Analytics unavailable', $payload['message']);
		$this->assertStringNotContainsString('password', $payload['message']);
	}

	/**
	 * The commercial aggregate must report the SEEDED leads and POS
	 * transactions — a 200 carrying zeros over seeded data is not a healthy
	 * answer.
	 *
	 * @return void
	 */
	public function testCommercialAggregatesTheSeededLeadsAndPosTransactions(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');

		$inWindow = gmdate('Y-m-d\TH:i:s\Z', (time() - (5 * 86400)));

		$store = $this->buildObjectStore();
		$store->seed('lead-won', 'pipelinq', 'lead', [
			'status' => 'won',
			'value' => 4000,
			'stageEnteredAt' => $inWindow,
		]);
		$store->seed('lead-lost', 'pipelinq', 'lead', [
			'status' => 'lost',
			'value' => 1000,
			'stageEnteredAt' => $inWindow,
		]);
		$store->seed('lead-open', 'pipelinq', 'lead', [
			'status' => 'open',
			'value' => 10000,
			'probability' => 25,
		]);
		$store->seed('pos-1', 'pipelinq', 'posTransaction', [
			'status' => 'settled',
			'total' => 250.5,
			'settledAt' => $inWindow,
		]);
		// A void transaction must not count toward revenue.
		$store->seed('pos-void', 'pipelinq', 'posTransaction', [
			'status' => 'voided',
			'total' => 9999,
			'settledAt' => $inWindow,
		]);

		$response = $this->buildRealController($store)->commercial();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(4250.5, $data['revenue']);
		$this->assertSame(4000.0, $data['wonValue']);
		$this->assertSame(50.0, $data['winRate']);
		$this->assertSame(4000.0, $data['avgDealSize']);
		$this->assertSame(10000.0, $data['openPipelineValue']);
		$this->assertSame(2500.0, $data['weightedForecast']);
		$this->assertSame('month', $data['period']);
	}

	/**
	 * A soft-deleted lead must not enter the commercial aggregate.
	 *
	 * @return void
	 */
	public function testCommercialExcludesSoftDeletedLeads(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('month');

		$inWindow = gmdate('Y-m-d\TH:i:s\Z', (time() - (5 * 86400)));

		$store = $this->buildObjectStore();
		$store->seed('lead-won', 'pipelinq', 'lead', [
			'status' => 'won',
			'value' => 4000,
			'stageEnteredAt' => $inWindow,
		]);
		$store->seed('lead-deleted', 'pipelinq', 'lead', [
			'status' => 'won',
			'value' => 50000,
			'stageEnteredAt' => $inWindow,
			'_deleted' => ['deleted' => $inWindow],
		]);

		$data = $this->buildRealController($store)->commercial()->getData();

		$this->assertSame(4000.0, $data['wonValue']);
		$this->assertSame(4000.0, $data['revenue']);
	}
}
