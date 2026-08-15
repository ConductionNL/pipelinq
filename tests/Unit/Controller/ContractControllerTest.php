<?php

/**
 * Contract tests for ContractController.
 *
 * Covers `POST /api/contracts/{id}/transition` (the guarded lifecycle state
 * machine) and `GET /api/contracts/metrics/renewal` (a period aggregate).
 *
 * ContractService and RecurringRevenueService are the REAL classes; only the
 * OpenRegister ObjectService is replaced by an in-memory double that mirrors
 * the upstream contract (see the docblocks on each double for the exact
 * behaviour reproduced).
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

use OCA\Pipelinq\Controller\ContractController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RecurringRevenueService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ContractController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Multiple rejection paths of
 *  one state machine plus the aggregate endpoint.
 */
class ContractControllerTest extends TestCase {
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
	 * The group manager double.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * The request double.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Set up the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->objects = $this->buildTolerantStore();
	}//end setUp()

	/**
	 * A store whose `find()` swallows any extra positional arguments.
	 *
	 * Mirrors the deliberately-simplified OpenRegister surface the unit tier
	 * has always assumed, so the lifecycle guards below can be exercised even
	 * where the caller's argument order does not line up with the upstream
	 * signature.
	 *
	 * @return object The store.
	 */
	private function buildTolerantStore(): object {
		return new class {
			/**
			 * Rows keyed by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Every saveObject() payload, in call order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Seed one row.
			 *
			 * @param string $uuid Row uuid.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $data Row body.
			 *
			 * @return void
			 */
			public function seed(string $uuid, string $schema, array $data): void {
				$data['id'] = $uuid;
				$data['@self'] = ['id' => $uuid, 'register' => 'pipelinq', 'schema' => $schema];
				$this->store[$uuid] = $data;
			}//end seed()

			/**
			 * Read one row.
			 *
			 * ⚠️ This signature MIRRORS `OCA\OpenRegister\Service\ObjectService::find()`
			 * parameter for parameter, and that is load-bearing. It used to be
			 * `find(int|string $id, mixed ...$rest)` — "tolerating any trailing
			 * arguments" — which silently absorbed
			 * `find($id, $registerId, $schemaId)`, the positional call the
			 * controller actually shipped. Against the real service that call
			 * puts a string into `?array $_extend` and raises a TypeError, so
			 * `ContractController::transition` returned 404 to *every* caller,
			 * owner included, and its `isAuthorized()` guard never ran (#801).
			 * A double shaped to what the caller passes cannot detect that the
			 * caller is wrong.
			 *
			 * @param int|string $id Object id.
			 * @param array|null $_extend Extend directives.
			 * @param bool $files Include files.
			 * @param mixed $register Register id or slug.
			 * @param mixed $schema Schema id or slug.
			 * @param bool $_rbac RBAC posture.
			 * @param bool $_multitenancy Tenancy posture.
			 * @param bool $_render Render posture.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
			): ?array {
				$row = ($this->store[(string)$id] ?? null);
				if ($row === null || ($row['_deleted'] ?? null) !== null) {
					return null;
				}

				return $row;
			}//end find()

			/**
			 * Query rows scoped by the register/schema carried in `filters`.
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

				$out = [];
				foreach ($this->store as $row) {
					if (($row['_deleted'] ?? null) !== null) {
						continue;
					}

					if ((string)($row['@self']['schema'] ?? '') === $schema) {
						$out[] = $row;
					}
				}

				return $out;
			}//end findAll()

			/**
			 * Write one row.
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param array|null $extend Extend list.
			 * @param mixed $register Register context.
			 * @param mixed $schema Schema context.
			 * @param string|null $uuid Target uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
			): array {
				$key = ($uuid ?? ('new-' . (count($this->store) + 1)));
				$object['id'] = $key;
				$object['@self'] = ['id' => $key, 'register' => (string)$register, 'schema' => (string)$schema];
				$this->store[$key] = $object;
				$this->saves[] = $object;
				return $object;
			}//end saveObject()
		};
	}//end buildTolerantStore()

	/**
	 * A store declaring the upstream OpenRegister ObjectService signature.
	 *
	 * `find(int|string $id, ?array $_extend = [], bool $files = false,
	 * Register|string|int|null $register = null, Schema|string|int|null
	 * $schema = null, ...)` — i.e. the second and third positional parameters
	 * are the extend list and the files flag, NOT register and schema.
	 *
	 * @return object The store.
	 */
	private function buildUpstreamSignatureStore(): object {
		return new class {
			/**
			 * Rows keyed by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Every saveObject() payload, in call order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Seed one row.
			 *
			 * @param string $uuid Row uuid.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $data Row body.
			 *
			 * @return void
			 */
			public function seed(string $uuid, string $schema, array $data): void {
				$data['id'] = $uuid;
				$data['@self'] = ['id' => $uuid, 'register' => 'pipelinq', 'schema' => $schema];
				$this->store[$uuid] = $data;
			}//end seed()

			/**
			 * Read one row.
			 *
			 * @param int|string $id Object id.
			 * @param array|null $_extend Extend list.
			 * @param bool $files Include files.
			 * @param mixed $register Register context.
			 * @param mixed $schema Schema context.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
			): ?array {
				return ($this->store[(string)$id] ?? null);
			}//end find()

			/**
			 * Query rows scoped by the register/schema carried in `filters`.
			 *
			 * @param array<string, mixed> $config Query config.
			 * @param bool $_rbac RBAC posture.
			 * @param bool $_multitenancy Tenancy posture.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = ($config['filters'] ?? []);
				if ((string)($filters['register'] ?? '') === '' || (string)($filters['schema'] ?? '') === '') {
					return [];
				}

				return array_values($this->store);
			}//end findAll()

			/**
			 * Write one row.
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param array|null $extend Extend list.
			 * @param mixed $register Register context.
			 * @param mixed $schema Schema context.
			 * @param string|null $uuid Target uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
			): array {
				$key = ($uuid ?? 'new');
				$object['id'] = $key;
				$this->store[$key] = $object;
				$this->saves[] = $object;
				return $object;
			}//end saveObject()
		};
	}//end buildUpstreamSignatureStore()

	/**
	 * Build the controller against the given object store.
	 *
	 * @param object $store The ObjectService double.
	 *
	 * @return ContractController
	 */
	private function buildController(object $store): ContractController {
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
				$map = ['register' => 'pipelinq', 'contract_schema' => 'contract'];
				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new ContractController(
			request: $this->request,
			contractService: new ContractService(
				appConfig: $appConfig,
				container: $container,
				logger: $logger,
			),
			revenueService: new RecurringRevenueService(
				appConfig: $appConfig,
				container: $container,
				logger: $logger,
			),
			userSession: $this->userSession,
			accessPolicy: new ObjectOwnerAccessPolicy(groupManager: $this->groupManager),
			container: $container,
			logger: $logger,
		);
	}//end buildController()

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'owner-1'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Stub the request body.
	 *
	 * @param array<string, mixed> $body The body values.
	 *
	 * @return void
	 */
	private function withBody(array $body): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default)
		);
		$this->request->method('getParams')->willReturn($body);
	}//end withBody()

	/**
	 * Unauthenticated transition is refused with 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testTransitionRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($this->objects)->transition(id: 'c-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
		$this->assertSame([], $this->objects->saves);
	}//end testTransitionRequiresAuthentication()

	/**
	 * An unknown contract id is 404 and writes nothing.
	 *
	 * @return void
	 */
	public function testTransitionOnUnknownContractReturnsNotFound(): void {
		$this->signIn();
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($this->objects)->transition(id: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Contract not found'], $response->getData());
		$this->assertSame([], $this->objects->saves);
	}//end testTransitionOnUnknownContractReturnsNotFound()

	/**
	 * A caller who neither owns the contract nor sits in a privileged group is
	 * refused with 403 and the contract is untouched.
	 *
	 * @return void
	 */
	public function testTransitionByAnUnrelatedUserIsForbidden(): void {
		$this->signIn(uid: 'intruder');
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->objects->seed('c-1', 'contract', ['status' => 'draft', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($this->objects)->transition(id: 'c-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $response->getData());
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('draft', $this->objects->store['c-1']['status']);
	}//end testTransitionByAnUnrelatedUserIsForbidden()

	/**
	 * A legal transition (draft -> active) returns 200 with the saved contract
	 * and persists the new status.
	 *
	 * @return void
	 */
	public function testTransitionAppliesALegalEdgeAndPersistsIt(): void {
		$this->signIn();
		$this->objects->seed('c-2', 'contract', ['status' => 'draft', 'ownerId' => 'owner-1', 'title' => 'Support']);
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($this->objects)->transition(id: 'c-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('active', $data['status']);
		$this->assertSame('Support', $data['title']);
		$this->assertSame('active', $this->objects->store['c-2']['status']);
		$this->assertCount(1, $this->objects->saves);
	}//end testTransitionAppliesALegalEdgeAndPersistsIt()

	/**
	 * A transition out of a terminal state is rejected with 422 and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function testTransitionOutOfATerminalStateIsRejectedAndNotPersisted(): void {
		$this->signIn();
		$this->objects->seed('c-3', 'contract', ['status' => 'churned', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($this->objects)->transition(id: 'c-3');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('terminal state', $response->getData()['message']);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('churned', $this->objects->store['c-3']['status']);
	}//end testTransitionOutOfATerminalStateIsRejectedAndNotPersisted()

	/**
	 * A status outside the schema enum is rejected with 422 and not persisted.
	 *
	 * @return void
	 */
	public function testTransitionToAnUnknownStatusIsRejectedAndNotPersisted(): void {
		$this->signIn();
		$this->objects->seed('c-4', 'contract', ['status' => 'active', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'archived']);

		$response = $this->buildController($this->objects)->transition(id: 'c-4');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('Unknown contract status', $response->getData()['message']);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('active', $this->objects->store['c-4']['status']);
	}//end testTransitionToAnUnknownStatusIsRejectedAndNotPersisted()

	/**
	 * `expiring` is reserved for the renewal engine — a caller-driven request
	 * for it is rejected with 422 and not persisted.
	 *
	 * @return void
	 */
	public function testTransitionToExpiringIsReservedForTheEngine(): void {
		$this->signIn();
		$this->objects->seed('c-5', 'contract', ['status' => 'active', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'expiring']);

		$response = $this->buildController($this->objects)->transition(id: 'c-5');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('renewal engine', $response->getData()['message']);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('active', $this->objects->store['c-5']['status']);
	}//end testTransitionToExpiringIsReservedForTheEngine()

	/**
	 * `renewed` without a won renewal lead is rejected with 422, no successor
	 * draft is created and nothing is persisted.
	 *
	 * @return void
	 */
	public function testTransitionToRenewedWithoutAWonLeadIsRejected(): void {
		$this->signIn();
		$this->objects->seed('c-6', 'contract', ['status' => 'active', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'renewed']);

		$response = $this->buildController($this->objects)->transition(id: 'c-6');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('won renewal lead', $response->getData()['message']);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('active', $this->objects->store['c-6']['status']);
	}//end testTransitionToRenewedWithoutAWonLeadIsRejected()

	/**
	 * `cancelled` without a cancellation reason is rejected with 422 and not
	 * persisted.
	 *
	 * @return void
	 */
	public function testTransitionToCancelledWithoutAReasonIsRejected(): void {
		$this->signIn();
		$this->objects->seed('c-7', 'contract', ['status' => 'active', 'ownerId' => 'owner-1']);
		$this->withBody(['status' => 'cancelled']);

		$response = $this->buildController($this->objects)->transition(id: 'c-7');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('cancellationReason', $response->getData()['message']);
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('active', $this->objects->store['c-7']['status']);
	}//end testTransitionToCancelledWithoutAReasonIsRejected()

	/**
	 * Against an ObjectService declaring the upstream `find()` signature, the
	 * contract lookup must still resolve an existing contract — the endpoint
	 * may not answer 404 for a row that is present.
	 *
	 * @return void
	 */
	public function testTransitionResolvesTheContractAgainstTheUpstreamFindSignature(): void {
		$store = $this->buildUpstreamSignatureStore();
		$store->seed('c-8', 'contract', ['status' => 'draft', 'ownerId' => 'owner-1']);
		$this->signIn();
		$this->withBody(['status' => 'active']);

		$response = $this->buildController($store)->transition(id: 'c-8');

		// ⚠️ This assertion used to sit behind
		// `if ($response->getStatus() === 404) { markTestSkipped(<the bug>); }`.
		// A conditional self-skip whose condition IS the defect can never fail:
		// it was green for the whole life of the bug and would have gone green
		// again once fixed, so it reported "pass" in both worlds and the
		// security control it protects never ran. The skip is removed; the
		// assertion now stands on its own.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('active', $response->getData()['status']);
	}//end testTransitionResolvesTheContractAgainstTheUpstreamFindSignature()

	/**
	 * Unauthenticated renewal metrics are refused with 401.
	 *
	 * @return void
	 */
	public function testRenewalMetricsRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withBody([]);

		$response = $this->buildController($this->objects)->renewalMetrics();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testRenewalMetricsRequiresAuthentication()

	/**
	 * GET /api/contracts/metrics/renewal returns the aggregate computed over
	 * the SEEDED contracts — not zeros.
	 *
	 * Two renewed and one churned contract close inside the window, so the
	 * renewal rate is 2/3 and the churned MRR is the churned contract's
	 * quarterly value normalised to a month.
	 *
	 * @return void
	 */
	public function testRenewalMetricsAggregatesTheSeededContracts(): void {
		$this->signIn();
		$this->objects->seed(
			'c-10',
			'contract',
			['status' => 'renewed', 'endDate' => '2026-06-01', 'billingInterval' => 'monthly', 'valuePerInterval' => 100]
		);
		$this->objects->seed(
			'c-11',
			'contract',
			['status' => 'renewed', 'endDate' => '2026-06-15', 'billingInterval' => 'annual', 'valuePerInterval' => 1200]
		);
		$this->objects->seed(
			'c-12',
			'contract',
			['status' => 'churned', 'endDate' => '2026-06-20', 'billingInterval' => 'quarterly', 'valuePerInterval' => 300]
		);
		// Outside the requested window — must not be counted.
		$this->objects->seed(
			'c-13',
			'contract',
			['status' => 'churned', 'endDate' => '2020-01-01', 'billingInterval' => 'monthly', 'valuePerInterval' => 999]
		);
		$this->withBody(['from' => '2026-06-01', 'to' => '2026-06-30']);

		$response = $this->buildController($this->objects)->renewalMetrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'renewed' => 2,
				'churned' => 1,
				'renewalRate' => 66.7,
				'churnedMrr' => 100.0,
			],
			$response->getData()
		);
	}//end testRenewalMetricsAggregatesTheSeededContracts()

	/**
	 * A soft-deleted contract must not be counted by the aggregate.
	 *
	 * @return void
	 */
	public function testRenewalMetricsExcludesSoftDeletedContracts(): void {
		$this->signIn();
		$this->objects->seed(
			'c-20',
			'contract',
			['status' => 'renewed', 'endDate' => '2026-06-01', 'billingInterval' => 'monthly', 'valuePerInterval' => 100]
		);
		$this->objects->seed(
			'c-21',
			'contract',
			[
				'status' => 'churned',
				'endDate' => '2026-06-02',
				'billingInterval' => 'monthly',
				'valuePerInterval' => 500,
				'_deleted' => ['deleted' => '2026-06-03T00:00:00+00:00'],
			]
		);
		$this->withBody(['from' => '2026-06-01', 'to' => '2026-06-30']);

		$response = $this->buildController($this->objects)->renewalMetrics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['renewed']);
		$this->assertSame(0, $response->getData()['churned']);
		$this->assertSame(0.0, $response->getData()['churnedMrr']);
	}//end testRenewalMetricsExcludesSoftDeletedContracts()
}//end class
