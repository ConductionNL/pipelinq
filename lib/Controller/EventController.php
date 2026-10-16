<?php

/**
 * Pipelinq EventController.
 *
 * The two event actions the object API cannot do: mailing a reminder to
 * everyone with a place, and checking a registration in at the door.
 *
 * Authentication is not authorization: a reminder mails residents and a
 * check-in changes who attended, so both take the CRM privilege check the
 * journey and campaign endpoints take.
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
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\EventService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * EventController: the reminder and the door check-in.
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */
class EventController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     App id.
	 * @param IRequest                $request     The request.
	 * @param EventService            $events      The event actions.
	 * @param IUserSession            $userSession The session.
	 * @param ObjectOwnerAccessPolicy $policy      CRM privilege check.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EventService $events,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Mail a reminder to every confirmed registration of an event.
	 *
	 * @param string $id Event UUID.
	 *
	 * @return JSONResponse The tally, or 404.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
	 */
	#[NoAdminRequired]
	public function reminder(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$tally = $this->events->sendReminder(eventId: $id);
		if ($tally === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($tally);
	}//end reminder()

	/**
	 * Check a registration in at the door.
	 *
	 * @param string $id Registration UUID.
	 *
	 * @return JSONResponse The registration, 404 when unknown, 409 when cancelled.
	 *
	 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
	 */
	#[NoAdminRequired]
	public function checkIn(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$result = $this->events->checkIn(registrationId: $id);
		if ($result['outcome'] === 'not_found') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($result['outcome'] === 'cancelled') {
			return new JSONResponse(['error' => 'cancelled'], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($result);
	}//end checkIn()

	/**
	 * Refuse a caller without a session or without the CRM privilege.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function guard(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end guard()
}//end class
