<?php

/**
 * Pipelinq PortalRequestController.
 *
 * The customer request surface: list own requests, read one request's
 * customer-safe detail (internal notes stripped), submit a new request, and add
 * a reply that unpauses an awaiting-customer request. Identity always comes from
 * the bearer token; the request service enforces per-customer scoping, the
 * customer-exposed category check, the 25 MB attachment limit and the 5/hour
 * submission rate limit (ADR-005, REQ-004 / REQ-006).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Portal request list/submit/reply endpoints.
 */
class PortalRequestController extends PortalApiController
{
    /**
     * Constructor.
     *
     * @param IRequest             $request  The request.
     * @param PortalRequestGuard   $guard    The portal guard.
     * @param LoggerInterface      $logger   The logger.
     * @param PortalRequestService $requests The request service.
     * @param PortalTenantService  $tenant   The tenant service.
     */
    public function __construct(
        IRequest $request,
        PortalRequestGuard $guard,
        LoggerInterface $logger,
        private PortalRequestService $requests,
        private PortalTenantService $tenant,
    ) {
        parent::__construct(request: $request, guard: $guard, logger: $logger);
    }//end __construct()

    /**
     * List the customer's requests.
     *
     * @return JSONResponse The paginated requests.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): JSONResponse
    {
        return $this->guarded(
                handler: function (): array {
                    $ctx  = $this->requireSession();
                    $page = $this->requests->getForAccount(
                    $ctx['account'],
                    $this->intParam(name: 'page', default: 1),
                    $this->intParam(name: 'perPage', default: 10)
                    );
                    return [$page, Http::STATUS_OK];
                }
                );
    }//end index()

    /**
     * Get one request's customer-safe detail.
     *
     * @param string $id The request id.
     *
     * @return JSONResponse The detail, or 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $id): JSONResponse
    {
        return $this->guarded(
                handler: function () use ($id): array {
                    $ctx    = $this->requireSession();
                    $expose = ($this->tenant->getConfig($ctx['tenantId'])['exposeAssigneeName'] ?? false) === true;
                    $detail = $this->requests->getDetailForAccount(account: $ctx['account'], requestId: $id, exposeAssigneeName: $expose);
                    if ($detail === null) {
                        return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
                    }

                    return [$detail, Http::STATUS_OK];
                }
                );
    }//end show()

    /**
     * Submit a new request.
     *
     * @return JSONResponse The created request summary + ETA.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function create(): JSONResponse
    {
        return $this->guarded(
            handler: function (): array {
                $ctx         = $this->requireSession();
                $attachments = $this->request->getParam('attachments', []);
                if (is_array($attachments) === false) {
                    $attachments = [];
                }

                $result = $this->requests->submit(
                    account: $ctx['account'],
                    tenantId: $ctx['tenantId'],
                    subject: $this->strParam(name: 'subject'),
                    body: $this->strParam(name: 'body'),
                    attachments: $attachments,
                    categoryId: $this->strParam(name: 'categoryId')
                );
                return [$result, Http::STATUS_CREATED];
            }
        );
    }//end create()

    /**
     * Add a customer reply to a request.
     *
     * @param string $id The request id.
     *
     * @return JSONResponse The updated detail.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function reply(string $id): JSONResponse
    {
        return $this->guarded(
                handler: function () use ($id): array {
                    $ctx    = $this->requireSession();
                    $detail = $this->requests->addReply(
                        account: $ctx['account'],
                        tenantId: $ctx['tenantId'],
                        requestId: $id,
                        message: $this->strParam(name: 'message')
                    );
                    return [$detail, Http::STATUS_OK];
                }
                );
    }//end reply()
}//end class
