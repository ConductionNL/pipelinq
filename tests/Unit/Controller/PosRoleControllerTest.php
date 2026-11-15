<?php

/**
 * Unit tests for PosRoleController.
 *
 * Any signed-in user may read the POS roles; creating, changing and deleting
 * one is admin-only, and an update first proves the role belongs to this
 * app's register so a foreign id cannot be overwritten.
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

use OCA\Pipelinq\Controller\PosRoleController;
use OCA\Pipelinq\Service\PosRoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
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
 * Tests for PosRoleController.
 *
 * @covers \OCA\Pipelinq\Controller\PosRoleController
 * @uses   \OCA\Pipelinq\Controller\PosResponses
 */
class PosRoleControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock role service.
	 *
	 * @var PosRoleService&MockObject
	 */
	private PosRoleService&MockObject $service;

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
	 * @var PosRoleController
	 */
	private PosRoleController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: PosRoleService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PosRoleController(
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
	 * Without a session nothing is read or written.
	 *
	 * @return void
	 */
	public function testEveryActionRequiresASession(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method(self::anything());

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->index()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->show(id: 'r1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->update(id: 'r1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->destroy(id: 'r1')->getStatus());
	}//end testEveryActionRequiresASession()

	/**
	 * A non-admin may read roles.
	 *
	 * @return void
	 */
	public function testANonAdminReadsRoles(): void {
		$this->signIn(uid: 'cashier', admin: false);
		$this->service->method('listRoles')->willReturn([['id' => 'r1']]);
		$this->service->method('getRole')->with('r1')->willReturn(['id' => 'r1', 'name' => 'Cashier']);

		self::assertSame(expected: ['roles' => [['id' => 'r1']]], actual: $this->controller->index()->getData());
		self::assertSame(
			expected: ['role' => ['id' => 'r1', 'name' => 'Cashier']],
			actual: $this->controller->show(id: 'r1')->getData()
		);
	}//end testANonAdminReadsRoles()

	/**
	 * A non-admin cannot create, change or delete a role.
	 *
	 * @return void
	 */
	public function testANonAdminCannotWriteRoles(): void {
		$this->signIn(uid: 'cashier', admin: false);
		$this->service->expects(self::never())->method('saveRole');
		$this->service->expects(self::never())->method('deleteRole');
		$this->service->expects(self::never())->method('getRole');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->update(id: 'r1')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->destroy(id: 'r1')->getStatus());
	}//end testANonAdminCannotWriteRoles()

	/**
	 * An admin writes roles from the request, with the permission flags cast.
	 *
	 * @return void
	 */
	public function testAnAdminWritesRolesFromTheRequest(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->request->method('getParam')->willReturnMap(
			[
				['name', '', 'Supervisor'],
				['description', '', 'Can void'],
				['canVoid', false, '1'],
				['maxDiscountPercent', 0, '15'],
				['canRefund', false, 1],
				['canNoSale', false, ''],
			]
		);
		$expected = [
			'name' => 'Supervisor',
			'description' => 'Can void',
			'canVoid' => true,
			'maxDiscountPercent' => 15,
			'canRefund' => true,
			'canNoSale' => false,
		];
		$this->service->expects(self::once())->method('getRole')->with('r1')->willReturn(['id' => 'r1']);
		$this->service->expects(self::exactly(count: 2))->method('saveRole')
			->willReturnCallback(
				static function (array $data, string $id = '') use ($expected): array {
					self::assertSame(expected: $expected, actual: $data);
					if ($id === '') {
						return ['id' => 'new'];
					}

					return ['id' => $id];
				}
			);
		$this->service->expects(self::once())->method('deleteRole')->with('r1');

		self::assertSame(expected: ['role' => ['id' => 'new']], actual: $this->controller->create()->getData());
		self::assertSame(expected: ['role' => ['id' => 'r1']], actual: $this->controller->update(id: 'r1')->getData());
		self::assertSame(expected: ['deleted' => true], actual: $this->controller->destroy(id: 'r1')->getData());
	}//end testAnAdminWritesRolesFromTheRequest()

	/**
	 * An update of a role outside this register stops before anything is saved.
	 *
	 * @return void
	 */
	public function testAForeignRoleIsNeverOverwritten(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->service->method('getRole')->willThrowException(new OCSNotFoundException('Role not found'));
		$this->service->expects(self::never())->method('saveRole');

		$this->expectException(exception: OCSNotFoundException::class);

		$this->controller->update(id: 'foreign');
	}//end testAForeignRoleIsNeverOverwritten()

	/**
	 * Service errors map to 404, 422 and a logged 500.
	 *
	 * @return void
	 */
	public function testServiceErrorsMapToStatuses(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->service->method('deleteRole')->willThrowException(new OCSNotFoundException('Role not found'));
		$this->service->method('saveRole')->willThrowException(new OCSBadRequestException('Role still assigned'));
		$this->service->method('listRoles')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')
			->with('PosRoleController::index failed', ['exception' => 'db down']);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->destroy(id: 'r1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $this->controller->create()->getStatus());
		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $this->controller->index()->getStatus());
	}//end testServiceErrorsMapToStatuses()
}//end class
