<?php

/**
 * Tests for MessagingAdminController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\MessagingAdminController;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An admin picks the account the SMS and WhatsApp webhooks write as.
 */
class MessagingAdminControllerTest extends TestCase {
	/**
	 * The account double.
	 *
	 * @var MessagingServiceAccount&MockObject
	 */
	private MessagingServiceAccount $account;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->account = $this->createMock(MessagingServiceAccount::class);
	}//end setUp()

	/**
	 * Build the controller for an admin or not, with a userId parameter.
	 *
	 * @param bool   $isAdmin Whether the caller is an admin.
	 * @param string $userId  The userId request parameter.
	 *
	 * @return MessagingAdminController The controller.
	 */
	private function controller(bool $isAdmin, string $userId = ''): MessagingAdminController {
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

		return new MessagingAdminController($request, $this->account, $session, $groups);
	}//end controller()

	/**
	 * A non-admin can neither read nor pick the account.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->account->expects($this->never())->method('assign');
		$this->account->expects($this->never())->method('status');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false)->getServiceAccount()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false, userId: 'mallory')->saveServiceAccount()->getStatus());
	}//end testANonAdminIsRefused()

	/**
	 * An admin reads and picks the account; the answer names its group.
	 *
	 * @return void
	 */
	public function testAnAdminPicksTheAccount(): void {
		$this->account->method('status')->willReturn(['userId' => '', 'usable' => false, 'reason' => 'unset']);
		$this->account->expects($this->once())->method('assign')->with(userId: 'sms-bot')
			->willReturn(['userId' => 'sms-bot', 'usable' => true, 'reason' => null]);

		$read = $this->controller(isAdmin: true)->getServiceAccount();
		$this->assertSame(Http::STATUS_OK, $read->getStatus());
		$this->assertSame(MessagingServiceAccount::GROUP, $read->getData()['group']);

		$saved = $this->controller(isAdmin: true, userId: 'sms-bot')->saveServiceAccount();
		$this->assertSame(Http::STATUS_OK, $saved->getStatus());
		$this->assertSame(
			['userId' => 'sms-bot', 'usable' => true, 'reason' => null, 'group' => MessagingServiceAccount::GROUP],
			$saved->getData()
		);
	}//end testAnAdminPicksTheAccount()

	/**
	 * An account that cannot be used is refused with 400.
	 *
	 * @return void
	 */
	public function testAnUnusableAccountIsRefused(): void {
		$this->account->method('assign')->willThrowException(new InvalidArgumentException('No account "ghost" exists.'));

		$response = $this->controller(isAdmin: true, userId: 'ghost')->saveServiceAccount();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('badRequest', $response->getData()['errorCode']);
	}//end testAnUnusableAccountIsRefused()
}//end class
