<?php

/**
 * BookingAdminController: rescheduling and the reminder an opt-out refused.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BookingAdminController;
use OCA\Pipelinq\Kernel\Contracts\AppointmentEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BookingServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Staff-only reschedule with its required start, and a refused reminder that says why.
 *
 * @covers \OCA\Pipelinq\Controller\BookingAdminController
 */
class BookingAdminControllerRescheduleTest extends TestCase {

	/**
	 * Booking service mock.
	 *
	 * @var BookingServiceInterface&MockObject
	 */
	private BookingServiceInterface&MockObject $bookings;

	/**
	 * Appointment e-mail service mock.
	 *
	 * @var AppointmentEmailServiceInterface&MockObject
	 */
	private AppointmentEmailServiceInterface&MockObject $email;

	/**
	 * Access policy mock.
	 *
	 * @var ObjectOwnerAccessPolicy&MockObject
	 */
	private ObjectOwnerAccessPolicy&MockObject $policy;

	/**
	 * Build the mocks; the acting user is privileged staff unless a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bookings = $this->createMock(BookingServiceInterface::class);
		$this->email = $this->createMock(AppointmentEmailServiceInterface::class);
		$this->policy = $this->createMock(ObjectOwnerAccessPolicy::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return BookingAdminController
	 */
	private function controller(array $params = []): BookingAdminController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new BookingAdminController(
			$request,
			$this->bookings,
			$this->email,
			$session,
			$this->policy,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A reschedule without a new start is refused before the service is asked.
	 *
	 * @return void
	 */
	public function testARescheduleWithoutANewStartIsUnprocessable(): void {
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->bookings->expects($this->never())->method('rescheduleBooking');

		$response = $this->controller()->reschedule('booking-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'newStartAt is required'], $response->getData());
	}//end testARescheduleWithoutANewStartIsUnprocessable()

	/**
	 * A reschedule answers the id of the new booking.
	 *
	 * @return void
	 */
	public function testARescheduleAnswersTheNewBookingId(): void {
		$this->policy->expects($this->once())->method('isPrivileged')->with('clerk')->willReturn(true);
		$this->bookings->expects($this->once())->method('rescheduleBooking')
			->with('booking-1', '2026-12-01T10:00:00+01:00')
			->willReturn('booking-2');

		$response = $this->controller(['newStartAt' => '2026-12-01T10:00:00+01:00'])->reschedule('booking-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['bookingId' => 'booking-2'], $response->getData());
	}//end testARescheduleAnswersTheNewBookingId()

	/**
	 * A non-staff user cannot reschedule.
	 *
	 * @return void
	 */
	public function testANonStaffRescheduleIsForbidden(): void {
		$this->policy->method('isPrivileged')->willReturn(false);
		$this->bookings->expects($this->never())->method('rescheduleBooking');

		$response = $this->controller(['newStartAt' => '2026-12-01T10:00:00+01:00'])->reschedule('booking-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testANonStaffRescheduleIsForbidden()

	/**
	 * A reminder the opt-out refused reports itself unsent, with the refusal code.
	 *
	 * @return void
	 */
	public function testARefusedReminderSaysWhy(): void {
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->email->expects($this->once())->method('sendReminder')->with('booking-1')->willReturn(false);
		$this->email->method('lastRefusal')->willReturn(['code' => 'opted-out']);

		$response = $this->controller()->sendReminder('booking-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'sent' => false,
				'reason' => 'opted-out',
				'message' => 'De herinnering is niet verstuurd: opted-out',
			],
			$response->getData()
		);
	}//end testARefusedReminderSaysWhy()

	/**
	 * A reminder that failed without a refusal reports only that it was not sent.
	 *
	 * @return void
	 */
	public function testAFailedReminderWithoutARefusalReportsOnlyUnsent(): void {
		$this->policy->method('isPrivileged')->willReturn(true);
		$this->email->method('sendReminder')->willReturn(false);
		$this->email->method('lastRefusal')->willReturn(null);

		$this->assertSame(['sent' => false], $this->controller()->sendReminder('booking-1')->getData());
	}//end testAFailedReminderWithoutARefusalReportsOnlyUnsent()
}//end class
