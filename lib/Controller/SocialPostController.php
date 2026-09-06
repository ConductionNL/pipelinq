<?php

/**
 * Pipelinq SocialPostController.
 *
 * REST surface for the composer, the approval gate, the publications and the
 * performance ranking, following the `ArticleController` conventions:
 * identity from `IUserSession` and never from a request body, a per-object
 * guard on every route that names an object (ADR-005), and one generic
 * refusal.
 *
 * The approve and reject routes are the ones worth reading twice. The approver
 * is the session user, always. A body naming somebody else is ignored by
 * `SocialPostService::approve()`, and this controller does not read one, so
 * there is no path by which a client's claim about who approved a post could
 * reach the record (rule 4 of the marketing architecture, ADR-088).
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialMetricsService;
use OCA\Pipelinq\Service\SocialPostService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for social posts, their publications and the ranking.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One route per verb the
 *  composer and the approval gate need; each is three lines over the service.
 */
class SocialPostController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SocialPostService $posts The post lifecycle and publishing.
	 * @param SocialPublicationStore $publications The per-account result rows.
	 * @param SocialMetricsService $metrics The ranking.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Privileged-group policy.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SocialPostService $posts,
		private readonly SocialPublicationStore $publications,
		private readonly SocialMetricsService $metrics,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/social-posts — every post, optionally by status.
	 *
	 * @param string $status A status to filter on, or an empty string.
	 *
	 * @return JSONResponse `{data[]}`.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	#[NoAdminRequired]
	public function index(string $status = ''): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->posts->listPosts(status: $status));
	}//end index()

	/**
	 * GET /api/social-posts/{id} — one post.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{post}`, or 404.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$post = $this->posts->getPost(postId: $id);
		if ($post === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['post' => $post]);
	}//end show()

	/**
	 * POST /api/social-posts — write a draft.
	 *
	 * @return JSONResponse 201 with the post, or 400.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->posts->createPost(payload: $this->collectBody(), uid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end create()

	/**
	 * PATCH /api/social-posts/{id} — edit a draft.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{post}`, or 400.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->posts->updatePost(postId: $id, payload: $this->collectBody(), uid: $uid));
	}//end update()

	/**
	 * POST /api/social-posts/{id}/submit — put a draft up for approval.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{post}`, or 400 naming the network that does not fit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	#[NoAdminRequired]
	public function submit(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(result: $this->posts->submitForApproval(postId: $id));
	}//end submit()

	/**
	 * POST /api/social-posts/{id}/approve — a person approves the post.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{post}`, or 400.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		// The approver is the session. No field of the body says who decided.
		return $this->answer(
			result: $this->posts->approve(postId: $id, uid: $uid, note: (string)$this->request->getParam('note', '')),
		);
	}//end approve()

	/**
	 * POST /api/social-posts/{id}/reject — a person sends it back.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{post}`, or 400.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->answer(
			result: $this->posts->reject(postId: $id, uid: $uid, note: (string)$this->request->getParam('note', '')),
		);
	}//end reject()

	/**
	 * GET /api/social-posts/{id}/publications — what happened per account.
	 *
	 * @param string $id The post.
	 *
	 * @return JSONResponse `{data[]}`.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	#[NoAdminRequired]
	public function publications(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse(['data' => $this->publications->forPost(postId: $id)]);
	}//end publications()

	/**
	 * POST /api/social-publications/{id}/retry — try one failed account again.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse `{publication}`, or 400 with the reason a retry cannot fix.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	#[NoAdminRequired]
	public function retry(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->refuse();
		}

		// The per-object guard lives in the service, which loads the account
		// this publication went to and refuses a caller who may not act on it.
		return $this->answer(result: $this->posts->retryPublication(publicationId: $id, uid: $uid));
	}//end retry()

	/**
	 * GET /api/social-performance — publications ranked by engagement rate.
	 *
	 * @param string $network A network to limit the ranking to, or an empty string.
	 * @param int $limit How many rows.
	 *
	 * @return JSONResponse `{data[]}`.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
	 */
	#[NoAdminRequired]
	public function performance(string $network = '', int $limit = 50): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse(['data' => $this->metrics->ranking(network: $network, limit: $limit)]);
	}//end performance()

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
	 * The session's user id, or null.
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
	 * The single refusal.
	 *
	 * @return JSONResponse A 403.
	 */
	private function refuse(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end refuse()

	/**
	 * The post fields a client may send.
	 *
	 * `status`, `approvals`, `agentAuthored`, `agentAuthoredBy`, `createdBy`
	 * and `publishedAt` are absent by construction: the service stamps them.
	 *
	 * @return array<string, mixed> The sanitised payload.
	 */
	private function collectBody(): array {
		$body = [];
		foreach (['title', 'articleId', 'campaignId', 'body', 'link', 'scheduledFor'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		foreach (['accountIds', 'media', 'variants'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$body[$key] = $value;
			}
		}

		return $body;
	}//end collectBody()
}//end class
