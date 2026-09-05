<?php

/**
 * Pipelinq TemplateController.
 *
 * REST surface for CampaignTemplate entities. Endpoints delegate to
 * ComplianceService (member 03) which runs `validateTemplate()` before
 * any persist — so an email template missing the `{{unsubscribe_link}}`
 * token or a physical-address footer is rejected at the controller
 * boundary. `createdBy` is sourced from IUserSession (ADR-005).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\ComplianceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for CampaignTemplate entities.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
 */
class TemplateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ComplianceService $complianceService Compliance + template repo.
	 * @param ArticleService $articleService Article reader and `{{articles}}` renderer.
	 * @param IUserSession $userSession Current user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly ComplianceService $complianceService,
		private readonly ArticleService $articleService,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/templates — paginated list.
	 *
	 * @param int $page 1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse `{data[], pagination}`.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Message templates carry customer-facing copy and compliance state —
		// a CRM capability. Admins bypass via the policy.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->complianceService->listTemplates(page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}//end index()

	/**
	 * POST /api/templates — create after compliance validation.
	 *
	 * @return JSONResponse 201 with the template or 400 generic error.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$payload = $this->collectTemplateBody();
		$result = $this->complianceService->createTemplate(payload: $payload, createdByUid: $user);
		return $this->renderResult(result: $result, successStatus: Http::STATUS_CREATED);
	}//end create()

	/**
	 * GET /api/templates/:id — fetch one CampaignTemplate.
	 *
	 * @param string $id Template UUID or slug.
	 *
	 * @return JSONResponse 200 with the template or 404 generic.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Message templates carry customer-facing copy and compliance state —
		// a CRM capability. Admins bypass via the policy.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return $this->notFound();
		}

		return new JSONResponse($template);
	}//end show()

	/**
	 * PATCH /api/templates/:id — patch a template after re-validation.
	 *
	 * @param string $id Template UUID or slug.
	 *
	 * @return JSONResponse 200 with the patched template or 400 / 404.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Message templates carry customer-facing copy and compliance state —
		// a CRM capability. Admins bypass via the policy.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$patch = $this->collectTemplateBody();
		$result = $this->complianceService->patchTemplate(templateId: $id, patch: $patch);
		return $this->renderResult(result: $result, successStatus: Http::STATUS_OK);
	}//end update()

	/**
	 * GET /api/templates/:id/preview — the bodies as they will be sent.
	 *
	 * The preview runs the same `{{articles}}` expansion the send path runs,
	 * so what a marketer reads before sending is produced by the code that
	 * will do the sending. Per-recipient tokens are deliberately left in
	 * place: a preview showing one recipient's address would say nothing
	 * about whether the token is there at all.
	 *
	 * @param string $id Template UUID or slug.
	 *
	 * @return JSONResponse The expanded bodies and the embedded articles, or 404.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
	 */
	#[NoAdminRequired]
	public function preview(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return $this->notFound();
		}

		$ids = ($template['articleIds'] ?? []);
		if (is_array($ids) === false) {
			$ids = [];
		}

		$articles = $this->articleService->loadArticlesByIds(articleIds: $ids);

		return new JSONResponse([
			'subject' => (string)($template['subject'] ?? ''),
			'bodyHtml' => $this->articleService->expandArticlesMarker(
				body: (string)($template['bodyHtml'] ?? ''),
				articles: $articles,
				format: ArticleService::FORMAT_HTML,
			),
			'bodyText' => $this->articleService->expandArticlesMarker(
				body: (string)($template['bodyText'] ?? ''),
				articles: $articles,
				format: ArticleService::FORMAT_TEXT,
			),
			'articles' => array_map(
				static fn (array $article): array => [
					'title' => (string)($article['title'] ?? ''),
					'summary' => (string)($article['summary'] ?? ''),
				],
				$articles,
			),
		]);
	}//end preview()

	/**
	 * Authenticated user id, or null.
	 *
	 * @return string|null UID or null.
	 */
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end requireUser()

	/**
	 * Collect a sanitised template body. Drops any client-supplied
	 * `createdBy` / `createdAt` so the server stamp wins.
	 *
	 * @return array<string, mixed> Sanitised payload.
	 */
	private function collectTemplateBody(): array {
		return [
			'name' => (string)$this->request->getParam('name', ''),
			'channel' => (string)$this->request->getParam('channel', ''),
			'subject' => (string)$this->request->getParam('subject', ''),
			'bodyHtml' => (string)$this->request->getParam('bodyHtml', ''),
			'bodyText' => (string)$this->request->getParam('bodyText', ''),
			'senderName' => (string)$this->request->getParam('senderName', ''),
			'senderEmail' => (string)$this->request->getParam('senderEmail', ''),
			'footerOverride' => (string)$this->request->getParam('footerOverride', ''),
			'articleIds' => $this->request->getParam('articleIds', []),
		];
	}//end collectTemplateBody()

	/**
	 * Map a service result to a JSONResponse with the right status.
	 *
	 * @param array{template?: array<string, mixed>, error?: string} $result Service result.
	 * @param int $successStatus HTTP status on success.
	 *
	 * @return JSONResponse
	 */
	private function renderResult(array $result, int $successStatus): JSONResponse {
		if (isset($result['error']) === true) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($result['error'] === 'Template not found') {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $result['error']], $status);
		}

		return new JSONResponse($result['template'] ?? null, $successStatus);
	}//end renderResult()

	/**
	 * Generic 401 response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()

	/**
	 * Deny a caller who is authenticated but not a CRM user.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end forbidden()

	/**
	 * Generic 404 response.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
