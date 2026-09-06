<?php

/**
 * Pipelinq SocialAccountController.
 *
 * REST surface for connecting, reconnecting and revoking a social account,
 * following the `ArticleController` conventions: identity comes from
 * `IUserSession` and never from a request body, every method that names an
 * object checks the per-object guard rather than relying on
 * `#[NoAdminRequired]` alone (ADR-005), and a refusal carries one generic
 * message so an unauthenticated and an unprivileged caller cannot tell each
 * other apart.
 *
 * ONE DEPARTURE FROM THE SIBLINGS, AND IT IS DELIBERATE. `ArticleController`
 * admits only privileged users on every route. A personal social account
 * belongs to a colleague who may be in no marketing group at all, and they
 * have to be able to reconnect and revoke their own account. So the object
 * routes here require a session and then ask `SocialAccountService::mayActOn()`,
 * which is stricter than the group check for a personal account and the same
 * for a company one.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SocialAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for social accounts.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
class SocialAccountController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SocialAccountService $accounts The account lifecycle.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Privileged-group policy, for the list routes.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SocialAccountService $accounts,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/social-accounts — every account, with each network's readiness.
	 *
	 * @return JSONResponse `{data[], readiness{}}`.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->accounts->listAccounts());
	}//end index()

	/**
	 * GET /api/social-accounts/{id} — one account.
	 *
	 * @param string $id The account UUID or slug.
	 *
	 * @return JSONResponse `{account}`, 403 or 404.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$account = $this->accounts->getAccount(accountId: $id);
		if ($account === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return $this->refuse();
		}

		return new JSONResponse(['account' => $account]);
	}//end show()

	/**
	 * POST /api/social-accounts — add an account, before it is connected.
	 *
	 * @return JSONResponse 201 with the account, or 400 naming the problem.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-personal-account-belongs-to-the-person-who-connected-it
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->accounts->createAccount(payload: $this->collectBody(), uid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end create()

	/**
	 * POST /api/social-accounts/{id}/connect — the parameters the browser needs
	 * to start, or restart, the broker's connect flow.
	 *
	 * Nothing outbound happens here and nothing is written except a refusal's
	 * reason. The browser posts these to OpenRegister with its own session.
	 *
	 * @param string $id The account.
	 *
	 * @return JSONResponse `{connect}`, or a refusal naming what is missing.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	#[NoAdminRequired]
	public function connect(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->accounts->connectRequest(
			accountId: $id,
			uid: $uid,
			returnUrl: $this->returnUrl(accountId: $id),
		);

		return $this->answer(result: $result);
	}//end connect()

	/**
	 * POST /api/social-accounts/{id}/attach — record the credential a completed
	 * connection produced.
	 *
	 * @param string $id The account.
	 *
	 * @return JSONResponse `{account}`, or a refusal.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	#[NoAdminRequired]
	public function attach(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		// One field, and it is verified against the broker before it is stored.
		$result = $this->accounts->attachCredential(
			accountId: $id,
			uid: $uid,
			payload: ['credentialRef' => (string)$this->request->getParam('credentialRef', '')],
		);

		return $this->answer(result: $result);
	}//end attach()

	/**
	 * POST /api/social-accounts/{id}/revoke — end the connection, keep the history.
	 *
	 * @param string $id The account.
	 *
	 * @return JSONResponse `{account}`, or a refusal.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	#[NoAdminRequired]
	public function revoke(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->accounts->revoke(accountId: $id, uid: $uid));
	}//end revoke()

	/**
	 * POST /api/social-accounts/{id}/sync — re-read the status from the broker.
	 *
	 * @param string $id The account.
	 *
	 * @return JSONResponse `{account}`, 403 or 404.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	#[NoAdminRequired]
	public function sync(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$account = $this->accounts->getAccount(accountId: $id);
		if ($account === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return $this->refuse();
		}

		return new JSONResponse(['account' => $this->accounts->syncStatus(accountId: $id)]);
	}//end sync()

	/**
	 * Turn a service answer into a response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end answer()

	/**
	 * Where the network's consent screen comes back to. A path on this
	 * instance only: OpenRegister reduces anything else to its own fallback.
	 *
	 * @param string $accountId The account being connected.
	 *
	 * @return string The return path.
	 */
	private function returnUrl(string $accountId): string {
		return '/apps/' . Application::APP_ID . '/social-accounts/' . rawurlencode($accountId);
	}//end returnUrl()

	/**
	 * The session's user id, or null when there is no session.
	 *
	 * @return string|null The user id, or null.
	 */
	private function requireUser(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end requireUser()

	/**
	 * The session's user id when they may use the marketing section.
	 *
	 * @return string|null The user id, or null.
	 */
	private function requireCrmUser(): ?string {
		$uid = $this->requireUser();
		if ($uid === null || $this->policy->isPrivileged(uid: $uid) === false) {
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
	 * The account fields a client may send.
	 *
	 * `ownerUserId`, `credentialRef`, `status` and `followerCount` are absent
	 * by construction: the service stamps them and a value arriving here would
	 * have to be dropped there anyway.
	 *
	 * @return array<string, mixed> The sanitised payload.
	 */
	private function collectBody(): array {
		$body = [];
		foreach (SocialAccountService::CLIENT_WRITABLE as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		return $body;
	}//end collectBody()
}//end class
