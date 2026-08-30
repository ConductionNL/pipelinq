<?php

/**
 * Contract tests for KccWerkplekController.
 *
 * Pins the wire contract of the two KCC workspace endpoints: the aggregated
 * state read and the agent availability toggle. Both are session-scoped —
 * the acting user id comes from the session and a body-supplied user id must
 * be ignored, otherwise one agent could flip another agent's availability.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KccWerkplekController;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * KccWerkplekController wire-contract coverage.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class KccWerkplekControllerTest extends TestCase {
	/**
	 * Request parameter map used by the current test.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The workspace service double.
	 *
	 * @var KccWerkplekService
	 */
	private KccWerkplekService $service;

	/**
	 * Reset the per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->service = $this->createMock(KccWerkplekService::class);
	}//end setUp()

	/**
	 * Build the controller for the given signed-in uid (null = anonymous).
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return KccWerkplekController The controller.
	 */
	private function controller(?string $uid = 'agent-1'): KccWerkplekController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new KccWerkplekController($request,
			$this->service,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	// ------------------------------------------------------------------
	// stateAction — GET /api/kcc-werkplek/state
	// ------------------------------------------------------------------

	/**
	 * An anonymous state read is refused with 401 and never touches the store.
	 *
	 * @return void
	 */
	public function testStateActionRejectsAnonymousCaller(): void {
		$this->service->expects($this->never())->method('getWorkspaceState');

		$response = $this->controller(null)->stateAction();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testStateActionRejectsAnonymousCaller()

	/**
	 * The state read is scoped to the SESSION uid and returns the five-key
	 * workspace envelope.
	 *
	 * @return void
	 */
	public function testStateActionReturnsTheWorkspaceEnvelopeForTheSessionUser(): void {
		$this->params = ['userId' => 'somebody-else'];

		$payload = [
			'agentProfile' => [
				'id' => 'profile-1',
				'userId' => 'agent-1',
				'isAvailable' => true,
				'maxConcurrent' => 3,
				'skills' => ['nl'],
			],
			'assignedRequests' => [['id' => 'req-1']],
			'openTasks' => [],
			'queueCounts' => ['front-office' => 2],
			'queues' => [['id' => 'q-1', 'slug' => 'front-office', 'title' => 'Front office', 'sortOrder' => 0, 'maxCapacity' => null]],
		];

		$this->service->expects($this->once())
			->method('getWorkspaceState')
			->with('agent-1')
			->willReturn($payload);

		$response = $this->controller()->stateAction();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['agentProfile', 'assignedRequests', 'openTasks', 'queueCounts', 'queues'],
			array_keys($data)
		);
		$this->assertSame('agent-1', $data['agentProfile']['userId']);
		$this->assertTrue($data['agentProfile']['isAvailable']);
		$this->assertSame(2, $data['queueCounts']['front-office']);
		$this->assertSame('req-1', $data['assignedRequests'][0]['id']);
	}//end testStateActionReturnsTheWorkspaceEnvelopeForTheSessionUser()

	/**
	 * An agent with nothing assigned still gets the full envelope with empty
	 * lists — never a bare `[]` or a 500. An empty workspace and a broken
	 * lookup must be distinguishable on the wire.
	 *
	 * @return void
	 */
	public function testStateActionReturnsAFullEnvelopeForAnEmptyWorkspace(): void {
		$this->service->method('getWorkspaceState')->willReturn(
			[
				'agentProfile' => ['userId' => 'agent-1', 'isAvailable' => false, 'maxConcurrent' => 0, 'skills' => []],
				'assignedRequests' => [],
				'openTasks' => [],
				'queueCounts' => [],
				'queues' => [],
			]
		);

		$response = $this->controller()->stateAction();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('queues', $data);
		$this->assertSame([], $data['assignedRequests']);
		$this->assertFalse($data['agentProfile']['isAvailable']);
	}//end testStateActionReturnsAFullEnvelopeForAnEmptyWorkspace()

	/**
	 * A store failure is a 500 with a STATIC message — the underlying
	 * exception text must never reach the caller.
	 *
	 * @return void
	 */
	public function testStateActionMapsAStoreFailureTo500WithoutLeakingDetail(): void {
		$this->service->method('getWorkspaceState')->willThrowException(
			new \RuntimeException('pgsql: relation oc_openregister_table_1_2 does not exist')
		);

		$response = $this->controller()->stateAction();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $response->getData());
		$this->assertStringNotContainsString(
			'oc_openregister',
			(string)json_encode($response->getData())
		);
	}//end testStateActionMapsAStoreFailureTo500WithoutLeakingDetail()

	// ------------------------------------------------------------------
	// setAvailabilityAction — PUT /api/kcc-werkplek/availability
	// ------------------------------------------------------------------

	/**
	 * An anonymous availability toggle is refused with 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testSetAvailabilityRejectsAnonymousCaller(): void {
		$this->service->expects($this->never())->method('setAvailability');

		$response = $this->controller(null)->setAvailabilityAction();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required'], $response->getData());
	}//end testSetAvailabilityRejectsAnonymousCaller()

	/**
	 * A missing isAvailable is a 400 and writes nothing.
	 *
	 * @return void
	 */
	public function testSetAvailabilityRequiresTheIsAvailableParameter(): void {
		$this->service->expects($this->never())->method('setAvailability');

		$response = $this->controller()->setAvailabilityAction();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'isAvailable is required'], $response->getData());
	}//end testSetAvailabilityRequiresTheIsAvailableParameter()

	/**
	 * A non-boolean isAvailable is a 400 and writes nothing — the toggle must
	 * not coerce arbitrary truthy input into `true`.
	 *
	 * @return void
	 */
	public function testSetAvailabilityRejectsANonBooleanValue(): void {
		$this->params = ['isAvailable' => 'yes-please'];
		$this->service->expects($this->never())->method('setAvailability');

		$response = $this->controller()->setAvailabilityAction();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'isAvailable must be a boolean'], $response->getData());
	}//end testSetAvailabilityRejectsANonBooleanValue()

	/**
	 * A boolean true is accepted, scoped to the SESSION uid, and answered with
	 * the two-key profile envelope. A body-supplied `userId` is ignored —
	 * without that, any agent could mark any other agent unavailable.
	 *
	 * @return void
	 */
	public function testSetAvailabilityIgnoresABodySuppliedUserIdAndUsesTheSession(): void {
		$this->params = ['isAvailable' => true, 'userId' => 'victim-agent'];

		$this->service->expects($this->once())
			->method('setAvailability')
			->with('agent-1', true)
			->willReturn(['userId' => 'agent-1', 'isAvailable' => true]);

		$response = $this->controller()->setAvailabilityAction();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['userId' => 'agent-1', 'isAvailable' => true], $response->getData());
	}//end testSetAvailabilityIgnoresABodySuppliedUserIdAndUsesTheSession()

	/**
	 * The documented string and integer spellings of the flag are accepted and
	 * map to the right boolean.
	 *
	 * @param mixed $raw The raw request value.
	 * @param bool $expected The boolean it must map to.
	 *
	 * @return void
	 *
	 * @dataProvider availabilitySpellingProvider
	 */
	public function testSetAvailabilityAcceptsTheDocumentedSpellings(mixed $raw, bool $expected): void {
		$this->params = ['isAvailable' => $raw];

		$this->service->expects($this->once())
			->method('setAvailability')
			->with('agent-1', $expected)
			->willReturn(['userId' => 'agent-1', 'isAvailable' => $expected]);

		$response = $this->controller()->setAvailabilityAction();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData()['isAvailable']);
	}//end testSetAvailabilityAcceptsTheDocumentedSpellings()

	/**
	 * The accepted spellings of the availability flag.
	 *
	 * @return array<string, array{0: mixed, 1: bool}> The cases.
	 */
	public static function availabilitySpellingProvider(): array {
		return [
			'boolean false' => [false, false],
			'string true' => ['true', true],
			'string false' => ['false', false],
			'string one' => ['1', true],
			'string zero' => ['0', false],
			'integer one' => [1, true],
			'integer zero' => [0, false],
		];
	}//end availabilitySpellingProvider()

	/**
	 * A failed save is a 500 with a STATIC envelope — the agent's previous
	 * state is not reported as changed and no internal detail escapes.
	 *
	 * @return void
	 */
	public function testSetAvailabilityMapsASaveFailureTo500WithoutLeakingDetail(): void {
		$this->params = ['isAvailable' => true];
		$this->service->method('setAvailability')->willThrowException(
			new \RuntimeException('Failed to update agent availability: schema agentProfile missing')
		);

		$response = $this->controller()->setAvailabilityAction();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Operation failed'], $response->getData());
		$this->assertStringNotContainsString(
			'agentProfile',
			(string)json_encode($response->getData())
		);
	}//end testSetAvailabilityMapsASaveFailureTo500WithoutLeakingDetail()
}//end class
