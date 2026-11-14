<?php

/**
 * Unit tests for PosStaffController.
 *
 * Staff management is admin-only and every per-record action runs the
 * per-object scope check before it reads or writes; PIN authentication is
 * open to any signed-in user but needs both a staff id and a PIN. Service
 * errors map to 404, 403, 422 or a logged 500.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosStaffController;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PosStaffController.
 *
 * @covers \OCA\Pipelinq\Controller\PosStaffController
 * @uses   \OCA\Pipelinq\Controller\PosResponses
 */
class PosStaffControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock staff service.
	 *
	 * @var PosStaffService&MockObject
	 */
	private PosStaffService&MockObject $service;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Controller under test.
	 *
	 * @var PosStaffController
	 */
	private PosStaffController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: PosStaffService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PosStaffController(
			request: $this->request,
			service: $this->service,
			userSession: $this->session,
			groupManager: $this->groupManager,
			l10n: $l10n,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Sign in as the given user, admin or not.
	 *
	 * @param string $uid The user id.
	 * @param bool $admin Whether the user is an NC admin.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
	}//end signIn()

	/**
	 * Without a session every management action answers 401 and the service is untouched.
	 *
	 * @return void
	 */
	public function testManagementRequiresASession(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method(self::anything());

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->index()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->show(id: 's1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->update(id: 's1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->destroy(id: 's1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->authenticate()->getStatus());
	}//end testManagementRequiresASession()

	/**
	 * A signed-in non-admin cannot manage staff.
	 *
	 * @return void
	 */
	public function testManagementRequiresAnAdmin(): void {
		$this->signIn(uid: 'cashier', admin: false);
		$this->service->expects(self::never())->method(self::anything());

		$response = $this->controller->destroy(id: 's1');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'Admin privileges required'], actual: $response->getData());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->index()->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->show(id: 's1')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->update(id: 's1')->getStatus());
	}//end testManagementRequiresAnAdmin()

	/**
	 * An admin lists and reads staff; the read is scope-checked for the caller first.
	 *
	 * @return void
	 */
	public function testAdminListsAndReadsStaff(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->service->method('listStaff')->willReturn([['id' => 's1']]);
		$this->service->expects(self::once())->method('authorizeStaff')->with('s1', 'admin');
		$this->service->method('getStaff')->with('s1')->willReturn(['id' => 's1', 'displayName' => 'Sam']);

		self::assertSame(expected: ['staff' => [['id' => 's1']]], actual: $this->controller->index()->getData());
		self::assertSame(
			expected: ['staff' => ['id' => 's1', 'displayName' => 'Sam']],
			actual: $this->controller->show(id: 's1')->getData()
		);
	}//end testAdminListsAndReadsStaff()

	/**
	 * Create and update send the request payload; update and delete are scope-checked first.
	 *
	 * @return void
	 */
	public function testAdminWritesStaffFromTheRequest(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->request->method('getParam')->willReturnMap(
			[
				['displayName', '', 'Sam'],
				['userId', '', 'sam'],
				['posRole', '', 'cashier'],
				['isActive', true, false],
				['pin', '', '1234'],
			]
		);
		$expected = ['displayName' => 'Sam', 'userId' => 'sam', 'posRole' => 'cashier', 'isActive' => false, 'pin' => '1234'];
		$this->service->expects(self::exactly(count: 2))->method('authorizeStaff')->with('s1', 'admin');
		$this->service->expects(self::exactly(count: 2))->method('saveStaff')
			->willReturnCallback(
				static function (array $data, string $id = '') use ($expected): array {
					self::assertSame(expected: $expected, actual: $data);
					if ($id === '') {
						return ['id' => 'new'];
					}

					return ['id' => $id];
				}
			);
		$this->service->expects(self::once())->method('deleteStaff')->with('s1');

		self::assertSame(expected: ['staff' => ['id' => 'new']], actual: $this->controller->create()->getData());
		self::assertSame(expected: ['staff' => ['id' => 's1']], actual: $this->controller->update(id: 's1')->getData());
		self::assertSame(expected: ['deleted' => true], actual: $this->controller->destroy(id: 's1')->getData());
	}//end testAdminWritesStaffFromTheRequest()

	/**
	 * A staff record outside the app's scope stops the update before anything is saved.
	 *
	 * @return void
	 */
	public function testOutOfScopeUpdateNeverSaves(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->service->method('authorizeStaff')->willThrowException(new OCSNotFoundException('Staff not found'));
		$this->service->expects(self::never())->method('saveStaff');

		$this->expectException(exception: OCSNotFoundException::class);

		$this->controller->update(id: 'foreign');
	}//end testOutOfScopeUpdateNeverSaves()

	/**
	 * Service errors map to their status; an unexpected one is logged and hidden.
	 *
	 * @return void
	 */
	public function testServiceErrorsMapToStatuses(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->service->method('getStaff')->willThrowException(new OCSNotFoundException('Staff not found'));
		$this->service->method('saveStaff')->willThrowException(new OCSBadRequestException('PIN too short'));
		$this->service->method('deleteStaff')->willThrowException(new OCSForbiddenException('Last manager'));
		$this->service->method('listStaff')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')
			->with('PosStaffController::index failed', ['exception' => 'db down']);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->show(id: 's1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->destroy(id: 's1')->getStatus());

		$failed = $this->controller->index();
		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $failed->getStatus());
		self::assertSame(expected: ['error' => 'An unexpected error occurred'], actual: $failed->getData());
	}//end testServiceErrorsMapToStatuses()

	/**
	 * PIN authentication needs both fields and is open to non-admins.
	 *
	 * @return void
	 */
	public function testAuthenticateNeedsStaffAndPin(): void {
		$this->signIn(uid: 'cashier', admin: false);
		$this->request->method('getParam')->willReturnMap([['staffId', '', 's1'], ['pin', '', '']]);
		$this->service->expects(self::never())->method('validatePin');

		$response = $this->controller->authenticate();

		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'Staff and PIN are required'], actual: $response->getData());
	}//end testAuthenticateNeedsStaffAndPin()

	/**
	 * A valid PIN opens a staff session; a wrong one is refused.
	 *
	 * @return void
	 */
	public function testAuthenticateValidatesThePin(): void {
		$this->signIn(uid: 'cashier', admin: false);
		$this->request->method('getParam')->willReturnMap([['staffId', '', 's1'], ['pin', '', '1234']]);
		$this->service->expects(self::exactly(count: 2))->method('validatePin')->with('s1', '1234')
			->willReturnOnConsecutiveCalls(
				['staffId' => 's1', 'role' => 'cashier'],
				self::throwException(exception: new OCSForbiddenException('Invalid PIN'))
			);

		self::assertSame(
			expected: ['session' => ['staffId' => 's1', 'role' => 'cashier']],
			actual: $this->controller->authenticate()->getData()
		);
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->authenticate()->getStatus());
	}//end testAuthenticateValidatesThePin()
}//end class
