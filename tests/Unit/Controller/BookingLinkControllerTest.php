<?php

/**
 * Unit tests for BookingLinkController and PublicBookingLinkController.
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
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BookingLinkController;
use OCA\Pipelinq\Controller\PublicBookingLinkController;
use OCA\Pipelinq\Service\BookingLink\BookingLinkService;
use OCA\Pipelinq\Service\BookingLink\BookingLinkTokens;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The signed-in user's own link, and the public slots and bookings behind it.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
#[CoversClass(BookingLinkController::class)]
#[CoversClass(PublicBookingLinkController::class)]
class BookingLinkControllerTest extends TestCase {

	/**
	 * The own-link controller for a session user, or for nobody.
	 *
	 * @param string|null       $uid    The signed-in user.
	 * @param BookingLinkTokens $tokens The tokens.
	 *
	 * @return BookingLinkController The controller.
	 */
	private function own(?string $uid, BookingLinkTokens $tokens): BookingLinkController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => 'https://cloud.example/' . $route . '/' . $parameters['token']
		);

		return new BookingLinkController(request: $this->createMock(originalClassName: IRequest::class), tokens: $tokens, session: $session, urls: $urls);
	}//end own()

	/**
	 * The signed-in user reads their own link, with the public slots address.
	 *
	 * @return void
	 */
	public function testTheUserReadsTheirOwnLink(): void {
		$tokens = $this->createMock(originalClassName: BookingLinkTokens::class);
		$tokens->expects($this->once())->method('tokenFor')->with('daan')->willReturn('T0K3N');

		$response = $this->own(uid: 'daan', tokens: $tokens)->show();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: ['token' => 'T0K3N', 'slotsUrl' => 'https://cloud.example/pipelinq.publicBookingLink.slots/T0K3N'],
			actual: $response->getData()
		);
	}//end testTheUserReadsTheirOwnLink()

	/**
	 * The signed-in user switches their own link off.
	 *
	 * @return void
	 */
	public function testTheUserRevokesTheirOwnLink(): void {
		$tokens = $this->createMock(originalClassName: BookingLinkTokens::class);
		$tokens->expects($this->once())->method('revoke')->with('daan');

		$response = $this->own(uid: 'daan', tokens: $tokens)->revoke();

		$this->assertSame(expected: ['revoked' => true], actual: $response->getData());
	}//end testTheUserRevokesTheirOwnLink()

	/**
	 * Without a session user nothing is read or withdrawn.
	 *
	 * @return void
	 */
	public function testNoSessionIsUnauthorized(): void {
		$tokens = $this->createMock(originalClassName: BookingLinkTokens::class);
		$tokens->expects($this->never())->method('tokenFor');
		$tokens->expects($this->never())->method('revoke');
		$controller = $this->own(uid: null, tokens: $tokens);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->show()->getStatus());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->revoke()->getStatus());
	}//end testNoSessionIsUnauthorized()

	/**
	 * The own-link routes need a user but no admin; the public ones need no login and are rate limited.
	 *
	 * @return void
	 */
	public function testTheRoutesCarryTheirAccessAttributes(): void {
		foreach (['show', 'revoke'] as $method) {
			$attributes = (new ReflectionMethod(BookingLinkController::class, $method))->getAttributes(NoAdminRequired::class);
			$this->assertCount(expectedCount: 1, haystack: $attributes, message: $method);
		}

		foreach (['slots', 'book'] as $method) {
			$reflection = new ReflectionMethod(PublicBookingLinkController::class, $method);
			$this->assertCount(expectedCount: 1, haystack: $reflection->getAttributes(PublicPage::class), message: $method);
			$this->assertCount(expectedCount: 1, haystack: $reflection->getAttributes(AnonRateLimit::class), message: $method);
		}
	}//end testTheRoutesCarryTheirAccessAttributes()

	/**
	 * The slots of a day, and 404 for a link nobody holds.
	 *
	 * @return void
	 */
	public function testSlotsOrNotFound(): void {
		$slot = ['start' => '2026-11-24T10:00:00+01:00', 'end' => '2026-11-24T10:30:00+01:00'];
		$bookings = $this->createMock(originalClassName: BookingLinkService::class);
		$bookings->method('slots')->willReturnMap([['good', '2026-11-24', [$slot]], ['bad', '2026-11-24', null]]);
		$controller = new PublicBookingLinkController(request: $this->createMock(originalClassName: IRequest::class), bookings: $bookings);

		$found = $controller->slots(token: 'good', date: '2026-11-24');
		$missing = $controller->slots(token: 'bad', date: '2026-11-24');

		$this->assertSame(expected: ['date' => '2026-11-24', 'slots' => [$slot]], actual: $found->getData());
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $missing->getData());
	}//end testSlotsOrNotFound()

	/**
	 * The HTTP status per booking outcome.
	 *
	 * @return array<string, array{0: string, 1: int}> Outcome and status.
	 */
	public static function outcomes(): array {
		return [
			'booked' => [BookingLinkService::BOOKED, Http::STATUS_CREATED],
			'unknown link' => [BookingLinkService::UNKNOWN_LINK, Http::STATUS_NOT_FOUND],
			'invalid' => [BookingLinkService::INVALID, Http::STATUS_BAD_REQUEST],
			'not free' => [BookingLinkService::NOT_FREE, Http::STATUS_CONFLICT],
			'no calendar' => [BookingLinkService::NO_CALENDAR, Http::STATUS_SERVICE_UNAVAILABLE],
		];
	}//end outcomes()

	/**
	 * A booking answers its outcome with the matching status.
	 *
	 * @param string $outcome The service's outcome.
	 * @param int    $status  The expected status.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testABookingAnswersItsOutcome(string $outcome, int $status): void {
		$bookings = $this->createMock(originalClassName: BookingLinkService::class);
		$bookings->expects($this->once())->method('book')
			->with('good', '2026-11-24T10:00:00+01:00', 'Anna', 'anna@example.org')
			->willReturn($outcome);
		$controller = new PublicBookingLinkController(request: $this->createMock(originalClassName: IRequest::class), bookings: $bookings);

		$response = $controller->book(token: 'good', start: '2026-11-24T10:00:00+01:00', name: 'Anna', email: 'anna@example.org');

		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['outcome' => $outcome], actual: $response->getData());
	}//end testABookingAnswersItsOutcome()
}//end class
