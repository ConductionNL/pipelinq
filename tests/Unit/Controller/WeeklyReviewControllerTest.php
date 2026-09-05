<?php

/**
 * Unit tests for WeeklyReviewController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\WeeklyReviewController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WeeklyReviewController: one response, and never an empty page.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
class WeeklyReviewControllerTest extends TestCase {

	/**
	 * The review service the controller delegates to.
	 *
	 * @var WeeklyReviewService
	 */
	private WeeklyReviewService $reviews;

	/**
	 * The session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Set up an authenticated, privileged caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reviews = $this->createMock(WeeklyReviewService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketeer');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * A tenant that has never run the agent still gets last week's numbers,
	 * which is the half of the review that does not need one.
	 *
	 * @return void
	 */
	public function testShowComposesOneWhenNoneIsStored(): void {
		$this->reviews->method('latest')->willReturn(null);
		$this->reviews->method('compose')->willReturn([
			'weekStarting' => '2026-08-24',
			'degraded' => ['watchEvent'],
			'highlights' => [],
		]);

		$response = $this->controller()->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2026-08-24', $response->getData()['weekStarting']);
		$this->assertContains('watchEvent', $response->getData()['degraded']);
	}//end testShowComposesOneWhenNoneIsStored()

	/**
	 * The stored review wins when there is one, so an agent's narrative is
	 * not thrown away by a fresh composition on every page load.
	 *
	 * @return void
	 */
	public function testShowPrefersTheStoredReview(): void {
		$this->reviews->method('latest')->willReturn([
			'weekStarting' => '2026-08-24',
			'summary' => 'Written by an agent.',
			'agentAuthored' => true,
		]);
		$this->reviews->expects($this->never())->method('compose');

		$this->assertTrue($this->controller()->show()->getData()['agentAuthored']);
	}//end testShowPrefersTheStoredReview()

	/**
	 * A write that fails is a 502, not a silently empty review.
	 *
	 * @return void
	 */
	public function testGenerateAnswersBadGatewayOnAFailedWrite(): void {
		$this->reviews->method('generate')->willReturn(null);

		$response = $this->controller()->generate('2026-08-24');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('write_failed', $response->getData()['error']);
	}//end testGenerateAnswersBadGatewayOnAFailedWrite()

	/**
	 * The review carries campaign and customer numbers, so it takes the same
	 * privilege check the campaign report takes.
	 *
	 * @return void
	 */
	public function testBothEndpointsRefuseAnUnprivilegedCaller(): void {
		$controller = new WeeklyReviewController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$this->reviews,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => false]),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->generate()->getStatus());
	}//end testBothEndpointsRefuseAnUnprivilegedCaller()

	/**
	 * The controller, wired to a privileged caller.
	 *
	 * @return WeeklyReviewController The controller.
	 */
	private function controller(): WeeklyReviewController {
		return new WeeklyReviewController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$this->reviews,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true]),
		);
	}//end controller()
}//end class
