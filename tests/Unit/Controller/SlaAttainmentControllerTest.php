<?php

/**
 * Contract tests for SlaAttainmentController.
 *
 * Covers `GET /api/sla/attainment`. SlaAttainmentService and TicketService are
 * the REAL classes; only the OpenRegister ObjectService is replaced by an
 * in-memory double that mirrors the upstream contract, so the assertions here
 * measure whether SEEDED SLA data actually reaches the wire.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SlaAttainmentController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SlaAttainmentController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 */
class SlaAttainmentControllerTest extends TestCase {
	/**
	 * In-memory OpenRegister ObjectService double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The user session double.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * The request double.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->buildObjectStore();
	}//end setUp()

	/**
	 * Build the in-memory ObjectService double.
	 *
	 * Register/schema context is taken ONLY from `$config['filters']['register']`
	 * and `$config['filters']['schema']`, exactly as OpenRegister's
	 * ObjectService::prepareFindAllConfig() does; a query that resolves no
	 * context yields an empty list, exactly as MagicMapper::findAll() does.
	 * Soft-deleted rows are excluded.
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
			 * Every findAll() config, in call order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

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
			}//end seed()

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
				$this->queries[] = $config;

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
			}//end findAll()

			/**
			 * Count rows.
			 *
			 * @param array<string, mixed> $config Query config.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				return count($this->findAll(config: $config));
			}//end count()
		};
	}//end buildObjectStore()

	/**
	 * Build the controller under test.
	 *
	 * @return SlaAttainmentController
	 */
	private function buildController(): SlaAttainmentController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$map = [
					'register' => 'pipelinq',
					'sla_register' => 'pipelinq',
					'sla_breach_event_schema' => 'slaBreachEvent',
					'callback_schema' => 'callback',
					'ticket_schema' => 'ticket',
				];

				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new SlaAttainmentController(
			request: $this->request,
			attainment: new SlaAttainmentService(
				appConfig: $appConfig,
				ticketService: new TicketService(
					appConfig: $appConfig,
					logger: $logger,
					objectService: $this->objects,
				),
				logger: $logger,
				container: $container,
			),
			userSession: $this->userSession,
			accessPolicy: $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
			logger: $logger,
		);
	}//end buildController()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Stub the query parameters.
	 *
	 * @param array<string, mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
	}//end withParams()

	/**
	 * Seed one breached and one fully-met tracked object inside the day bucket.
	 *
	 * @param string $date The YYYY-MM-DD bucket date.
	 *
	 * @return void
	 */
	private function seedAttainmentFixtures(string $date): void {
		$this->objects->seed(
			'breach-1',
			'pipelinq',
			'slaBreachEvent',
			[
				'breachedAt' => $date . 'T09:00:00+00:00',
				'targetKind' => 'resolution',
				'policyId' => 'gold',
				'resolvedAt' => $date . 'T11:00:00+00:00',
			]
		);

		$this->objects->seed(
			'ticket-met-1',
			'pipelinq',
			'ticket',
			[
				'ticketType' => 'request',
				'slaStatus' => [
					'policyId' => 'gold',
					'targets' => [
						[
							'kind' => 'resolution',
							'status' => 'met',
							'withAt' => $date . 'T10:00:00+00:00',
						],
					],
				],
			]
		);
	}//end seedAttainmentFixtures()

	/**
	 * Unauthenticated access is refused with 401.
	 *
	 * @return void
	 */
	public function testAttainmentRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams([]);

		$response = $this->buildController()->attainment();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'notAuthenticated'], $response->getData());
	}//end testAttainmentRequiresAuthentication()

	/**
	 * An unsupported bucket is rejected with 400 and a machine-readable code.
	 *
	 * @return void
	 */
	public function testAttainmentRejectsAnInvalidBucket(): void {
		$this->signIn();
		$this->withParams(['bucket' => 'fortnight', 'groupBy' => 'policy']);

		$response = $this->buildController()->attainment();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidBucket'], $response->getData());
	}//end testAttainmentRejectsAnInvalidBucket()

	/**
	 * An unsupported grouping is rejected with 400.
	 *
	 * @return void
	 */
	public function testAttainmentRejectsAnInvalidGroupBy(): void {
		$this->signIn();
		$this->withParams(['bucket' => 'month', 'groupBy' => 'zodiac']);

		$response = $this->buildController()->attainment();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidGroupBy'], $response->getData());
	}//end testAttainmentRejectsAnInvalidGroupBy()

	/**
	 * With no data at all, the endpoint answers 200 and the documented
	 * zero-valued envelope with a resolved range.
	 *
	 * @return void
	 */
	public function testAttainmentReturnsTheDocumentedEnvelopeWhenThereIsNoData(): void {
		$this->signIn();
		$this->withParams(['bucket' => 'day', 'date' => '2026-06-10', 'groupBy' => 'policy']);

		$response = $this->buildController()->attainment();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		foreach (
			[
				'attainment',
				'attainmentPercent',
				'total',
				'met',
				'breached',
				'inFlightBreached',
				'closedBreached',
				'range',
				'details',
			] as $key
		) {
			$this->assertArrayHasKey($key, $data);
		}

		$this->assertSame(0, $data['total']);
		$this->assertSame('2026-06-10T00:00:00+00:00', $data['range']['start']);
		$this->assertSame('2026-06-11T00:00:00+00:00', $data['range']['end']);
		$this->assertSame(['byTarget', 'byGroup'], array_keys($data['details']));
	}//end testAttainmentReturnsTheDocumentedEnvelopeWhenThereIsNoData()

	/**
	 * With one breached event and one fully-met tracked object seeded inside
	 * the bucket, the endpoint must report them — a 200 carrying zeros over
	 * seeded data is not a healthy answer.
	 *
	 * @return void
	 */
	public function testAttainmentCountsTheSeededBreachAndMetObjects(): void {
		$this->signIn();
		$this->seedAttainmentFixtures(date: '2026-06-10');
		$this->withParams(['bucket' => 'day', 'date' => '2026-06-10', 'groupBy' => 'policy']);

		$response = $this->buildController()->attainment();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// The met leg reads through TicketService, whose query carries its
		// register/schema inside `filters` — it works, and proves the store
		// double is wired correctly and that this endpoint CAN see seeded data.
		$this->assertSame(1, $data['met']);


		$this->assertSame(1, $data['breached']);
		$this->assertSame(2, $data['total']);
		$this->assertSame(0.5, $data['attainment']);
		$this->assertSame(50.0, $data['attainmentPercent']);
		$this->assertArrayHasKey('resolution', $data['details']['byTarget']);
	}//end testAttainmentCountsTheSeededBreachAndMetObjects()

	/**
	 * A soft-deleted tracked object must not be counted toward attainment.
	 *
	 * @return void
	 */
	public function testAttainmentExcludesSoftDeletedTrackedObjects(): void {
		$this->signIn();
		$this->objects->seed(
			'ticket-met-deleted',
			'pipelinq',
			'ticket',
			[
				'ticketType' => 'request',
				'_deleted' => ['deleted' => '2026-06-10T12:00:00+00:00'],
				'slaStatus' => [
					'policyId' => 'gold',
					'targets' => [
						['kind' => 'resolution', 'status' => 'met', 'withAt' => '2026-06-10T10:00:00+00:00'],
					],
				],
			]
		);
		$this->withParams(['bucket' => 'day', 'date' => '2026-06-10', 'groupBy' => 'policy']);

		$response = $this->buildController()->attainment();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['met']);
		$this->assertSame(0, $response->getData()['total']);
	}//end testAttainmentExcludesSoftDeletedTrackedObjects()
}//end class
