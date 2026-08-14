<?php

/**
 * Contract tests for ActivityTimelineController.
 *
 * Covers `GET /api/timeline`, `GET /api/worklog` and `POST /api/worklog` —
 * status codes, response body shape, the required-parameter rejections and the
 * entity-existence probe.
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

use OCA\Pipelinq\Controller\ActivityTimelineController;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ActivityTimelineController contract coverage.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Happy path plus at least one
 *  contract-relevant failure path for each of the three endpoints.
 */
class ActivityTimelineControllerTest extends TestCase {
	/**
	 * The request double.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The timeline service double.
	 *
	 * @var ActivityTimelineService
	 */
	private ActivityTimelineService $service;

	/**
	 * The user session double.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * In-memory OpenRegister ObjectService double used by the existence probe.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ActivityTimelineService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->objects = new class {
			/**
			 * Rows keyed by id.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Read one row.
			 *
			 * @param int|string $id Object id.
			 * @param array|null $_extend Extend list.
			 * @param bool $files Include files.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false): ?array {
				return ($this->store[(string)$id] ?? null);
			}//end find()
		};
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ActivityTimelineController
	 */
	private function buildController(): ActivityTimelineController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new ActivityTimelineController(
			request: $this->request,
			service: $this->service,
			userSession: $this->userSession,
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
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
	 * Stub the query/body parameters.
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
	 * GET /api/timeline returns 200 with the paginated envelope the service
	 * produced.
	 *
	 * @return void
	 */
	public function testGetTimelineReturnsOkWithThePaginatedEnvelope(): void {
		$this->signIn();
		$this->objects->store['client-1'] = ['id' => 'client-1'];
		$this->withParams(['entityType' => 'client', 'entityId' => 'client-1']);

		$this->service->method('getTimeline')->willReturn(
			[
				'items' => [
					['id' => 'a-1', 'type' => 'contactmoment', 'date' => '2026-06-02T10:00:00+00:00'],
					['id' => 'a-2', 'type' => 'task', 'date' => '2026-06-01T10:00:00+00:00'],
				],
				'total' => 2,
				'page' => 1,
				'pages' => 1,
			]
		);

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([2, 1, 1], [$data['total'], $data['page'], $data['pages']]);
		$this->assertCount(2, $data['items']);
		$this->assertSame('a-1', $data['items'][0]['id']);
		$this->assertSame('contactmoment', $data['items'][0]['type']);
	}//end testGetTimelineReturnsOkWithThePaginatedEnvelope()

	/**
	 * A timeline request without entityType/entityId is refused with 400 and
	 * the service is never consulted.
	 *
	 * @return void
	 */
	public function testGetTimelineRejectsMissingEntityParameters(): void {
		$this->signIn();
		$this->withParams([]);
		$this->service->expects($this->never())->method('getTimeline');

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'entityType and entityId are required'], $response->getData());
	}//end testGetTimelineRejectsMissingEntityParameters()

	/**
	 * An entity that does not exist yields 404 and the service is never
	 * consulted.
	 *
	 * @return void
	 */
	public function testGetTimelineReturnsNotFoundForAnAbsentEntity(): void {
		$this->signIn();
		$this->withParams(['entityType' => 'client', 'entityId' => 'ghost']);
		$this->service->expects($this->never())->method('getTimeline');

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Entity not found'], $response->getData());
	}//end testGetTimelineReturnsNotFoundForAnAbsentEntity()

	/**
	 * When the object service cannot answer, the entity check DENIES.
	 *
	 * Regression for #801. `objectExists()` used to `return true` from its
	 * catch — "fails open so a temporary OR outage does not block the timeline
	 * surface" — which made an unavailable object service indistinguishable
	 * from a successful check on a caller-supplied `entityId` (CWE-863). This
	 * branch had no test at all, so the behaviour change was invisible to the
	 * other 2171.
	 *
	 * @return void
	 */
	public function testGetTimelineDeniesWhenTheEntityCheckCannotBeCompleted(): void {
		$this->objects = new class {
			/**
			 * Stand in for an object service that is unavailable.
			 *
			 * @param int|string $id Object id.
			 * @param array|null $_extend Extend directives.
			 * @param bool $files Include files.
			 *
			 * @return array<string, mixed>|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false): ?array {
				throw new \RuntimeException('object service unavailable');
			}//end find()
		};

		$this->signIn();
		$this->withParams(['entityType' => 'client', 'entityId' => 'someone-elses-client']);
		// The point of the fix: an unanswerable check must not become an
		// answered one. The timeline service is never reached.
		$this->service->expects($this->never())->method('getTimeline');

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testGetTimelineDeniesWhenTheEntityCheckCannotBeCompleted()

	/**
	 * A service failure is mapped to a 500 carrying a static message — no
	 * internal exception text may reach the wire.
	 *
	 * @return void
	 */
	public function testGetTimelineMapsServiceFailureToAStaticServerError(): void {
		$this->signIn();
		$this->objects->store['client-1'] = ['id' => 'client-1'];
		$this->withParams(['entityType' => 'client', 'entityId' => 'client-1']);
		$this->service->method('getTimeline')->willThrowException(new \RuntimeException('db credentials rejected'));

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Failed to load timeline'], $response->getData());
	}//end testGetTimelineMapsServiceFailureToAStaticServerError()

	/**
	 * Unauthenticated timeline access is refused with 401.
	 *
	 * @return void
	 */
	public function testGetTimelineRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->getTimeline();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testGetTimelineRequiresAuthentication()

	/**
	 * GET /api/worklog returns 200 with the worklog envelope including the
	 * summed `totalDuration`.
	 *
	 * @return void
	 */
	public function testGetWorklogReturnsOkWithTotalDuration(): void {
		$this->signIn();
		$this->objects->store['req-1'] = ['id' => 'req-1'];
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);

		$this->service->method('getWorklog')->willReturn(
			[
				'items' => [['id' => 'w-1', 'duration' => 'PT30M']],
				'total' => 1,
				'page' => 1,
				'pages' => 1,
				'totalDuration' => 'PT30M',
			]
		);

		$response = $this->buildController()->getWorklog();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('PT30M', $data['totalDuration']);
		$this->assertSame(1, $data['total']);
		$this->assertSame('w-1', $data['items'][0]['id']);
	}//end testGetWorklogReturnsOkWithTotalDuration()

	/**
	 * A worklog request without entityType/entityId is refused with 400.
	 *
	 * @return void
	 */
	public function testGetWorklogRejectsMissingEntityParameters(): void {
		$this->signIn();
		$this->withParams(['entityType' => 'request']);
		$this->service->expects($this->never())->method('getWorklog');

		$response = $this->buildController()->getWorklog();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'entityType and entityId are required'], $response->getData());
	}//end testGetWorklogRejectsMissingEntityParameters()

	/**
	 * Unauthenticated worklog access is refused with 401.
	 *
	 * @return void
	 */
	public function testGetWorklogRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->getWorklog();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testGetWorklogRequiresAuthentication()

	/**
	 * POST /api/worklog returns 201 with the created entry.
	 *
	 * @return void
	 */
	public function testCreateWorklogReturnsCreatedWithTheNewEntry(): void {
		$this->signIn();
		$this->objects->store['req-1'] = ['id' => 'req-1'];
		$this->withParams(
			[
				'entityType' => 'request',
				'entityId' => 'req-1',
				'duration' => 'PT45M',
				'description' => 'Investigated the incident',
			]
		);

		$this->service->method('createWorklog')->willReturn(
			[
				'id' => 'w-9',
				'type' => 'worklog',
				'duration' => 'PT45M',
				'title' => 'Investigated the incident',
			]
		);

		$response = $this->buildController()->createWorklog();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('w-9', $data['id']);
		$this->assertSame('PT45M', $data['duration']);
		$this->assertSame('worklog', $data['type']);
	}//end testCreateWorklogReturnsCreatedWithTheNewEntry()

	/**
	 * A worklog creation without a duration is refused with 400 and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function testCreateWorklogRejectsAMissingDuration(): void {
		$this->signIn();
		$this->objects->store['req-1'] = ['id' => 'req-1'];
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1']);
		$this->service->expects($this->never())->method('createWorklog');

		$response = $this->buildController()->createWorklog();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'entityType, entityId and duration are required'],
			$response->getData()
		);
	}//end testCreateWorklogRejectsAMissingDuration()

	/**
	 * A service failure while writing is mapped to a 500 with a static message.
	 *
	 * @return void
	 */
	public function testCreateWorklogMapsServiceFailureToAStaticServerError(): void {
		$this->signIn();
		$this->objects->store['req-1'] = ['id' => 'req-1'];
		$this->withParams(['entityType' => 'request', 'entityId' => 'req-1', 'duration' => 'PT10M']);
		$this->service->method('createWorklog')->willThrowException(new \RuntimeException('schema not provisioned'));

		$response = $this->buildController()->createWorklog();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Failed to create worklog'], $response->getData());
	}//end testCreateWorklogMapsServiceFailureToAStaticServerError()

	/**
	 * Unauthenticated worklog creation is refused with 401 and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function testCreateWorklogRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('createWorklog');

		$response = $this->buildController()->createWorklog();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testCreateWorklogRequiresAuthentication()
}//end class
