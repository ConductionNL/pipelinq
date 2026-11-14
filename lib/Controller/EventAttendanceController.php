<?php

/**
 * Pipelinq EventAttendanceController.
 *
 * What the team does on an event's attendee list: add someone by hand, and
 * mark each person attended or no-show. Marking attended counts as an `attend`
 * touchpoint of the campaign. The caller must be able to read the event or the
 * registration through OpenRegister's access rules; otherwise it does not exist
 * for them.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\EventEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Add attendees by hand and record who came.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
 */
class EventAttendanceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request       The request.
	 * @param EventRegistrationServiceInterface $registrations The registrations.
	 * @param EventEmailServiceInterface        $mails         Confirms a hand-added registration.
	 * @param CallerObjectReaderInterface       $reader        Reads as the caller, so access rules apply.
	 * @param IUserSession             $userSession   The caller.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
	 */
	public function __construct(
		IRequest $request,
		private EventRegistrationServiceInterface $registrations,
		private EventEmailServiceInterface $mails,
		private CallerObjectReaderInterface $reader,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Mark a registration attended or no-show.
	 *
	 * @param string $id       The registration id.
	 * @param bool   $attended True for attended, false for no-show.
	 *
	 * @return JSONResponse The registration, 401 without a user, 404 when the caller cannot read it.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
	 */
	#[NoAdminRequired]
	public function attendance(string $id, bool $attended = true): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->reader->read(id: $id, schemaKey: 'eventRegistration_schema') === null) {
			return new JSONResponse(['message' => 'Registration not found'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->registrations->markAttendance(registrationId: $id, attended: $attended);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Registration not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($saved);
	}//end attendance()

	/**
	 * Add a person to an event by hand: a place while one is free, else the waiting list.
	 *
	 * @param string $id    The event id.
	 * @param string $name  The person's name.
	 * @param string $email The person's address.
	 *
	 * @return JSONResponse The outcome and registration; 400 without an address, 409 when the event is closed.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
	 */
	#[NoAdminRequired]
	public function add(string $id, string $name = '', string $email = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->reader->read(id: $id, schemaKey: 'campaignEvent_schema') === null) {
			return new JSONResponse(['message' => 'Event not found'], Http::STATUS_NOT_FOUND);
		}

		if (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
			return new JSONResponse(['message' => 'A valid email address is required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->registerByHand(id: $id, name: $name, email: $email);
	}//end add()

	/**
	 * Register a person on an existing event and confirm a new registration.
	 *
	 * @param string $id    The event id.
	 * @param string $name  The person's name.
	 * @param string $email The person's address.
	 *
	 * @return JSONResponse The outcome and registration; 409 when the event is closed.
	 */
	private function registerByHand(string $id, string $name, string $email): JSONResponse {
		$event = ($this->registrations->event(eventId: $id) ?? []);
		$outcome = $this->registrations->register(
			event: $event,
			person: ['name' => $name, 'email' => $email, 'source' => 'colleague']
		);
		if ($outcome->status === EventRegistrationOutcome::CLOSED) {
			return new JSONResponse(['message' => 'The event does not take registrations'], Http::STATUS_CONFLICT);
		}

		$this->confirmNewRegistration(event: $event, outcome: $outcome);

		return new JSONResponse(['outcome' => $outcome->status, 'registration' => $outcome->registration]);
	}//end registerByHand()

	/**
	 * Mail a new place or waiting-list registration its confirmation and stamp it.
	 *
	 * @param array<string, mixed>     $event   The event.
	 * @param EventRegistrationOutcome $outcome The registration outcome.
	 *
	 * @return void
	 */
	private function confirmNewRegistration(array $event, EventRegistrationOutcome $outcome): void {
		$isNew = in_array($outcome->status, [EventRegistrationOutcome::REGISTERED, EventRegistrationOutcome::WAITLISTED], true);
		if ($isNew === true && $outcome->registration !== null
			&& $this->mails->sendConfirmation(event: $event, registration: $outcome->registration) === true
		) {
			$this->registrations->stamp(registration: $outcome->registration, field: 'confirmationSentAt');
		}
	}//end confirmNewRegistration()
}//end class
