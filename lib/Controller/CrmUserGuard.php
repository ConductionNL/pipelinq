<?php

/**
 * Pipelinq CrmUserGuard.
 *
 * The caller gate of the marketing content endpoints.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Runs an action for the session's user, or answers the single 403.
 *
 * Authentication is not authorization: unpublished articles, lists, posts and
 * subscriptions are the tenant's own, so they are gated on the same policy.
 * An unauthenticated and an unprivileged caller get the same refusal, so they
 * cannot tell each other apart.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
final class CrmUserGuard {
	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy      Per-object owner access policy.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * Run the action for a CRM user, refusing anyone else.
	 *
	 * @param callable(string): JSONResponse $action The action, given the caller's uid.
	 *
	 * @return JSONResponse The action's response, or the 403.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function respond(callable $action): JSONResponse {
		$uid = $this->signedInUid();
		if ($uid === null || $this->policy->isPrivileged(uid: $uid) === false) {
			return $this->refusal();
		}

		return $action($uid);
	}//end respond()

	/**
	 * Run the action for any signed-in user; the action does its own per-object check.
	 *
	 * @param callable(string): JSONResponse $action The action, given the caller's uid.
	 *
	 * @return JSONResponse The action's response, or the 403.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function respondToUser(callable $action): JSONResponse {
		$uid = $this->signedInUid();
		if ($uid === null) {
			return $this->refusal();
		}

		return $action($uid);
	}//end respondToUser()

	/**
	 * The session's user id, or null.
	 *
	 * @return string|null The user id, or null.
	 */
	private function signedInUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end signedInUid()

	/**
	 * The single refusal.
	 *
	 * @return JSONResponse A 403.
	 */
	private function refusal(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end refusal()
}//end class
