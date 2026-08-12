<?php

/**
 * Pipelinq SegmentController.
 *
 * REST surface for the marketing-segmentation-and-blast chain's Segment
 * entity. Endpoints delegate to SegmentService (member 02): list /
 * get-with-size / create (rule-tree validated) / preview members /
 * refresh size. `createdBy` is sourced from IUserSession — request
 * bodies are not trusted for identity (ADR-005).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for Segment entities.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#segmentcontroller-task-2.7-of-giant
 */
class SegmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SegmentService $segmentService Segment service (member 02).
	 * @param IUserSession $userSession Current user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly SegmentService $segmentService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/segments — paginated list.
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
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$envelope = $this->segmentService->listSegments(page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}//end index()

	/**
	 * POST /api/segments — create a Segment after rule-tree validation.
	 *
	 * @return JSONResponse 201 with segment+estimatedSize, 400 on invalid input.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->requireUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$payload = $this->collectSegmentBody();
		$result = $this->segmentService->createSegment(payload: $payload, createdByUid: $user);
		return $this->renderCreate(result: $result);
	}//end create()

	/**
	 * GET /api/segments/:id — fetch with estimatedSize.
	 *
	 * @param string $id Segment UUID or slug.
	 *
	 * @return JSONResponse 200 with Segment+estimatedSize or 404 generic.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$segment = $this->segmentService->getSegmentById(segmentId: $id);
		if ($segment === null) {
			return $this->notFound();
		}

		$segment['estimatedSize'] = $this->segmentService->estimateSize(segmentId: $id);
		return new JSONResponse($segment);
	}//end show()

	/**
	 * GET /api/segments/:id/members — preview matching recipients.
	 *
	 * @param string $id Segment UUID or slug.
	 * @param int $limit Cap (clamped server-side to 500).
	 *
	 * @return JSONResponse `{members[]}` projected for the blast engine.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function members(string $id, int $limit = 50): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$rows = $this->segmentService->previewSegmentMembers(segmentId: $id, limit: $limit);
		return new JSONResponse(['members' => $rows]);
	}//end members()

	/**
	 * POST /api/segments/:id/size — recompute + persist estimatedSize.
	 *
	 * @param string $id Segment UUID or slug.
	 *
	 * @return JSONResponse `{estimatedSize}`.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	#[NoAdminRequired]
	public function refreshSize(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$size = $this->segmentService->refreshSegmentSize(segmentId: $id);
		return new JSONResponse(['estimatedSize' => $size]);
	}//end refreshSize()

	/**
	 * Resolve the authenticated user id.
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
	 * Collect a sanitised Segment create body.
	 *
	 * Any client-supplied `createdBy` is dropped. `rules` is accepted
	 * either as a JSON object/array body or as a JSON-encoded string —
	 * the service-level validator rejects non-array shapes either way.
	 *
	 * @return array<string, mixed> Sanitised payload.
	 */
	private function collectSegmentBody(): array {
		$rules = $this->request->getParam('rules');
		if (is_string($rules) === true) {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				$rules = $decoded;
			}
		}

		return [
			'name' => (string)$this->request->getParam('name', ''),
			'description' => (string)$this->request->getParam('description', ''),
			'entityType' => (string)$this->request->getParam('entityType', ''),
			'rules' => $rules,
		];
	}//end collectSegmentBody()

	/**
	 * Render the create result.
	 *
	 * @param array{segment?: array<string, mixed>, error?: string, estimatedSize?: int} $result Service result.
	 *
	 * @return JSONResponse
	 */
	private function renderCreate(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		$payload = [
			'segment' => ($result['segment'] ?? null),
			'estimatedSize' => (int)($result['estimatedSize'] ?? 0),
		];
		return new JSONResponse($payload, Http::STATUS_CREATED);
	}//end renderCreate()

	/**
	 * Generic 401 response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()

	/**
	 * Generic 404 response.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
