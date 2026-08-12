<?php

/**
 * Unit tests for ForecastController.
 *
 * Verifies the wire contract of the three forecast endpoints: the paginated
 * snapshot export (JSON envelope and the CSV download variant, including the
 * server-side page-size clamp and the read scope check), override creation
 * (permission gate before validation, the 201 body, and the masked failure),
 * and override deletion (404 before the permission check, the permission
 * decision being taken from the STORED override's level rather than anything
 * the client sends, and the `{deleted: true}` body).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ForecastController;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ForecastController.
 */
class ForecastControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock access policy.
	 *
	 * @var ForecastAccessPolicy&MockObject
	 */
	private ForecastAccessPolicy $accessPolicy;

	/**
	 * Mock snapshot export service.
	 *
	 * @var ForecastExportService&MockObject
	 */
	private ForecastExportService $exportService;

	/**
	 * Mock override service.
	 *
	 * @var ForecastOverrideService&MockObject
	 */
	private ForecastOverrideService $overrideService;

	/**
	 * The controller under test.
	 *
	 * @var ForecastController
	 */
	private ForecastController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->accessPolicy = $this->createMock(ForecastAccessPolicy::class);
		$this->exportService = $this->createMock(ForecastExportService::class);
		$this->overrideService = $this->createMock(ForecastOverrideService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ForecastController(
			$this->request,
			$this->userSession,
			$this->accessPolicy,
			$this->exportService,
			$this->overrideService,
			$logger
		);
	}//end setUp()

	/**
	 * Stub the acting user (or none).
	 *
	 * @param string|null $uid The acting UID, or null for no session.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * An unauthenticated caller cannot read snapshots.
	 *
	 * @return void
	 */
	public function testSnapshotsRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->exportService->expects($this->never())->method('exportSnapshots');

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'team');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
	}//end testSnapshotsRequiresAuthentication()

	/**
	 * A missing period is a 400, not an unfiltered export of everything.
	 *
	 * @return void
	 */
	public function testSnapshotsRejectsMissingPeriod(): void {
		$this->authenticate('alice');
		$this->exportService->expects($this->never())->method('exportSnapshots');

		$response = $this->controller->snapshots(periodId: '', level: 'team');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A valid period and level are required.'], $response->getData());
	}//end testSnapshotsRejectsMissingPeriod()

	/**
	 * An unknown hierarchy level is rejected before the policy is consulted.
	 *
	 * @return void
	 */
	public function testSnapshotsRejectsUnknownLevel(): void {
		$this->authenticate('alice');
		$this->accessPolicy->expects($this->never())->method('canRead');

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'galaxy');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSnapshotsRejectsUnknownLevel()

	/**
	 * A caller outside the requested scope gets 403 and no data at all.
	 *
	 * @return void
	 */
	public function testSnapshotsForbiddenOutsideScope(): void {
		$this->authenticate('rep-bob');
		$this->accessPolicy->method('canRead')->willReturn(false);
		$this->exportService->expects($this->never())->method('exportSnapshots');

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'division');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'You may not read forecasts at this scope.'], $response->getData());
	}//end testSnapshotsForbiddenOutsideScope()

	/**
	 * The JSON variant returns the full pagination envelope.
	 *
	 * @return void
	 */
	public function testSnapshotsReturnsPaginatedEnvelope(): void {
		$this->authenticate('manager');
		$this->accessPolicy->method('canRead')->willReturn(true);
		$this->exportService->method('exportSnapshots')->willReturn(
			[
				'snapshots' => [['as_of_date' => '2026-01-31', 'owner_id' => 'rep-bob', 'commit' => 1000]],
				'total' => 7,
				'limit' => 50,
				'offset' => 0,
			]
		);

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'team');
		$data = $response->getData();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['snapshots', 'total', 'limit', 'offset'], array_keys($data));
		$this->assertCount(1, $data['snapshots']);
		$this->assertSame(7, $data['total']);
		$this->assertSame('rep-bob', $data['snapshots'][0]['owner_id']);
	}//end testSnapshotsReturnsPaginatedEnvelope()

	/**
	 * The page size is clamped server-side, so a client cannot ask for the
	 * whole snapshot table in one request.
	 *
	 * @return void
	 */
	public function testSnapshotsClampsPageSize(): void {
		$this->authenticate('manager');
		$this->accessPolicy->method('canRead')->willReturn(true);
		$this->exportService->expects($this->once())
			->method('exportSnapshots')
			->with(
				periodId: 'FY26-Q1',
				level: 'company',
				ownerId: null,
				limit: 200,
				offset: 0
			)
			->willReturn(['snapshots' => [], 'total' => 0, 'limit' => 200, 'offset' => 0]);

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'company', limit: 100000, offset: -5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testSnapshotsClampsPageSize()

	/**
	 * The CSV variant is a download response carrying the rendered CSV body
	 * and a period/level-derived filename.
	 *
	 * @return void
	 */
	public function testSnapshotsCsvReturnsDownload(): void {
		$this->authenticate('manager');
		$this->accessPolicy->method('canRead')->willReturn(true);
		$this->exportService->method('exportSnapshots')->willReturn(
			['snapshots' => [['as_of_date' => '2026-01-31']], 'total' => 1, 'limit' => 50, 'offset' => 0]
		);
		// The CSV body is rendered from the same snapshot page the JSON
		// variant would have returned, not from a second unscoped query.
		$this->exportService->expects($this->once())
			->method('toCsv')
			->with([['as_of_date' => '2026-01-31']])
			->willReturn("as_of_date\r\n2026-01-31\r\n");

		$response = $this->controller->snapshots(periodId: 'FY26-Q1', level: 'team', format: 'CSV');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame("as_of_date\r\n2026-01-31\r\n", $response->render());
	}//end testSnapshotsCsvReturnsDownload()

	/**
	 * An unauthenticated caller cannot create an override.
	 *
	 * @return void
	 */
	public function testCreateOverrideRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->overrideService->expects($this->never())->method('createOverride');

		$response = $this->controller->createOverride();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
	}//end testCreateOverrideRequiresAuthentication()

	/**
	 * A caller who may not override at the requested level is refused before
	 * the payload is even validated.
	 *
	 * @return void
	 */
	public function testCreateOverrideForbiddenForNonManager(): void {
		$this->authenticate('rep-bob');
		$this->request->method('getParam')->willReturn('team');
		$this->accessPolicy->method('canOverride')->willReturn(false);
		$this->overrideService->expects($this->never())->method('validatePayload');
		$this->overrideService->expects($this->never())->method('createOverride');

		$response = $this->controller->createOverride();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'You may not override forecasts at this scope.'], $response->getData());
	}//end testCreateOverrideForbiddenForNonManager()

	/**
	 * A payload the service rejects becomes a 400 carrying the field-level
	 * message, not a 500 and not a silent partial write.
	 *
	 * @return void
	 */
	public function testCreateOverrideRejectsInvalidPayload(): void {
		$this->authenticate('manager');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$params = ['level' => 'team', 'period_id' => 'FY26-Q1', 'reason' => 'no'];
				return ($params[$key] ?? $default);
			}
		);
		$this->accessPolicy->method('canOverride')->willReturn(true);
		$this->overrideService->method('validatePayload')
			->willReturn('A reason of at least 5 characters is required.');
		$this->overrideService->expects($this->never())->method('createOverride');

		$response = $this->controller->createOverride();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'A reason of at least 5 characters is required.'],
			$response->getData()
		);
	}//end testCreateOverrideRejectsInvalidPayload()

	/**
	 * A valid override is created as the acting manager and answered 201 with
	 * the stored record — the server-set owner, never a client-supplied one.
	 *
	 * @return void
	 */
	public function testCreateOverrideReturnsCreatedRecord(): void {
		$this->authenticate('manager');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$params = [
					'level' => 'team',
					'period_id' => 'FY26-Q1',
					'override_owner_id' => 'team-north',
					'category' => 'commit',
					'override_amount' => 5000,
					'reason' => 'Deal slipped to next quarter.',
				];
				return ($params[$key] ?? $default);
			}
		);
		$this->accessPolicy->method('canOverride')->willReturn(true);
		$this->overrideService->method('validatePayload')->willReturn(null);
		$this->overrideService->expects($this->once())
			->method('createOverride')
			->with(
				payload: [
					'period_id' => 'FY26-Q1',
					'override_owner_id' => 'team-north',
					'level' => 'team',
					'category' => 'commit',
					'override_amount' => 5000,
					'reason' => 'Deal slipped to next quarter.',
				],
				managerId: 'manager'
			)
			->willReturn(
				[
					'id' => 'ovr-1',
					'period_id' => 'FY26-Q1',
					'level' => 'team',
					'override_amount' => 5000.0,
					'original_amount' => 8000.0,
					'created_by' => 'manager',
				]
			);

		$response = $this->controller->createOverride();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('ovr-1', $data['id']);
		$this->assertSame(5000.0, $data['override_amount']);
		$this->assertSame(8000.0, $data['original_amount']);
		$this->assertSame('manager', $data['created_by']);
	}//end testCreateOverrideReturnsCreatedRecord()

	/**
	 * A persistence failure answers 500 with a generic message; the exception
	 * text never reaches the client.
	 *
	 * @return void
	 */
	public function testCreateOverrideMasksPersistenceFailure(): void {
		$this->authenticate('manager');
		$this->request->method('getParam')->willReturn('team');
		$this->accessPolicy->method('canOverride')->willReturn(true);
		$this->overrideService->method('validatePayload')->willReturn(null);
		$this->overrideService->method('createOverride')
			->willThrowException(new \RuntimeException('SQLSTATE[42P01] relation "oc_x" does not exist'));

		$response = $this->controller->createOverride();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not create the override.'], $response->getData());
		$this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
	}//end testCreateOverrideMasksPersistenceFailure()

	/**
	 * An unauthenticated caller cannot delete an override.
	 *
	 * @return void
	 */
	public function testDeleteOverrideRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->overrideService->expects($this->never())->method('deleteOverride');

		$response = $this->controller->deleteOverride('ovr-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
	}//end testDeleteOverrideRequiresAuthentication()

	/**
	 * An id that resolves to nothing is a 404 and never reaches the delete.
	 *
	 * @return void
	 */
	public function testDeleteOverrideNotFound(): void {
		$this->authenticate('manager');
		$this->overrideService->method('getOverride')->willReturn(null);
		$this->overrideService->expects($this->never())->method('deleteOverride');

		$response = $this->controller->deleteOverride('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Override not found.'], $response->getData());
	}//end testDeleteOverrideNotFound()

	/**
	 * The permission decision uses the level stored on the fetched override —
	 * a client cannot smuggle a permitted level past the check for a record
	 * that actually sits at a level they may not touch.
	 *
	 * @return void
	 */
	public function testDeleteOverrideAuthorizesAgainstStoredLevel(): void {
		$this->authenticate('team-manager');
		$this->overrideService->method('getOverride')
			->willReturn(['id' => 'ovr-1', 'level' => 'division', 'override_owner_id' => 'division-west']);
		$this->accessPolicy->expects($this->once())
			->method('canOverride')
			->with(userId: 'team-manager', level: 'division')
			->willReturn(false);
		$this->overrideService->expects($this->never())->method('deleteOverride');

		$response = $this->controller->deleteOverride('ovr-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'You may not delete this override.'], $response->getData());
	}//end testDeleteOverrideAuthorizesAgainstStoredLevel()

	/**
	 * A permitted delete answers 200 with the deletion acknowledgement.
	 *
	 * @return void
	 */
	public function testDeleteOverrideReturnsDeleted(): void {
		$this->authenticate('manager');
		$this->overrideService->method('getOverride')->willReturn(['id' => 'ovr-1', 'level' => 'team']);
		$this->accessPolicy->method('canOverride')->willReturn(true);
		$this->overrideService->expects($this->once())->method('deleteOverride')->with('ovr-1')->willReturn(true);

		$response = $this->controller->deleteOverride('ovr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => true], $response->getData());
	}//end testDeleteOverrideReturnsDeleted()

	/**
	 * A failed delete is reported as 500, never as a successful deletion —
	 * the client must not believe an override was reverted when it was not.
	 *
	 * @return void
	 */
	public function testDeleteOverrideReportsFailure(): void {
		$this->authenticate('manager');
		$this->overrideService->method('getOverride')->willReturn(['id' => 'ovr-1', 'level' => 'team']);
		$this->accessPolicy->method('canOverride')->willReturn(true);
		$this->overrideService->method('deleteOverride')->willReturn(false);

		$response = $this->controller->deleteOverride('ovr-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not delete the override.'], $response->getData());
	}//end testDeleteOverrideReportsFailure()
}//end class
