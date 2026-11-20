<?php

/**
 * MailIntakeAdminController tests: only an administrator lists or changes the ticket mailboxes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MailIntakeAdminController;
use OCA\Pipelinq\Service\MailIntake\MailIntakeAccounts;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see MailIntakeAdminController}.
 *
 * @covers \OCA\Pipelinq\Controller\MailIntakeAdminController
 */
final class MailIntakeAdminControllerTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var MailIntakeAccounts&MockObject */
	private MailIntakeAccounts $accounts;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/**
	 * Build the subject and its collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->accounts = $this->createMock(originalClassName: MailIntakeAccounts::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->groups = $this->createMock(originalClassName: IGroupManager::class);
	}

	/**
	 * A non admin is refused both calls.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedBothCalls(): void {
		$this->signIn(admin: false);
		$this->accounts->expects($this->never())->method('all');
		$this->accounts->expects($this->never())->method('select');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller()->accounts()->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller()->saveAccounts()->getStatus());
	}

	/**
	 * Nobody signed in is refused.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);

		$this->assertSame(expected: ['errorCode' => 'forbidden'], actual: $this->controller()->accounts()->getData());
	}

	/**
	 * An admin reads the accounts and the selection.
	 *
	 * @return void
	 */
	public function testAnAdminReadsTheAccountsAndTheSelection(): void {
		$this->signIn(admin: true);
		$list = [['id' => 3, 'address' => 'service@gemeente.nl', 'name' => 'Service', 'owner' => 'functional']];
		$this->accounts->method('all')->willReturn($list);
		$this->accounts->method('selected')->willReturn([3]);

		$response = $this->controller()->accounts();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['accounts' => $list, 'selected' => [3]], actual: $response->getData());
	}

	/**
	 * An admin stores the selection.
	 *
	 * @return void
	 */
	public function testAnAdminStoresTheSelection(): void {
		$this->signIn(admin: true);
		$this->request->method('getParam')->with('accounts')->willReturn([3, '9']);
		$this->accounts->expects($this->once())->method('select')->with([3, '9'])->willReturn([3]);

		$response = $this->controller()->saveAccounts();

		$this->assertSame(expected: ['selected' => [3]], actual: $response->getData());
	}

	/**
	 * A save without a list is a bad request.
	 *
	 * @return void
	 */
	public function testASaveWithoutAListIsABadRequest(): void {
		$this->signIn(admin: true);
		$this->request->method('getParam')->willReturn('3');
		$this->accounts->expects($this->never())->method('select');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller()->saveAccounts()->getStatus());
	}

	/**
	 * Stand-in for signIn().
	 *
	 * @param bool $admin The admin.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('u1');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with('u1')->willReturn($admin);
	}

	/**
	 * Stand-in for controller().
	 *
	 * @return MailIntakeAdminController
	 */
	private function controller(): MailIntakeAdminController {
		return new MailIntakeAdminController(request: $this->request, accounts: $this->accounts, userSession: $this->session, groupManager: $this->groups);
	}
}
