<?php

/**
 * Pipelinq BookingLinkController.
 *
 * The signed-in user's own personal booking link: read it (made on first
 * read) or switch it off. The uid comes from the session, never from the
 * request, so a user only ever sees and withdraws their own link.
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
use OCA\Pipelinq\Service\BookingLink\BookingLinkTokens;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Read or withdraw the signed-in user's booking link.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class BookingLinkController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The HTTP request.
	 * @param BookingLinkTokens $tokens  The link tokens.
	 * @param IUserSession      $session The signed-in user.
	 * @param IURLGenerator     $urls    Builds the link's slots address.
	 */
	public function __construct(
		IRequest $request,
		private readonly BookingLinkTokens $tokens,
		private readonly IUserSession $session,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in user's booking link, made on first read.
	 *
	 * @return JSONResponse `token` and `slotsUrl`, the public address that lists the free slots.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$token = $this->tokens->tokenFor(userId: $uid);
		return new JSONResponse(
			[
				'token' => $token,
				'slotsUrl' => $this->urls->linkToRouteAbsolute('pipelinq.publicBookingLink.slots', ['token' => $token]),
			]
		);
	}//end show()

	/**
	 * Switch the signed-in user's booking link off; the old link stops working.
	 *
	 * @return JSONResponse `revoked: true`.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	#[NoAdminRequired]
	public function revoke(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$this->tokens->revoke(userId: $uid);
		return new JSONResponse(['revoked' => true]);
	}//end revoke()
}//end class
