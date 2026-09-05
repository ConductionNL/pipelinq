<?php

/**
 * Pipelinq ArticleController.
 *
 * REST surface for the `article` object, following the same conventions as
 * {@see MailingListController}: identity comes from `IUserSession` and never
 * from the request body (ADR-005), every method checks the per-object access
 * policy rather than relying on `#[NoAdminRequired]` alone, and a refusal
 * carries one generic message so an unauthenticated and an unprivileged
 * caller cannot tell each other apart.
 *
 * The surface is deliberately narrow. The Articles index and the article
 * detail page are declarative and read through OpenRegister's own object API,
 * so nothing here repeats that (ADR-022). What is here is what no declarative
 * page can serve: the derived usages, the two lifecycle transitions that stamp
 * a date, and the create and update paths that stamp the author and refuse a
 * client-supplied agent mark.
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
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for articles.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
class ArticleController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ArticleService $articles Article service.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly ArticleService $articles,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/articles — paginated list, newest first.
	 *
	 * @param int $page 1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse `{data[], pagination}`.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->articles->listArticles(page: $page, limit: $limit));
	}//end index()

	/**
	 * GET /api/articles/{id} — one article.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse The article, or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$article = $this->articles->getArticleById(articleId: $id);
		if ($article === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['article' => $article]);
	}//end show()

	/**
	 * POST /api/articles — create an article as a draft.
	 *
	 * @return JSONResponse 201 with the article, or 400 naming the bad field.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$result = $this->articles->createArticle(payload: $this->collectBody(), authorUid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => ($result['article'] ?? null)], Http::STATUS_CREATED);
	}//end create()

	/**
	 * PATCH /api/articles/{id} — change an article's editable fields.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse 200 with the article, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->updateArticle(articleId: $id, patch: $this->collectBody()));
	}//end update()

	/**
	 * POST /api/articles/{id}/publish — publish, stamping the moment once.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse 200 with the article, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->publishArticle(articleId: $id));
	}//end publish()

	/**
	 * POST /api/articles/{id}/archive — take the article out of use.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse 200 with the article, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function archive(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return $this->render(result: $this->articles->archiveArticle(articleId: $id));
	}//end archive()

	/**
	 * POST /api/articles/{id}/transition — apply one declared transition.
	 *
	 * Publish and archive have their own routes because they are what the
	 * interface offers; this one carries the rest of the declared lifecycle
	 * (submit for review, return to draft, restore) without a route each.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse 200 with the article, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function transition(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		$transition = (string)$this->request->getParam('transition', '');

		return $this->render(
			result: $this->articles->applyTransition(articleId: $id, transition: $transition),
		);
	}//end transition()

	/**
	 * GET /api/articles/{id}/usages — where the article has been used.
	 *
	 * @param string $id Article UUID or slug.
	 *
	 * @return JSONResponse `{data[], counts}`.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	#[NoAdminRequired]
	public function usages(string $id): JSONResponse {
		$uid = $this->requireCrmUser();
		if ($uid === null) {
			return $this->refuse();
		}

		return new JSONResponse($this->articles->listUsages(articleId: $id));
	}//end usages()

	/**
	 * Map a service result to a response with the right status.
	 *
	 * @param array{article?: array<string, mixed>, error?: string} $result Service result.
	 *
	 * @return JSONResponse The response.
	 */
	private function render(array $result): JSONResponse {
		if (($result['error'] ?? '') === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => ($result['article'] ?? null)]);
	}//end render()

	/**
	 * Resolve the caller, refusing anyone who is not a CRM user.
	 *
	 * Authentication is not authorization: an unpublished article is the
	 * tenant's unreleased copy, so reading one is gated on the same policy a
	 * segment and a mailing list are.
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
	 * Collect a sanitised article body.
	 *
	 * `author`, `status`, `publishedAt`, `agentAuthored` and
	 * `agentAuthoredBy` are absent by construction: the service stamps them
	 * and a value arriving here would have to be dropped there anyway.
	 *
	 * @return array<string, mixed> Sanitised payload.
	 */
	private function collectBody(): array {
		$body = [];
		foreach (['title', 'slug', 'summary', 'body', 'heroImage', 'language', 'portalPageRef'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$body[$key] = (string)$value;
			}
		}

		foreach (['links', 'tags'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$body[$key] = $value;
			}
		}

		return $body;
	}//end collectBody()
}//end class
