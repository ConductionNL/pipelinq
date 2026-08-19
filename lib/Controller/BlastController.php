<?php

/**
 * Pipelinq BlastController.
 *
 * REST surface for the marketing-segmentation-and-blast chain's Blast
 * entity. Exposes CRUD + lifecycle endpoints (send / cancel) and the
 * BlastDelivery sub-list, all delegated to BlastService (member 04) so
 * the controller stays thin. `createdBy` is ALWAYS sourced from
 * IUserSession — request bodies are not trusted for identity (ADR-005).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for Blast entities.
 *
 * Every endpoint declares NoAdminRequired — Blasts are operator content,
 * not admin settings — and resolves the acting user via IUserSession so
 * the `createdBy` field is always server-authoritative.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */
class BlastController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request.
     * @param BlastService       $blastService       Blast orchestration service.
     * @param AttributionService $attributionService Attribution roll-up service (member 04).
     * @param IUserSession       $userSession        Current user session.
     */
    public function __construct(
        IRequest $request,
        private readonly BlastService $blastService,
        private readonly AttributionService $attributionService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/blasts — paginated list, optionally filtered by status.
     *
     * @param string|null $status Status filter (draft/scheduled/sending/sent/...).
     * @param int         $page   1-based page number.
     * @param int         $limit  Page size.
     *
     * @return JSONResponse `{data[], pagination}`.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function index(?string $status=null, int $page=1, int $limit=20): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $envelope = $this->blastService->listBlasts(status: $status, page: $page, limit: $limit);
        return new JSONResponse($envelope);
    }//end index()

    /**
     * POST /api/blasts — create a draft Blast.
     *
     * @return JSONResponse 201 with the new Blast, 400 on invalid input.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $user = $this->requireUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $payload = $this->collectBlastBody();
        $result  = $this->blastService->createDraftBlast(payload: $payload, createdByUid: $user);
        return $this->renderCreate(result: $result);
    }//end create()

    /**
     * GET /api/blasts/:id — fetch one Blast.
     *
     * @param string $id Blast UUID or slug.
     *
     * @return JSONResponse 200 with the Blast or 404 generic.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $blast = $this->blastService->getBlastById(blastId: $id);
        if ($blast === null) {
            return $this->notFound();
        }

        return new JSONResponse($blast);
    }//end show()

    /**
     * PATCH /api/blasts/:id — rename a Blast (name field only).
     *
     * @param string $id Blast UUID or slug.
     *
     * @return JSONResponse 200 with the patched Blast, 400/404 on failure.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $name = trim((string) $this->request->getParam('name', ''));
        if ($name === '') {
            return new JSONResponse(['error' => 'Invalid name'], Http::STATUS_BAD_REQUEST);
        }

        $blast = $this->blastService->patchBlastName(blastId: $id, name: $name);
        if ($blast === null) {
            return $this->notFound();
        }

        return new JSONResponse($blast);
    }//end update()

    /**
     * POST /api/blasts/:id/send — queue + transition the Blast.
     *
     * @param string $id Blast UUID or slug.
     *
     * @return JSONResponse 200 with the send-summary envelope.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function send(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $summary = $this->blastService->sendBlast(blastId: $id);
        return new JSONResponse($summary);
    }//end send()

    /**
     * POST /api/blasts/:id/cancel — abort a queued/scheduled Blast.
     *
     * @param string $id Blast UUID or slug.
     *
     * @return JSONResponse 200 with the cancel summary.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function cancel(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $summary = $this->blastService->cancelBlast(blastId: $id);
        return new JSONResponse($summary);
    }//end cancel()

    /**
     * GET /api/blasts/:id/deliveries — paginated BlastDelivery rows.
     *
     * @param string $id    Blast UUID or slug.
     * @param int    $page  1-based page number.
     * @param int    $limit Page size.
     *
     * @return JSONResponse `{data[], pagination}` scoped to the supplied Blast.
     *
     * @spec openspec/specs/marketing-api/spec.md
     */
    #[NoAdminRequired]
    public function deliveries(string $id, int $page=1, int $limit=20): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $envelope = $this->blastService->listDeliveriesForBlast(blastId: $id, page: $page, limit: $limit);
        return new JSONResponse($envelope);
    }//end deliveries()

    /**
     * GET /api/blasts/:id/attribution — attributed deal count + summed
     * value (EUR) for one Blast, surfaced by the Performance Dashboard
     * Attribution tab (member 08).
     *
     * @param string $id Blast UUID or slug.
     *
     * @return JSONResponse 200 with `{blastId, dealCount, attributedValue, currency}`, 404 on unknown Blast.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-08-performance-dashboard/tasks.md#performancedashboard-vue-task-3-4-of-giant
     */
    #[NoAdminRequired]
    public function attribution(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $blast = $this->blastService->getBlastById(blastId: $id);
        if ($blast === null) {
            return $this->notFound();
        }

        $summary = $this->attributionService->getBlastAttributionSummary(blastId: $id);
        return new JSONResponse($summary);
    }//end attribution()

    /**
     * Return the authenticated user id, or null when unauthenticated.
     *
     * @return string|null UID or null.
     */
    private function requireUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end requireUser()

    /**
     * Collect a sanitised draft-blast body from the request.
     *
     * Any frontend-supplied `createdBy` is dropped here — it's only set
     * server-side from the IUserSession UID (ADR-005).
     *
     * @return array<string, mixed> Sanitised payload.
     */
    private function collectBlastBody(): array
    {
        return [
            'name'              => (string) $this->request->getParam('name', ''),
            'segmentId'         => (string) $this->request->getParam('segmentId', ''),
            'templateId'        => (string) $this->request->getParam('templateId', ''),
            'channel'           => (string) $this->request->getParam('channel', ''),
            'connectorSourceId' => (string) $this->request->getParam('connectorSourceId', ''),
            'scheduledFor'      => (string) $this->request->getParam('scheduledFor', ''),
        ];
    }//end collectBlastBody()

    /**
     * Render the create result as a JSONResponse with the right status.
     *
     * @param array{blast?: array<string, mixed>, error?: string} $result Service result.
     *
     * @return JSONResponse
     */
    private function renderCreate(array $result): JSONResponse
    {
        if (isset($result['error']) === true) {
            return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result['blast'] ?? null, Http::STATUS_CREATED);
    }//end renderCreate()

    /**
     * Generic 401 response.
     *
     * @return JSONResponse
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
    }//end unauthorized()

    /**
     * Generic 404 response.
     *
     * @return JSONResponse
     */
    private function notFound(): JSONResponse
    {
        return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
    }//end notFound()
}//end class
