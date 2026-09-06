<?php

/**
 * Tests for SocialPostController and SocialAdvocacyController.
 *
 * The wire contract of the three routes this change adds beyond the accounts
 * surface: the per-account publications on a post, the retry on one of them,
 * and the prepared share a colleague reads. Each is asserted for its refusal
 * as well as its success, because `#[NoAdminRequired]` opens all three to
 * every logged-in user and the guard is what stands between that and somebody
 * else's account.
 *
 * The advocacy routes deliberately do NOT require a marketing group: the
 * person being asked to share may be in none, and the guard is per object
 * inside the service instead.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SocialAdvocacyController;
use OCA\Pipelinq\Controller\SocialPostController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialAdvocacyService;
use OCA\Pipelinq\Service\SocialMetricsService;
use OCA\Pipelinq\Service\SocialPostService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The post, publication and share routes.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialPostControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock post service.
	 *
	 * @var SocialPostService&MockObject
	 */
	private SocialPostService $posts;

	/**
	 * Mock publication store.
	 *
	 * @var SocialPublicationStore&MockObject
	 */
	private SocialPublicationStore $publications;

	/**
	 * Mock metrics service.
	 *
	 * @var SocialMetricsService&MockObject
	 */
	private SocialMetricsService $metrics;

	/**
	 * Mock advocacy service.
	 *
	 * @var SocialAdvocacyService&MockObject
	 */
	private SocialAdvocacyService $advocacy;

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
	 * The post controller under test.
	 *
	 * @var SocialPostController
	 */
	private SocialPostController $controller;

	/**
	 * The advocacy controller under test.
	 *
	 * @var SocialAdvocacyController
	 */
	private SocialAdvocacyController $advocacyController;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->posts = $this->createMock(SocialPostService::class);
		$this->publications = $this->createMock(SocialPublicationStore::class);
		$this->metrics = $this->createMock(SocialMetricsService::class);
		$this->advocacy = $this->createMock(SocialAdvocacyService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->policy = $this->createMock(ObjectOwnerAccessPolicy::class);

		$this->controller = new SocialPostController(
			$this->request,
			$this->posts,
			$this->publications,
			$this->metrics,
			$this->userSession,
			$this->policy,
		);

		$this->advocacyController = new SocialAdvocacyController(
			$this->request,
			$this->advocacy,
			$this->userSession,
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
	 * GET /api/social-posts/{id}/publications answers the per-account rows for
	 * a marketer.
	 *
	 * @return void
	 */
	public function testPublicationsAnswersThePerAccountRows(): void {
		$this->signIn(uid: 'marieke');
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->publications->method('forPost')->willReturn([
			['id' => 'pub-1', 'network' => 'mastodon', 'status' => 'published'],
			['id' => 'pub-2', 'network' => 'linkedin', 'status' => 'failed'],
		]);

		$response = $this->controller->publications(id: 'post-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData()['data']);
	}

	/**
	 * A caller who may not use the marketing section sees no publications.
	 *
	 * @return void
	 */
	public function testPublicationsRefusesAnUnprivilegedCaller(): void {
		$this->signIn(uid: 'buurman');
		$this->policy->method('isPrivileged')->willReturn(false);
		$this->publications->expects($this->never())->method('forPost');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->publications(id: 'post-1')->getStatus(),
		);
	}

	/**
	 * POST /api/social-publications/{id}/retry hands the caller's own identity
	 * to the service, which owns the per-object guard.
	 *
	 * @return void
	 */
	public function testRetryPassesTheSessionIdentityToTheGuardedService(): void {
		$this->signIn(uid: 'ruben');
		$this->posts->expects($this->once())->method('retryPublication')
			->with('pub-1', 'ruben')
			->willReturn(['publication' => ['id' => 'pub-1', 'status' => 'published']]);

		$this->assertSame(Http::STATUS_OK, $this->controller->retry(id: 'pub-1')->getStatus());
	}

	/**
	 * A failure a retry cannot fix comes back as a 400 carrying the reason,
	 * rather than as a silent success.
	 *
	 * @return void
	 */
	public function testRetryReportsAFailureARetryCannotFix(): void {
		$this->signIn(uid: 'ruben');
		$this->posts->method('retryPublication')->willReturn([
			'error' => 'The connection to this account has ended. Reconnect it and the post can go out again.',
		]);

		$response = $this->controller->retry(id: 'pub-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Reconnect', $response->getData()['error']);
	}

	/**
	 * With no session, the retry route refuses and the service is never
	 * reached.
	 *
	 * @return void
	 */
	public function testRetryRefusesWithoutASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->posts->expects($this->never())->method('retryPublication');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->retry(id: 'pub-1')->getStatus());
	}

	/**
	 * The approver is the SESSION user. The controller reads no field naming
	 * who decided, so a body claiming somebody else cannot reach the record.
	 *
	 * @return void
	 */
	public function testApproveStampsTheSessionUser(): void {
		$this->signIn(uid: 'marieke');
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'note' => 'Prima',
				'userId' => 'somebody-else',
				default => $default,
			}
		);

		$this->posts->expects($this->once())->method('approve')
			->with('post-1', 'marieke', 'Prima')
			->willReturn(['post' => ['id' => 'post-1', 'status' => 'scheduled']]);

		$this->assertSame(Http::STATUS_OK, $this->controller->approve(id: 'post-1')->getStatus());
	}

	/**
	 * GET /api/social-performance answers the ranking for a marketer.
	 *
	 * @return void
	 */
	public function testPerformanceAnswersTheRanking(): void {
		$this->signIn(uid: 'marieke');
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->metrics->method('ranking')->willReturn([['publicationId' => 'pub-1']]);

		$response = $this->controller->performance();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['data']);
	}

	/**
	 * GET /api/social-publications/{id}/share answers the prepared text for
	 * the account's owner, WITHOUT requiring a marketing group: the colleague
	 * being asked to post may be in none.
	 *
	 * @return void
	 */
	public function testShareAnswersThePreparedTextForTheOwner(): void {
		$this->signIn(uid: 'ruben');
		$this->advocacy->expects($this->once())->method('shareBundle')
			->with('pub-1', 'ruben')
			->willReturn(['share' => ['body' => 'OpenRegister 3.0 is uit.', 'composerUrl' => '']]);

		$response = $this->advocacyController->share(id: 'pub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('OpenRegister 3.0 is uit.', $response->getData()['share']['body']);
	}

	/**
	 * A share that belongs to somebody else is refused by the service, and the
	 * controller reports that rather than the text.
	 *
	 * @return void
	 */
	public function testShareRefusesSomebodyElsesPublication(): void {
		$this->signIn(uid: 'marieke');
		$this->advocacy->method('shareBundle')->willReturn([
			'error' => 'This share belongs to somebody else.',
		]);

		$response = $this->advocacyController->share(id: 'pub-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('share', $response->getData());
	}

	/**
	 * Confirming a share passes the session identity and the address, and
	 * refuses without a session.
	 *
	 * @return void
	 */
	public function testConfirmShareRecordsAgainstTheSessionUser(): void {
		$this->signIn(uid: 'ruben');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (
				$key === 'url' ? 'https://www.instagram.com/p/abc/' : $default
			)
		);

		$this->advocacy->expects($this->once())->method('confirmShare')
			->with('pub-1', 'ruben', 'https://www.instagram.com/p/abc/')
			->willReturn(['publication' => ['id' => 'pub-1', 'status' => 'shared']]);

		$this->assertSame(
			Http::STATUS_OK,
			$this->advocacyController->confirmShare(id: 'pub-1')->getStatus(),
		);
	}

	/**
	 * With no session, confirming refuses and the service is never reached.
	 *
	 * @return void
	 */
	public function testConfirmShareRefusesWithoutASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->advocacy->expects($this->never())->method('confirmShare');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->advocacyController->confirmShare(id: 'pub-1')->getStatus(),
		);
	}
}//end class
