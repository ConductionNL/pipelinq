<?php

/**
 * Unit tests for EventController.
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
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EventController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\EventService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EventController: the guard and the answers of both actions.
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
 */
class EventControllerTest extends TestCase {

	/**
	 * A controller over the given service, for a caller with or without the privilege.
	 *
	 * @param EventService $events     The service.
	 * @param bool         $privileged Whether the caller holds the CRM privilege.
	 * @param bool         $signedIn   Whether there is a session.
	 *
	 * @return EventController The controller.
	 */
	private function controller(EventService $events, bool $privileged = true, bool $signedIn = true): EventController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'host']);
		}

		$session->method('getUser')->willReturn($user);

		return new EventController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$events,
			$session,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
		);
	}//end controller()

	/**
	 * The reminder answers the tally.
	 *
	 * @return void
	 */
	public function testTheReminderAnswersTheTally(): void {
		$tally = ['sent' => 3, 'refused' => 1, 'skipped' => 0, 'failed' => 0];
		$events = $this->createConfiguredMock(EventService::class, ['sendReminder' => $tally]);

		$response = $this->controller(events: $events)->reminder(id: 'event-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($tally, $response->getData());
	}//end testTheReminderAnswersTheTally()

	/**
	 * An unknown event is 404.
	 *
	 * @return void
	 */
	public function testTheReminderOfAnUnknownEventIsNotFound(): void {
		$events = $this->createConfiguredMock(EventService::class, ['sendReminder' => null]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(events: $events)->reminder(id: 'nope')->getStatus());
	}//end testTheReminderOfAnUnknownEventIsNotFound()

	/**
	 * A caller without the privilege sends nothing, and one without a session is 401.
	 *
	 * @return void
	 */
	public function testTheGuardRunsBeforeTheAction(): void {
		$events = $this->createMock(EventService::class);
		$events->expects($this->never())->method('sendReminder');
		$events->expects($this->never())->method('checkIn');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(events: $events, privileged: false)->reminder(id: 'event-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(events: $events, signedIn: false)->checkIn(id: 'reg-1')->getStatus());
	}//end testTheGuardRunsBeforeTheAction()

	/**
	 * A cancelled registration is 409, an unknown one 404, a checked-in one 200.
	 *
	 * @return void
	 */
	public function testTheCheckInAnswers(): void {
		$cancelled = $this->createConfiguredMock(EventService::class, ['checkIn' => ['outcome' => 'cancelled']]);
		$unknown = $this->createConfiguredMock(EventService::class, ['checkIn' => ['outcome' => 'not_found']]);
		$done = $this->createConfiguredMock(EventService::class, ['checkIn' => ['outcome' => 'checked_in', 'registration' => ['uuid' => 'reg-1']]]);

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller(events: $cancelled)->checkIn(id: 'reg-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(events: $unknown)->checkIn(id: 'reg-1')->getStatus());
		$response = $this->controller(events: $done)->checkIn(id: 'reg-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('checked_in', $response->getData()['outcome']);
	}//end testTheCheckInAnswers()
}//end class
