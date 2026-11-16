<?php

/**
 * Unit tests for EventAttendanceController.
 *
 * Without a user nothing happens (401); a registration or event the caller
 * cannot read does not exist for them (404); marking attended records it and
 * counts one `attend` touchpoint; adding by hand registers and confirms by
 * mail, refuses a bad address (400) and a closed event (409).
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EventAttendanceController;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for adding attendees by hand and recording who came.
 */
class EventAttendanceControllerTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The mail double.
	 *
	 * @var EventEmailService&MockObject
	 */
	private EventEmailService $mails;

	/**
	 * Build the controller.
	 *
	 * @param bool               $signedIn Whether there is a user.
	 * @param array<int, string> $readable The ids the caller may read.
	 * @param string             $status   The event's registration status.
	 *
	 * @return EventAttendanceController
	 */
	private function controller(bool $signedIn = true, array $readable = ['ev-1', 'r-1'], string $status = 'open'): EventAttendanceController {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [['uuid' => 'ev-1', 'campaign' => 'camp-1', 'signupStatus' => $status, 'capacity' => 10]],
				'eventRegistration' => [['uuid' => 'r-1', 'event' => 'ev-1', 'email' => 'a@example.nl', 'status' => 'confirmed', 'contact' => 'c-1']],
			]
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793527200);
		$this->mails = $this->createMock(EventEmailService::class);
		$reader = $this->createMock(CallerObjectReader::class);
		$reader->method('read')->willReturnCallback(
			static fn (string $id, string $schemaKey): ?array => (in_array($id, $readable, true) === true ? ['id' => $id] : null)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true ? $this->createMock(IUser::class) : null));

		return new EventAttendanceController(
			$this->createMock(IRequest::class),
			new EventRegistrationService($this->store, new TouchpointService($this->store), $time),
			$this->mails,
			$reader,
			$session
		);
	}//end controller()

	/**
	 * Without a user both actions are refused and nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingHappens(): void {
		$controller = $this->controller(signedIn: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->attendance(id: 'r-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->add(id: 'ev-1', name: 'B', email: 'b@example.nl')->getStatus());
		$this->assertSame([], $this->store->writes);
	}//end testWithoutAUserNothingHappens()

	/**
	 * What the caller cannot read is not found, and nothing is written.
	 *
	 * @return void
	 */
	public function testWhatTheCallerCannotReadIsNotFound(): void {
		$controller = $this->controller(readable: []);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->attendance(id: 'r-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->add(id: 'ev-1', name: 'B', email: 'b@example.nl')->getStatus());
		$this->assertSame([], $this->store->writes);
	}//end testWhatTheCallerCannotReadIsNotFound()

	/**
	 * Marking attended records it and counts one touchpoint; a no-show counts none.
	 *
	 * @return void
	 */
	public function testMarkingAttendance(): void {
		$controller = $this->controller();

		$response = $controller->attendance(id: 'r-1', attended: true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('attended', $response->getData()['attendance']);
		$this->assertCount(1, $this->store->rows['touchpoint']);

		$this->assertSame('no-show', $controller->attendance(id: 'r-1', attended: false)->getData()['attendance']);
		$this->assertCount(1, $this->store->rows['touchpoint']);
	}//end testMarkingAttendance()

	/**
	 * Adding by hand registers the person as a colleague's registration and confirms by mail.
	 *
	 * @return void
	 */
	public function testAddingSomeoneByHand(): void {
		$controller = $this->controller();
		$this->mails->expects($this->once())->method('sendConfirmation')->willReturn(true);

		$response = $controller->add(id: 'ev-1', name: 'Bea', email: 'bea@example.nl');

		$this->assertSame('registered', $response->getData()['outcome']);
		$registration = $response->getData()['registration'];
		$this->assertSame('colleague', $registration['source']);
		$this->assertSame('Bea', $registration['name']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $this->store->rows['eventRegistration'][$registration['uuid']]['confirmationSentAt']);
	}//end testAddingSomeoneByHand()

	/**
	 * A bad address is a 400 and a closed event a 409; neither writes anything.
	 *
	 * @return void
	 */
	public function testABadAddressOrAClosedEventIsRefused(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->add(id: 'ev-1', name: 'B', email: 'nope')->getStatus());
		$this->assertSame([], $this->store->writes);

		$closed = $this->controller(status: 'closed');
		$this->mails->expects($this->never())->method('sendConfirmation');
		$this->assertSame(Http::STATUS_CONFLICT, $closed->add(id: 'ev-1', name: 'B', email: 'b@example.nl')->getStatus());
		$this->assertSame([], $this->store->writes);
	}//end testABadAddressOrAClosedEventIsRefused()
}//end class
