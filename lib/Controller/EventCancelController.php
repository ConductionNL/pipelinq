<?php

/**
 * Pipelinq EventCancelController.
 *
 * The public page behind the cancel link in an event mail. Opening the link
 * (GET) only shows the event and a Cancel my registration button; pressing the
 * button (POST) cancels. A mail scanner that follows links therefore never
 * cancels anyone. The signed token is the whole authorisation: it names one
 * registration, is signed with a per-instance secret, and expires when the
 * event starts.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Show and perform the cancellation of one event registration.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */
class EventCancelController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request       The request.
	 * @param EventRegistrationService $registrations The registrations.
	 * @param EventEmailService        $mails         Verifies the token and mails whoever moves up.
	 * @param IURLGenerator            $urls          Builds the form action.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function __construct(
		IRequest $request,
		private EventRegistrationService $registrations,
		private EventEmailService $mails,
		private IURLGenerator $urls,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Show the event and the Cancel my registration button. Changes nothing.
	 *
	 * @param string $token The signed cancel token.
	 *
	 * @return TemplateResponse The page.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $token = ''): TemplateResponse {
		$registration = $this->registrationFor(token: $token);
		if ($registration === null) {
			return $this->page(state: 'invalid', event: [], token: '', status: Http::STATUS_NOT_FOUND);
		}

		$event = ($this->registrations->event(eventId: (string)($registration['event'] ?? '')) ?? []);
		if ((string)($registration['status'] ?? '') === 'cancelled') {
			return $this->page(state: 'done', event: $event, token: '');
		}

		return $this->page(state: 'confirm', event: $event, token: $token);
	}//end show()

	/**
	 * Cancel the registration the token names, and mail whoever moves up.
	 *
	 * @param string $token The signed cancel token.
	 *
	 * @return TemplateResponse The page.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function cancel(string $token = ''): TemplateResponse {
		$registration = $this->registrationFor(token: $token);
		if ($registration === null) {
			return $this->page(state: 'invalid', event: [], token: '', status: Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->registrations->cancel(registrationId: (string)($registration['uuid'] ?? $registration['id'] ?? ''));
		$event = ($this->registrations->event(eventId: (string)($registration['event'] ?? '')) ?? []);
		if ($outcome->promoted !== null && $this->mails->sendPlaceFreed(event: $event, registration: $outcome->promoted) === true) {
			$this->registrations->stamp(registration: $outcome->promoted, field: 'confirmationSentAt');
		}

		return $this->page(state: 'done', event: $event, token: '');
	}//end cancel()

	/**
	 * The registration a genuine, unexpired token names.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>|null The registration.
	 */
	private function registrationFor(string $token): ?array {
		$id = $this->mails->verifyCancelToken(token: $token);
		if ($id === null) {
			return null;
		}

		return $this->registrations->registration(registrationId: $id);
	}//end registrationFor()

	/**
	 * Render the guest page.
	 *
	 * @param string               $state  `confirm`, `done` or `invalid`.
	 * @param array<string, mixed> $event  The event.
	 * @param string               $token  The token the button posts back.
	 * @param int                  $status The HTTP status.
	 *
	 * @return TemplateResponse The page.
	 */
	private function page(string $state, array $event, string $token, int $status = Http::STATUS_OK): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'event-cancel',
			[
				'state' => $state,
				'title' => (string)($event['title'] ?? ''),
				'startAt' => (string)($event['startAt'] ?? ''),
				'token' => $token,
				'action' => $this->urls->linkToRoute('pipelinq.eventCancel.cancel'),
			],
			TemplateResponse::RENDER_AS_GUEST
		);
		$response->setStatus($status);
		return $response;
	}//end page()
}//end class
