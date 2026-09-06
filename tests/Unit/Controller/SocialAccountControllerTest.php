<?php

/**
 * Tests for SocialAccountController.
 *
 * ONE THING IS ASSERTED HERE THAT NO SERVICE TEST CAN. `#[NoAdminRequired]`
 * opens a route to every logged-in user, so an object route needs its own
 * guard in the method body (ADR-005, per-object authorization). These tests
 * walk every mutation with a session that owns nothing and check that each
 * one refuses.
 *
 * The refusal is deliberately the same on both paths: an unauthenticated
 * caller and an unprivileged one get an identical 403, so neither can learn
 * which of the two they are.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SocialAccountController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SocialAccountService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The account routes and their per-object guard.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
 */
class SocialAccountControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock account service.
	 *
	 * @var SocialAccountService&MockObject
	 */
	private SocialAccountService $accounts;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock access policy.
	 *
	 * @var ObjectOwnerAccessPolicy&MockObject
	 */
	private ObjectOwnerAccessPolicy $policy;

	/**
	 * The controller under test.
	 *
	 * @var SocialAccountController
	 */
	private SocialAccountController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->accounts = $this->createMock(SocialAccountService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->policy = $this->createMock(ObjectOwnerAccessPolicy::class);

		$this->controller = new SocialAccountController(
			$this->request,
			$this->accounts,
			$this->userSession,
			$this->policy,
		);
	}

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * With no session at all, every route refuses with the same 403.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedOnEveryRoute(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->accounts->expects($this->never())->method('connectRequest');
		$this->accounts->expects($this->never())->method('revoke');

		foreach (
			[
				$this->controller->index(),
				$this->controller->show(id: 'acc-1'),
				$this->controller->create(),
				$this->controller->connect(id: 'acc-1'),
				$this->controller->attach(id: 'acc-1'),
				$this->controller->revoke(id: 'acc-1'),
				$this->controller->sync(id: 'acc-1'),
			] as $response
		) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}
	}

	/**
	 * A logged-in user who is not a marketer cannot list or create accounts.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedCallerCannotListOrCreate(): void {
		$this->signIn(uid: 'buurman');
		$this->policy->method('isPrivileged')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->index()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create()->getStatus());
	}

	/**
	 * A logged-in user who does not own the account is refused on every
	 * mutation, and the service is never asked to act.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedCallerIsRefusedOnEveryMutation(): void {
		$this->signIn(uid: 'marieke');
		$this->policy->method('isPrivileged')->willReturn(false);
		$this->accounts->method('getAccount')->willReturn([
			'network' => 'linkedin',
			'kind' => 'person',
			'ownerUserId' => 'ruben',
		]);
		$this->accounts->method('mayActOn')->willReturn(false);
		$this->accounts->method('connectRequest')->willReturn(['error' => 'You may not connect this account.']);
		$this->accounts->method('attachCredential')->willReturn(['error' => 'You may not connect this account.']);
		$this->accounts->method('revoke')->willReturn(['error' => 'You may not revoke this account.']);
		$this->accounts->expects($this->never())->method('syncStatus');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->show(id: 'acc-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->sync(id: 'acc-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->connect(id: 'acc-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->attach(id: 'acc-1')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->revoke(id: 'acc-1')->getStatus());
	}

	/**
	 * An account that does not exist is a 404, not a 200 with nothing in it.
	 *
	 * @return void
	 */
	public function testAMissingAccountIsNotFound(): void {
		$this->signIn(uid: 'ruben');
		$this->accounts->method('getAccount')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(id: 'nope')->getStatus());
	}

	/**
	 * The owner of a personal account reaches it even though they are in no
	 * marketing group, which is the whole reason the object routes ask
	 * `mayActOn()` rather than the group policy.
	 *
	 * @return void
	 */
	public function testTheOwnerOfAPersonalAccountReachesItWithoutAMarketingGroup(): void {
		$this->signIn(uid: 'ruben');
		$this->policy->method('isPrivileged')->willReturn(false);
		$this->accounts->method('getAccount')->willReturn([
			'network' => 'linkedin',
			'kind' => 'person',
			'ownerUserId' => 'ruben',
		]);
		$this->accounts->method('mayActOn')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller->show(id: 'acc-1')->getStatus());
	}

	/**
	 * Attaching a credential takes EXACTLY one field off the request. A body
	 * carrying a token cannot reach the service, because the controller never
	 * reads one.
	 *
	 * @return void
	 */
	public function testAttachSendsOnlyTheCredentialReferenceToTheService(): void {
		$this->signIn(uid: 'ruben');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'credentialRef' => 'cred-1',
				'accessToken' => 'YOUR_TOKEN_HERE',
				default => $default,
			}
		);

		$this->accounts->expects($this->once())->method('attachCredential')
			->with('acc-1', 'ruben', ['credentialRef' => 'cred-1'])
			->willReturn(['account' => ['id' => 'acc-1']]);

		$this->assertSame(Http::STATUS_OK, $this->controller->attach(id: 'acc-1')->getStatus());
	}
}//end class
