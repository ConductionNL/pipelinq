<?php

/**
 * Pipelinq PublicBookingLinkController.
 *
 * The public side of a personal booking link: the free slots of one day and
 * booking one of them. The token in the path is the whole authorisation. A
 * link nobody holds answers 404 with no detail, and the answers name only
 * free times, never what the owner's calendar holds.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\BookingLink\BookingLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;

/**
 * List and book the free slots behind a booking link.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class PublicBookingLinkController extends Controller {

	/**
	 * HTTP status per booking outcome.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		BookingLinkService::BOOKED => Http::STATUS_CREATED,
		BookingLinkService::UNKNOWN_LINK => Http::STATUS_NOT_FOUND,
		BookingLinkService::INVALID => Http::STATUS_BAD_REQUEST,
		BookingLinkService::NOT_FREE => Http::STATUS_CONFLICT,
		BookingLinkService::NO_CALENDAR => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request  The HTTP request.
	 * @param BookingLinkService $bookings The slots and bookings.
	 */
	public function __construct(
		IRequest $request,
		private readonly BookingLinkService $bookings,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The free slots of one day.
	 *
	 * @param string $token The token from the link.
	 * @param string $date  The day, `YYYY-MM-DD`.
	 *
	 * @return Http\JSONResponse `date` and `slots` (start and end per slot), or 404 for a link nobody holds.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function slots(string $token, string $date = ''): Http\JSONResponse {
		$slots = $this->bookings->slots(token: $token, date: $date);
		if ($slots === null) {
			return new Http\JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new Http\JSONResponse(['date' => $date, 'slots' => $slots]);
	}//end slots()

	/**
	 * Book one free slot.
	 *
	 * @param string $token The token from the link.
	 * @param string $start The slot's start, as the slots list gave it.
	 * @param string $name  The booker's name.
	 * @param string $email The booker's email address.
	 *
	 * @return Http\JSONResponse `outcome`: 201 booked, 400 invalid, 404 unknown link, 409 not free, 503 no calendar.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function book(string $token, string $start = '', string $name = '', string $email = ''): Http\JSONResponse {
		$outcome = $this->bookings->book(token: $token, start: $start, name: $name, email: $email);
		return new Http\JSONResponse(['outcome' => $outcome], self::STATUS[$outcome] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end book()
}//end class
