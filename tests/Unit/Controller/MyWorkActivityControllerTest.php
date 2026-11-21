<?php

/**
 * Unit tests for MyWorkActivityController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MyWorkActivityController;
use OCA\Pipelinq\Kernel\Contracts\RecentActivityServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/my-work/recent-activity: the session user's recent activity, 401 when signed out.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
#[CoversClass(MyWorkActivityController::class)]
class MyWorkActivityControllerTest extends TestCase {

	/**
	 * A controller for the given user over the given entries.
	 *
	 * @param string|null                       $uid     The session user, or null when signed out.
	 * @param array<int, array<string, string>> $entries What the service answers.
	 *
	 * @return MyWorkActivityController The controller.
	 */
	private function controller(?string $uid, array $entries): MyWorkActivityController {
		$service = $this->createMock(originalClassName: RecentActivityServiceInterface::class);
		$service->expects($this->atMost(allowedInvocations: 1))->method('forUser')->with('daan')->willReturn(value: $entries);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createConfiguredMock(originalClassName: IUser::class, configuration: ['getUID' => $uid]);
		}

		$session->method('getUser')->willReturn($user);

		return new MyWorkActivityController(
			request: $this->createMock(originalClassName: IRequest::class),
			activity: $service,
			session: $session,
		);
	}//end controller()

	/**
	 * The session user's recent activity comes back under `items`.
	 *
	 * @return void
	 */
	public function testTheSessionUsersActivity(): void {
		$entries = [['id' => 'a1', 'title' => 'Parking permit', 'action' => 'update']];
		$response = $this->controller(uid: 'daan', entries: $entries)->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['items' => $entries], actual: $response->getData());
	}//end testTheSessionUsersActivity()

	/**
	 * Without a signed-in user the route answers 401.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(uid: null, entries: [])->index()->getStatus());
	}//end testSignedOutIsRefused()
}//end class
