<?php

/**
 * Unit tests for JourneyController.
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\JourneyController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JourneyController: the guard, the write path that compiles, and
 * the run log that names a refusal.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyControllerTest extends TestCase {

	/**
	 * The request the controller collects its body from.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * The journey service the controller delegates to.
	 *
	 * @var JourneyService
	 */
	private JourneyService $journeys;

	/**
	 * The session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var JourneyController
	 */
	private JourneyController $controller;

	/**
	 * Set up an authenticated, privileged caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->journeys = $this->createMock(JourneyService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new JourneyController(
			'pipelinq',
			$this->request,
			$this->journeys,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true]),
		);

		$this->authenticate('marketeer');
	}//end setUp()

	/**
	 * The index lists what the service holds.
	 *
	 * @return void
	 */
	public function testIndexListsTheJourneys(): void {
		$this->journeys->method('listJourneys')->willReturn([['uuid' => 'journey-1', 'name' => 'Win back']]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['results']);
	}//end testIndexListsTheJourneys()

	/**
	 * A journey that is gone answers 404 rather than an empty object, which
	 * a page would render as a journey with no fields.
	 *
	 * @return void
	 */
	public function testShowAnswersNotFoundForAnUnknownJourney(): void {
		$this->journeys->method('find')->willReturn(null);

		$response = $this->controller->show('journey-gone');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('not_found', $response->getData()['error']);
	}//end testShowAnswersNotFoundForAnUnknownJourney()

	/**
	 * A create without a name is refused before anything is written.
	 *
	 * @return void
	 */
	public function testCreateRefusesAJourneyWithoutAName(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->journeys->expects($this->never())->method('save');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('name_required', $response->getData()['error']);
	}//end testCreateRefusesAJourneyWithoutAName()

	/**
	 * A create hands the flow status straight back, so the form can say the
	 * journey will not run rather than showing a clean save.
	 *
	 * @return void
	 */
	public function testCreateReturnsTheFlowStatus(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key) {
				if ($key === 'name') {
					return 'Win back';
				}

				if ($key === 'trigger') {
					return ['kind' => 'leadStageChanged'];
				}

				return null;
			}
		);
		$this->journeys->method('save')->willReturn([
			'uuid' => 'journey-1',
			'name' => 'Win back',
			'flowStatus' => 'engine_missing',
		]);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('engine_missing', $response->getData()['flowStatus']);
	}//end testCreateReturnsTheFlowStatus()

	/**
	 * The client may not stamp the compilation result: the compiler owns it,
	 * and a journey claiming `compiled` that never was would look exactly
	 * like one that runs.
	 *
	 * @return void
	 */
	public function testTheClientCannotSetTheFlowStatus(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key) {
				if ($key === 'name') {
					return 'Win back';
				}

				if ($key === 'flowStatus') {
					return 'compiled';
				}

				return null;
			}
		);

		$captured = [];
		$this->journeys->method('save')->willReturnCallback(
			static function (array $payload) use (&$captured): array {
				$captured = $payload;
				return ['uuid' => 'journey-1', 'flowStatus' => 'not_compiled'];
			}
		);

		$this->controller->create();

		$this->assertArrayNotHasKey('flowStatus', $captured);
		$this->assertArrayNotHasKey('flowUuid', $captured);
		$this->assertArrayNotHasKey('flowError', $captured);
	}//end testTheClientCannotSetTheFlowStatus()

	/**
	 * The run log carries every outcome, refusals included.
	 *
	 * @return void
	 */
	public function testRunsReturnsTheRefusalsWithTheirContacts(): void {
		$this->journeys->method('runsFor')->willReturn([
			['contactId' => 'contact-1', 'state' => 'refused', 'reason' => 'no_consent'],
		]);

		$response = $this->controller->runs('journey-1');
		$results = $response->getData()['results'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('contact-1', $results[0]['contactId']);
		$this->assertSame('no_consent', $results[0]['reason']);
	}//end testRunsReturnsTheRefusalsWithTheirContacts()

	/**
	 * Compiling an unknown journey is a 404, not a silent no-op.
	 *
	 * @return void
	 */
	public function testCompileAnswersNotFoundForAnUnknownJourney(): void {
		$this->journeys->method('compile')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->compile('journey-gone')->getStatus());
	}//end testCompileAnswersNotFoundForAnUnknownJourney()

	/**
	 * Authentication is not authorization: a journey reaches customers and
	 * its run log names them, so an unauthenticated caller is refused on
	 * every entry point.
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesACallerWithoutASession(): void {
		$this->authenticate(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->show('journey-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->runs('journey-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->compile('journey-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->create()->getStatus());
	}//end testEveryEndpointRefusesACallerWithoutASession()

	/**
	 * Point the shared session mock at a uid, or at nobody.
	 *
	 * @param string|null $uid The acting uid, or null for no session.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid): void {
		if ($uid === null) {
			$this->userSession = $this->createMock(IUserSession::class);
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$this->userSession = $this->createMock(IUserSession::class);
			$this->userSession->method('getUser')->willReturn($user);
		}

		$this->controller = new JourneyController(
			'pipelinq',
			$this->request,
			$this->journeys,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true]),
		);
	}//end authenticate()
}//end class
