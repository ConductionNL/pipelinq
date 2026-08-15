<?php

/**
 * Contract tests for RoutingController.
 *
 * Covers `GET /api/routing/suggestions`. RoutingService and TicketService are
 * the REAL classes; only the OpenRegister ObjectService is replaced by an
 * in-memory double, so the suggestion list is asserted against SEEDED skills,
 * agent profiles and workload rather than against "the status was 200".
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\RoutingController;
use OCA\Pipelinq\Service\RoutingService;
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
 * RoutingController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 */
class RoutingControllerTest extends TestCase {
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
	 * Register/schema context is taken ONLY from `$config['filters']`, exactly
	 * as ObjectService::prepareFindAllConfig() does; the remaining filter keys
	 * are object-field equality filters; soft-deleted rows are excluded. The
	 * `find()` signature is widened so both the upstream call shape
	 * (`find($id, $extendArray)`) and the bundled stub's are accepted.
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
			}//end seed()

			/**
			 * Read one row.
			 *
			 * @param string|int $id Object id.
			 * @param mixed $arg2 Extend list (upstream) or register (stub).
			 * @param mixed $arg3 Files flag (upstream) or schema (stub).
			 *
			 * @return array<string, mixed>|object|null
			 */
			public function find(string|int $id, mixed $arg2 = '', mixed $arg3 = ''): array|object|null {
				$row = ($this->store[(string)$id] ?? null);
				if ($row === null || ($row['_deleted'] ?? null) !== null) {
					return null;
				}

				return $row;
			}//end find()

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
	 * @return RoutingController
	 */
	private function buildController(): RoutingController {
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
					'ticket_schema' => 'ticket',
					'lead_schema' => 'lead',
					'skill_schema' => 'skill',
					'agentProfile_schema' => 'agentProfile',
				];

				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new RoutingController(
			request: $this->request,
			routingService: new RoutingService(
				appConfig: $appConfig,
				container: $container,
				ticketService: new TicketService(
					container: $container,
					appConfig: $appConfig,
					logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		),
				logger: $logger,
			),
			userSession: $this->userSession,
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
	 * Seed a request ticket, one matching skill and two agent profiles.
	 *
	 * `bob` carries an open request so his workload is 1 and he sorts after
	 * the idle `alice`.
	 *
	 * @return void
	 */
	private function seedRoutingFixtures(): void {
		$this->objects->seed(
			'req-1',
			'pipelinq',
			'ticket',
			['ticketType' => 'request', 'category' => 'billing', 'status' => 'new']
		);
		$this->objects->seed(
			'skill-1',
			'pipelinq',
			'skill',
			['title' => 'Billing expert', 'isActive' => true, 'categories' => ['billing']]
		);
		$this->objects->seed(
			'skill-2',
			'pipelinq',
			'skill',
			['title' => 'Hardware expert', 'isActive' => true, 'categories' => ['hardware']]
		);
		$this->objects->seed(
			'profile-alice',
			'pipelinq',
			'agentProfile',
			[
				'userId' => 'alice',
				'displayName' => 'Alice A',
				'skills' => ['skill-1'],
				'maxConcurrent' => 5,
				'isAvailable' => true,
			]
		);
		$this->objects->seed(
			'profile-bob',
			'pipelinq',
			'agentProfile',
			[
				'userId' => 'bob',
				'displayName' => 'Bob B',
				'skills' => ['skill-1'],
				'maxConcurrent' => 5,
				'isAvailable' => true,
			]
		);
		$this->objects->seed(
			'req-open-bob',
			'pipelinq',
			'ticket',
			['ticketType' => 'request', 'category' => 'billing', 'status' => 'new', 'assignee' => 'bob']
		);
	}//end seedRoutingFixtures()

	/**
	 * Unauthenticated suggestions are refused with 401.
	 *
	 * @return void
	 */
	public function testGetSuggestionsRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testGetSuggestionsRequiresAuthentication()

	/**
	 * Missing entity parameters are refused with 400.
	 *
	 * @return void
	 */
	public function testGetSuggestionsRejectsMissingEntityParameters(): void {
		$this->signIn();
		$this->withParams(['entityType' => 'request']);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'entityType and entityId are required'], $response->getData());
	}//end testGetSuggestionsRejectsMissingEntityParameters()

	/**
	 * An entity type outside the allow-list is refused with 400.
	 *
	 * @return void
	 */
	public function testGetSuggestionsRejectsAnUnknownEntityType(): void {
		$this->signIn();
		$this->withParams(['entityType' => 'invoice', 'entityId' => 'x-1']);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid entityType'], $response->getData());
	}//end testGetSuggestionsRejectsAnUnknownEntityType()

	/**
	 * The happy path returns the SEEDED agents, ranked by ascending workload,
	 * with the matched skill title carried through.
	 *
	 * @return void
	 */
	public function testGetSuggestionsReturnsTheSeededAgentsRankedByWorkload(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();

		$this->assertSame(['suggestions', 'atCapacity', 'noMatch'], array_keys($data));
		$this->assertFalse($data['noMatch']);
		$this->assertSame(0, $data['atCapacity']);
		$this->assertCount(2, $data['suggestions']);

		$this->assertSame('alice', $data['suggestions'][0]['userId']);
		$this->assertSame('Alice A', $data['suggestions'][0]['displayName']);
		$this->assertSame(0, $data['suggestions'][0]['workload']);
		$this->assertSame(5, $data['suggestions'][0]['maxConcurrent']);
		$this->assertSame('Billing expert', $data['suggestions'][0]['matchedSkill']);
		$this->assertSame(['billing'], $data['suggestions'][0]['categories']);

		$this->assertSame('bob', $data['suggestions'][1]['userId']);
		$this->assertSame(1, $data['suggestions'][1]['workload']);
	}//end testGetSuggestionsReturnsTheSeededAgentsRankedByWorkload()

	/**
	 * An agent already at maxConcurrent is dropped from the list and counted
	 * in `atCapacity`.
	 *
	 * @return void
	 */
	public function testGetSuggestionsExcludesAgentsAtCapacity(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->objects->store['profile-bob']['maxConcurrent'] = 1;
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$response = $this->buildController()->getSuggestions();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['atCapacity']);
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame('alice', $data['suggestions'][0]['userId']);
		$this->assertFalse($data['noMatch']);
	}//end testGetSuggestionsExcludesAgentsAtCapacity()

	/**
	 * An agent flagged unavailable is dropped and is NOT reported as being at
	 * capacity.
	 *
	 * @return void
	 */
	public function testGetSuggestionsExcludesUnavailableAgents(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->objects->store['profile-bob']['isAvailable'] = false;
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$response = $this->buildController()->getSuggestions();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame('alice', $data['suggestions'][0]['userId']);
		$this->assertSame(0, $data['atCapacity']);
	}//end testGetSuggestionsExcludesUnavailableAgents()

	/**
	 * A category nobody is skilled for yields the documented `noMatch` shape,
	 * still on a 200.
	 *
	 * @return void
	 */
	public function testGetSuggestionsReportsNoMatchForAnUncoveredCategory(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->objects->store['req-1']['category'] = 'astrology';
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true],
			$response->getData()
		);
	}//end testGetSuggestionsReportsNoMatchForAnUncoveredCategory()

	/**
	 * An unknown entity id yields `noMatch` rather than a leak or a 500.
	 *
	 * @return void
	 */
	public function testGetSuggestionsReportsNoMatchForAnUnknownEntity(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->withParams(['entityType' => 'request', 'entityId' => 'ghost']);

		$response = $this->buildController()->getSuggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true],
			$response->getData()
		);
	}//end testGetSuggestionsReportsNoMatchForAnUnknownEntity()

	/**
	 * A soft-deleted agent profile must not be suggested.
	 *
	 * @return void
	 */
	public function testGetSuggestionsExcludesSoftDeletedAgentProfiles(): void {
		$this->signIn();
		$this->seedRoutingFixtures();
		$this->objects->store['profile-bob']['_deleted'] = ['deleted' => '2026-06-01T00:00:00+00:00'];
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$response = $this->buildController()->getSuggestions();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame('alice', $data['suggestions'][0]['userId']);
	}//end testGetSuggestionsExcludesSoftDeletedAgentProfiles()

	/**
	 * An unexpected backend failure is mapped to a 500 with a static message —
	 * no internal exception text on the wire.
	 *
	 * @return void
	 */
	public function testGetSuggestionsMapsBackendFailureToAStaticServerError(): void {
		$this->signIn();
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('postgres: FATAL password authentication failed'));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$map = ['register' => 'pipelinq', 'ticket_schema' => 'ticket'];
				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$controller = new RoutingController(
			request: $this->request,
			routingService: new RoutingService(
				appConfig: $appConfig,
				container: $container,
				ticketService: new TicketService(
					container: $container,
					appConfig: $appConfig,
					logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		),
				logger: $logger,
			),
			userSession: $this->userSession,
			logger: $logger,
		);

		$response = $controller->getSuggestions();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $response->getData());
	}//end testGetSuggestionsMapsBackendFailureToAStaticServerError()
}//end class
