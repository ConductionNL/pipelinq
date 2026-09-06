<?php

/**
 * Pipelinq SubscriptionController.
 *
 * The marketer's side of a membership: read what a contact is on, import an
 * existing customer onto a soft opt-in list, take someone off one list or
 * off every list, and mint a preference-centre link to send them.
 *
 * The subscriber's side lives in {@see ListPublicController} and shares
 * nothing with this one but the service beneath. That separation is
 * deliberate: these methods act on a contact the caller names, so each one
 * checks the per-object access policy, while the public ones act only on
 * what a signed token already proves.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for subscriptions, from the marketer's side.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
 */
class SubscriptionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SubscriptionService $subscriptions Membership state changes.
	 * @param SubscriptionQueryService $queries Membership reads.
	 * @param PreferenceCentreService $preferences The preference centre.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly SubscriptionService $subscriptions,
		private readonly SubscriptionQueryService $queries,
		private readonly PreferenceCentreService $preferences,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/contacts/{contactId}/subscriptions — what this person is on.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return JSONResponse `{subscriptions[]}`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	#[NoAdminRequired]
	public function forContact(string $contactId): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse([
			'subscriptions' => $this->queries->listSubscriptionsForContact(contactId: $contactId),
		]);
	}//end forContact()

	/**
	 * POST /api/subscriptions/soft-opt-in — import an existing customer.
	 *
	 * Refused unless the list declares soft opt-in and the request records
	 * that an objection was offered. The refusal message says which of the
	 * two failed, because the caller is the marketer and that is exactly
	 * what they need to correct.
	 *
	 * @return JSONResponse 201 on import, 400 with the reason otherwise.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 */
	#[NoAdminRequired]
	public function softOptIn(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$offered = $this->request->getParam('objectionOffered');
		$result = $this->subscriptions->importSoftOptIn(
			listId: (string)$this->request->getParam('listId', ''),
			contactId: (string)$this->request->getParam('contactId', ''),
			email: (string)$this->request->getParam('email', ''),
			evidence: [
				'objectionOffered' => ($offered === true || $offered === 'true' || $offered === '1'),
				'objectionOfferedAt' => (string)$this->request->getParam('objectionOfferedAt', ''),
				'objectionText' => (string)$this->request->getParam('objectionText', ''),
				'reference' => (string)$this->request->getParam('reference', ''),
			],
		);

		if ($result['status'] !== 'imported') {
			return new JSONResponse(
				['error' => ($result['error'] ?? 'The subscription could not be imported')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['status' => 'imported'], Http::STATUS_CREATED);
	}//end softOptIn()

	/**
	 * POST /api/subscriptions/unsubscribe — take someone off a list.
	 *
	 * With no `listId`, every membership the contact holds is closed. This
	 * is the marketer acting on a request that arrived by another route, a
	 * phone call or a reply, so the withdrawal is recorded exactly as a
	 * self-service one is.
	 *
	 * @return JSONResponse 200 with the number of memberships closed.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	#[NoAdminRequired]
	public function unsubscribe(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$contactId = (string)$this->request->getParam('contactId', '');
		$listId = (string)$this->request->getParam('listId', '');
		$reason = (string)$this->request->getParam('reason', '');
		if ($contactId === '') {
			return new JSONResponse(['error' => 'Name the contact to unsubscribe'], Http::STATUS_BAD_REQUEST);
		}

		if ($listId === '') {
			$count = $this->subscriptions->globalUnsubscribe(contactId: $contactId, reason: $reason);
			return new JSONResponse(['count' => $count]);
		}

		$closed = $this->subscriptions->unsubscribeFromList(
			listId: $listId,
			contactId: $contactId,
			reason: $reason,
		);
		if ($closed === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['count' => 1]);
	}//end unsubscribe()

	/**
	 * GET /api/contacts/{contactId}/preference-link — mint a signed link.
	 *
	 * The marketer sends this to someone who asks to change what they get.
	 * It is minted rather than stored, so there is no link to leak from a
	 * row and no link to invalidate when the contact changes.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return JSONResponse `{url}`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	#[NoAdminRequired]
	public function preferenceLink(string $contactId): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		if ($contactId === '') {
			return new JSONResponse(['error' => 'Name the contact'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['url' => $this->preferences->preferencesUrlFor(contactId: $contactId)]);
	}//end preferenceLink()

	/**
	 * Resolve the caller, refusing anyone who is not a CRM user.
	 *
	 * @return string|null The uid, or null when the caller may not proceed.
	 */
	private function requireCrmUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return null;
		}

		return $uid;
	}//end requireCrmUser()

	/**
	 * The single refusal, so an unauthenticated and an unprivileged caller
	 * cannot tell each other apart.
	 *
	 * @return JSONResponse A 403.
	 */
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end refuse()
}//end class
