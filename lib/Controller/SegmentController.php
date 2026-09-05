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
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

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
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly SegmentService $segmentService,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
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
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Authentication is not authorization. A segment is a saved query over
		// the customer base; listing or evaluating one exposes who is in it.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$envelope = $this->segmentService->listSegments(page: $page, limit: $limit);
		return new JSONResponse($envelope);
	}//end index()

	/**
	 * GET /api/segments/signals — the derived fields a rule may use.
	 *
	 * The builder needs the catalogue AND the availability report, because a
	 * field whose bookkeeping cannot be read still validates and still saves.
	 * Listing it without saying so would offer the marketer a rule that
	 * silently matches nobody.
	 *
	 * @return JSONResponse `{catalogue, availability}`.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-segment-builder-lists-the-signals-and-validates-a-rule-on-one
	 */
	#[NoAdminRequired]
	public function signals(): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		return new JSONResponse([
			'catalogue' => $this->segmentService->signalCatalogue(),
			'availability' => $this->segmentService->signalAvailability(),
		]);
	}//end signals()

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
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Authentication is not authorization. A segment is a saved query over
		// the customer base; listing or evaluating one exposes who is in it.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
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
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Authentication is not authorization. A segment is a saved query over
		// the customer base; listing or evaluating one exposes who is in it.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$rows = $this->segmentService->previewSegmentMembers(segmentId: $id, limit: $limit);
		return new JSONResponse(['members' => $rows]);
	}//end members()

	/**
	 * POST /api/segments/preview — validate + estimate an unsaved rule tree.
	 *
	 * Backs the SegmentBuilder live-validation and debounced size-estimate
	 * calls (marketing-ui) before a Segment has been persisted — there is
	 * no `:id` yet for `refreshSize()` to recompute against. Delegates to
	 * `SegmentService.previewRulePayload()`, which runs the same
	 * `validateRules()` path `create()` uses and only counts matches when
	 * the tree is valid.
	 *
	 * @return JSONResponse `{valid, error, estimatedSize}`.
	 *
	 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
	 */
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Authentication is not authorization. A rule-tree preview reveals a
		// count over the customer base; only privileged CRM users may run it.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$entityType = strtolower((string)$this->request->getParam('entityType', ''));
		$rules = $this->collectRulesBody();
		$result = $this->segmentService->previewRulePayload(rules: $rules, entityType: $entityType);
		return new JSONResponse($result);
	}//end preview()

	/**
	 * PATCH /api/segments/:id — update a Segment after rule-tree re-validation.
	 *
	 * Backs the SegmentEdit page (marketing-segments-ui-repair): re-runs
	 * `SegmentService.validateRules()` on the (possibly edited) rule tree
	 * before anything is persisted, exactly as `create()` does.
	 *
	 * @param string $id Segment UUID or slug.
	 *
	 * @return JSONResponse 200 with segment+estimatedSize, 400 on invalid input, 404 when missing.
	 *
	 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#requirement-api-endpoints-crud-and-query
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		$payload = $this->collectSegmentBody();
		$result = $this->segmentService->updateSegment(segmentId: $id, payload: $payload);
		return $this->renderUpdate(result: $result);
	}//end update()

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
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorized();
		}

		// Authentication is not authorization. A segment is a saved query over
		// the customer base; listing or evaluating one exposes who is in it.
		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return $this->forbidden();
		}

		try {
			$size = $this->segmentService->refreshSegmentSize(segmentId: $id);
		} catch (Throwable $e) {
			// A refresh that could not be persisted must not answer 200 with the
			// new count: the stored record still holds the old one, and the
			// caller would have no way to know. The service has already logged
			// the cause; this controller carries no logger of its own.
			unset($e);
			return new JSONResponse(
				['error' => 'The refreshed segment size could not be saved'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

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
	 * Deny a caller who is authenticated but not a CRM user.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}//end forbidden()

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
	 * Collect just the `rules` param, accepting either an array/object body
	 * or a JSON-encoded string. Used by {@see preview()}, which has no other
	 * Segment fields to sanitise.
	 *
	 * @return array<string, mixed> The rule tree, or an empty array when absent/malformed.
	 */
	private function collectRulesBody(): array {
		$rules = $this->request->getParam('rules');
		if (is_string($rules) === true) {
			$decoded = json_decode($rules, true);
			if (is_array($decoded) === true) {
				$rules = $decoded;
			}
		}

		if (is_array($rules) === true) {
			return $rules;
		}

		return [];
	}//end collectRulesBody()

	/**
	 * Render the update result.
	 *
	 * @param array{segment?: array<string, mixed>, error?: string, estimatedSize?: int} $result Service result.
	 *
	 * @return JSONResponse
	 */
	private function renderUpdate(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($result['error'] === 'Segment not found') {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(['error' => $result['error']], $status);
		}

		$payload = [
			'segment' => ($result['segment'] ?? null),
			'estimatedSize' => (int)($result['estimatedSize'] ?? 0),
		];
		return new JSONResponse($payload, Http::STATUS_OK);
	}//end renderUpdate()

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
