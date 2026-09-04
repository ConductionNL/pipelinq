<?php

/**
 * Pipelinq MailingListController.
 *
 * REST surface for the `mailingList` object, following the same
 * conventions as {@see SegmentController}: identity comes from
 * `IUserSession` and never from the request body (ADR-005), every method
 * checks the per-object access policy rather than relying on
 * `#[NoAdminRequired]` alone, and a refusal carries a generic message.
 *
 * A mailing list holds who asked to hear from the tenant. Listing one is
 * therefore as revealing as listing a segment, and is gated the same way.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for mailing lists.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */
class MailingListController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param MailingListService $lists Mailing list service.
	 * @param SubscriptionQueryService $queries Membership reads.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly MailingListService $lists,
		private readonly SubscriptionQueryService $queries,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/mailing-lists — paginated list.
	 *
	 * @param int $page 1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse `{data[], pagination}`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->lists->listMailingLists(page: $page, limit: $limit));
	}//end index()

	/**
	 * GET /api/mailing-lists/{id} — one list with its subscription counts.
	 *
	 * @param string $id MailingList UUID or slug.
	 *
	 * @return JSONResponse The list and its per-state counts, or 404.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$list = $this->lists->getMailingListById(listId: $id);
		if ($list === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			'list' => $list,
			'counts' => $this->queries->countsForList(listId: $id),
		]);
	}//end show()

	/**
	 * POST /api/mailing-lists — create a list.
	 *
	 * @return JSONResponse 201 with the list, or 400 naming the bad field.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->lists->createMailingList(payload: $this->collectBody(), createdByUid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['list' => ($result['list'] ?? null)], Http::STATUS_CREATED);
	}//end create()

	/**
	 * PATCH /api/mailing-lists/{id} — change a list's editable fields.
	 *
	 * @param string $id MailingList UUID or slug.
	 *
	 * @return JSONResponse 200 with the list, 400 or 404.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->lists->updateMailingList(listId: $id, patch: $this->collectBody());
		if (($result['error'] ?? '') === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['list' => ($result['list'] ?? null)]);
	}//end update()

	/**
	 * GET /api/mailing-lists/{id}/subscriptions — the memberships.
	 *
	 * @param string $id MailingList UUID or slug.
	 * @param int $page 1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse `{data[], pagination, counts}`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	#[NoAdminRequired]
	public function subscriptions(string $id, int $page = 1, int $limit = 25): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$envelope = $this->queries->listSubscriptionsForList(listId: $id, page: $page, limit: $limit);
		$envelope['counts'] = $this->queries->countsForList(listId: $id);
		return new JSONResponse($envelope);
	}//end subscriptions()

	/**
	 * Resolve the caller, refusing anyone who is not a CRM user.
	 *
	 * Authentication is not authorization: a mailing list holds who asked
	 * to hear from the tenant, so reading one is gated on the same policy
	 * a segment is.
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

	/**
	 * Collect a sanitised list body. Unknown keys are dropped, not merged.
	 *
	 * @return array<string, mixed> Sanitised payload.
	 */
	private function collectBody(): array {
		$body = [];
		foreach (['name', 'description', 'optInMode', 'senderName', 'senderEmail', 'replyTo', 'footerAddress', 'status'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		$publicSignup = $this->request->getParam('publicSignup');
		if ($publicSignup !== null) {
			$body['publicSignup'] = ($publicSignup !== false && $publicSignup !== 'false' && $publicSignup !== '0');
		}

		return $body;
	}//end collectBody()
}//end class
