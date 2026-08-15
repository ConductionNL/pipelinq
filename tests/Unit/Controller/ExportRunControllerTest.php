<?php

/**
 * Unit tests for ExportRunController.
 *
 * Verifies the export-run history + retry API: the access gate (401 when
 * unauthenticated, 403 when not an export admin/analyst — ADR-005), the
 * filtered run list, the run detail (with snapshots), a successful retry that
 * re-enqueues a pending run from the originating job, and the rejection of a
 * retry on a non-retryable run status.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ExportRunController;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExportRunController.
 */
class ExportRunControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock run service.
	 *
	 * @var ExportRunService&MockObject
	 */
	private ExportRunService $runs;

	/**
	 * Mock job service.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService $jobs;

	/**
	 * Mock access policy.
	 *
	 * @var ExportAccessPolicy&MockObject
	 */
	private ExportAccessPolicy $policy;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ExportRunController
	 */
	private ExportRunController $controller;

	/**
	 * Set up the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->runs = $this->createMock(ExportRunService::class);
		$this->jobs = $this->createMock(ExportJobService::class);
		$this->policy = $this->createMock(ExportAccessPolicy::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->controller = new ExportRunController($this->request,
			$this->runs,
			$this->jobs,
			$this->policy,
			$this->userSession,
			$l10n,
			$logger
		);
	}//end setUp()

	/**
	 * Stub the acting user (or none) and the policy decision.
	 *
	 * @param string|null $uid The acting UID, or null for no session.
	 * @param bool $isAdmin Whether the user is an export admin.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid, bool $isAdmin = true): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->policy->method('isExportAdmin')->willReturn($isAdmin);
	}//end authenticate()

	/**
	 * An unauthenticated caller gets 401.
	 *
	 * @return void
	 */
	public function testListRunsRequiresAuthentication(): void {
		$this->authenticate(null);

		$response = $this->controller->listRuns();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testListRunsRequiresAuthentication()

	/**
	 * A non-admin/analyst caller gets 403.
	 *
	 * @return void
	 */
	public function testListRunsForbiddenForNonAdmin(): void {
		$this->authenticate('regular-user', isAdmin: false);

		$response = $this->controller->listRuns();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testListRunsForbiddenForNonAdmin()

	/**
	 * An admin gets the filtered run list.
	 *
	 * @return void
	 */
	public function testListRunsReturnsRuns(): void {
		$this->authenticate('analyst');
		$this->request->method('getParam')->willReturn('');
		$this->runs->method('listRuns')->willReturn([['id' => 'r1', 'status' => 'succeeded']]);

		$response = $this->controller->listRuns();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['runs']);
	}//end testListRunsReturnsRuns()

	/**
	 * Run detail returns the run plus its schema snapshots.
	 *
	 * @return void
	 */
	public function testShowRunReturnsRunAndSnapshots(): void {
		$this->authenticate('analyst');
		$this->runs->method('getRun')->with(runId: 'r1')->willReturn(['id' => 'r1', 'status' => 'succeeded']);
		$this->runs->method('listSnapshots')->with(runId: 'r1')->willReturn([['pipelinqSchemaName' => 'client']]);

		$response = $this->controller->showRun('r1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('r1', $data['run']['id']);
		$this->assertCount(1, $data['snapshots']);
	}//end testShowRunReturnsRunAndSnapshots()

	/**
	 * Retrying a failed run enqueues a fresh pending run from the job.
	 *
	 * @return void
	 */
	public function testRetryRunRequeuesFromJob(): void {
		$this->authenticate('analyst');
		$this->runs->method('getRun')->willReturn(['id' => 'r1', 'jobId' => 'job-1', 'status' => 'failed']);
		$this->jobs->method('getJob')->with(id: 'job-1')->willReturn(['id' => 'job-1', 'mode' => 'full']);
		$this->runs->expects($this->once())
			->method('createPendingRun')
			->willReturn(['id' => 'r2', 'status' => 'pending']);

		$response = $this->controller->retryRun('r1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('pending', $response->getData()['run']['status']);
	}//end testRetryRunRequeuesFromJob()

	/**
	 * Retrying a succeeded run is rejected with 422 (only failed/partial retry).
	 *
	 * @return void
	 */
	public function testRetryRunRejectsNonRetryableStatus(): void {
		$this->authenticate('analyst');
		$this->runs->method('getRun')->willReturn(['id' => 'r1', 'jobId' => 'job-1', 'status' => 'succeeded']);
		$this->runs->expects($this->never())->method('createPendingRun');

		$response = $this->controller->retryRun('r1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testRetryRunRejectsNonRetryableStatus()
}//end class
