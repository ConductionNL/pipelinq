<?php

/**
 * Unit tests for MyWorkMeetingsController.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MyWorkMeetingsController;
use OCA\Pipelinq\Service\MyWork\MyWorkCalendarService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GET /api/my-work/meetings: the session user's meetings, an empty list on a failed read.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
#[CoversClass(MyWorkMeetingsController::class)]
class MyWorkMeetingsControllerTest extends TestCase {

	/**
	 * A controller for the given user over the given meetings, or a failing read.
	 *
	 * @param string|null                           $uid      The session user, or null when signed out.
	 * @param array<int, array<string, string>>|null $meetings What the service answers; null makes it throw.
	 *
	 * @return MyWorkMeetingsController The controller.
	 */
	private function controller(?string $uid, ?array $meetings): MyWorkMeetingsController {
		$service = $this->createMock(originalClassName: MyWorkCalendarService::class);
		if ($meetings === null) {
			$service->method('upcoming')->willThrowException(new RuntimeException('calendar down'));
		} else {
			$service->expects($this->atMost(allowedInvocations: 1))->method('upcoming')->with('daan')->willReturn($meetings);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createConfiguredMock(originalClassName: IUser::class, configuration: ['getUID' => $uid]);
		}

		$session->method('getUser')->willReturn($user);

		return new MyWorkMeetingsController(
			request: $this->createMock(originalClassName: IRequest::class),
			meetings: $service,
			session: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The session user's meetings come back under `items`.
	 *
	 * @return void
	 */
	public function testTheSessionUsersMeetings(): void {
		$meetings = [['id' => 'e1', 'title' => 'Kennismaking']];
		$response = $this->controller(uid: 'daan', meetings: $meetings)->upcoming();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['items' => $meetings], actual: $response->getData());
	}//end testTheSessionUsersMeetings()

	/**
	 * A failed calendar read answers an empty list, so the section hides.
	 *
	 * @return void
	 */
	public function testAFailedReadIsAnEmptyList(): void {
		$response = $this->controller(uid: 'daan', meetings: null)->upcoming();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['items' => []], actual: $response->getData());
	}//end testAFailedReadIsAnEmptyList()

	/**
	 * Without a signed-in user the route answers 401.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(uid: null, meetings: [])->upcoming()->getStatus());
	}//end testSignedOutIsRefused()
}//end class
