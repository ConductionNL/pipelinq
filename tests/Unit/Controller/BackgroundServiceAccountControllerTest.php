<?php

/**
 * The admin endpoints that read and pick the background service account.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\BackgroundServiceAccountController;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only an admin reads or picks the account, and both routes exist.
 *
 * @covers \OCA\Pipelinq\Controller\BackgroundServiceAccountController
 */
class BackgroundServiceAccountControllerTest extends TestCase {
	/**
	 * The account.
	 *
	 * @var BackgroundServiceAccount&\PHPUnit\Framework\MockObject\MockObject
	 */
	private BackgroundServiceAccount $account;

	/**
	 * Set up the account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->account = $this->createMock(BackgroundServiceAccount::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param bool $isAdmin Whether the caller is an admin.
	 * @param string $userId The uid in the request.
	 *
	 * @return BackgroundServiceAccountController The controller.
	 */
	private function controller(bool $isAdmin, string $userId = ''): BackgroundServiceAccountController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'userId' ? $userId : $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new BackgroundServiceAccountController($request, $this->account, $session, $groups);
	}//end controller()

	/**
	 * The two routes point at methods the controller has.
	 *
	 * @return void
	 */
	public function testBothRoutesExist(): void {
		$routes = (include __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$found = [];
		foreach ($routes as $route) {
			if ($route['url'] === '/api/background/service-account') {
				$found[$route['verb']] = $route['name'];
			}
		}

		$this->assertSame(['GET' => 'backgroundServiceAccount#show', 'PUT' => 'backgroundServiceAccount#save'], $found);
		$this->assertTrue(method_exists(BackgroundServiceAccountController::class, 'show'));
		$this->assertTrue(method_exists(BackgroundServiceAccountController::class, 'save'));
	}//end testBothRoutesExist()

	/**
	 * Somebody who is not an admin reads nothing and picks nothing.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->account->expects($this->never())->method('assign');
		$this->account->expects($this->never())->method('status');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false)->show()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false, userId: 'call-bot')->save()->getStatus());
	}//end testANonAdminIsRefused()

	/**
	 * An admin reads the status with the group.
	 *
	 * @return void
	 */
	public function testAnAdminReadsTheStatus(): void {
		$this->account->method('status')->willReturn(['userId' => '', 'usable' => false, 'reason' => 'unset']);

		$response = $this->controller(isAdmin: true)->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['userId' => '', 'usable' => false, 'reason' => 'unset', 'group' => 'pipelinq-background-service'],
			$response->getData()
		);
	}//end testAnAdminReadsTheStatus()

	/**
	 * An admin picks an account; one that cannot be used answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testAnAdminPicksAnAccount(): void {
		$this->account->method('assign')->willReturnCallback(
			static function (string $userId): array {
				if ($userId !== 'call-bot') {
					throw new InvalidArgumentException('No account "' . $userId . '" exists.');
				}

				return ['userId' => $userId, 'usable' => true, 'reason' => null];
			}
		);

		$saved = $this->controller(isAdmin: true, userId: 'call-bot')->save();
		$this->assertSame(Http::STATUS_OK, $saved->getStatus());
		$this->assertSame('call-bot', $saved->getData()['userId']);
		$this->assertSame('pipelinq-background-service', $saved->getData()['group']);

		$refused = $this->controller(isAdmin: true, userId: 'nobody')->save();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame('badRequest', $refused->getData()['errorCode']);
	}//end testAnAdminPicksAnAccount()
}//end class
