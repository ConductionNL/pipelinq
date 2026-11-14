<?php

/**
 * Unit tests for FullExportController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\BackgroundJob\FullExportJob;
use OCA\Pipelinq\Controller\FullExportController;
use OCA\Pipelinq\Service\Export\FullExportState;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only an administrator starts the full export.
 *
 * @covers \OCA\Pipelinq\Controller\FullExportController
 */
class FullExportControllerTest extends TestCase {

	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid   The caller, or null for none.
	 * @param bool        $admin Whether the caller is an administrator.
	 * @param IJobList    $jobs  The job queue.
	 *
	 * @return FullExportController The controller.
	 */
	private function controller(?string $uid, bool $admin, IJobList $jobs, ?FullExportState $state = null): FullExportController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $admin && $id === $uid);

		return new FullExportController(request: $this->createMock(IRequest::class), userSession: $session, groups: $groups, jobs: $jobs, state: $state);
	}//end controller()

	/**
	 * An administrator queues the export for themselves and is told it is being prepared.
	 *
	 * @return void
	 */
	public function testAnAdministratorQueuesTheExport(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(FullExportJob::class, ['userId' => 'admin']);

		$response = $this->controller(uid: 'admin', admin: true, jobs: $jobs)->start();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame('queued', $response->getData()['status']);
	}//end testAnAdministratorQueuesTheExport()

	/**
	 * Anyone else is refused with 403 and nothing is queued.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefused(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$response = $this->controller(uid: 'sales', admin: false, jobs: $jobs)->start();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Only an administrator can export all data.'], $response->getData());
	}//end testANonAdministratorIsRefused()

	/**
	 * Without a caller the request is 401 and nothing is queued.
	 *
	 * @return void
	 */
	public function testWithoutACallerIs401(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');

		$response = $this->controller(uid: null, admin: false, jobs: $jobs)->start();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testWithoutACallerIs401()

	/**
	 * Queueing records the export as queued for the administrator (task 3.1).
	 *
	 * @return void
	 */
	public function testQueueingRecordsTheState(): void {
		$state = $this->createMock(FullExportState::class);
		$state->expects($this->once())->method('queued')->with('admin');

		$this->controller(uid: 'admin', admin: true, jobs: $this->createMock(IJobList::class), state: $state)->start();
	}//end testQueueingRecordsTheState()

	/**
	 * An administrator reads the state of the last export; anyone else is refused.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorReadsTheState(): void {
		$state = $this->createMock(FullExportState::class);
		$state->method('read')->willReturn(['state' => 'ready', 'name' => 'pipelinq-export.zip', 'fileId' => '42']);
		$jobs = $this->createMock(IJobList::class);

		$admin = $this->controller(uid: 'admin', admin: true, jobs: $jobs, state: $state)->status();
		$this->assertSame(Http::STATUS_OK, $admin->getStatus());
		$this->assertSame(['state' => 'ready', 'name' => 'pipelinq-export.zip', 'fileId' => '42'], $admin->getData());

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'sales', admin: false, jobs: $jobs, state: $state)->status()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null, admin: false, jobs: $jobs, state: $state)->status()->getStatus());
		$this->assertSame(['state' => 'none'], $this->controller(uid: 'admin', admin: true, jobs: $jobs)->status()->getData());
	}//end testOnlyAnAdministratorReadsTheState()
}//end class
