<?php

/**
 * Pipelinq PortalDocumentController.
 *
 * Signed-URL document download proxy. The customer first asks for a short-lived
 * signed link for an object they can see; this controller then validates that
 * signature + TTL on download, re-checks that the token's bound account still
 * has access to the object (defence in depth — a leaked link cannot outlive the
 * grant), audits the access, and streams the document. An expired but
 * legitimately-issued link returns 410 Gone; a tampered/forged one returns 404
 * (ADR-005, REQ-005).
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
 * @spec openspec/changes/customer-portal/specs.md#REQ-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Portal document download proxy.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the signing service, the
 *  access re-check facade, the account store and the audit log a secure proxy needs.
 */
class PortalDocumentController extends PortalApiController
{
    /**
     * Schema slug for accounts.
     *
     * @var string
     */
    private const ACCOUNT_SCHEMA = 'portalAccount';

    /**
     * Constructor.
     *
     * @param IRequest               $request    The request.
     * @param PortalRequestGuard     $guard      The portal guard.
     * @param LoggerInterface        $logger     The logger.
     * @param DocumentSigningService $signing    The signing service.
     * @param PortalInvoiceService   $invoices   The invoice facade (access re-check).
     * @param PortalObjectRepository $repository The portal object repository.
     * @param PortalAuditService     $audit      The audit service.
     */
    public function __construct(
        IRequest $request,
        PortalRequestGuard $guard,
        LoggerInterface $logger,
        private DocumentSigningService $signing,
        private PortalInvoiceService $invoices,
        private PortalObjectRepository $repository,
        private PortalAuditService $audit,
    ) {
        parent::__construct(request: $request, guard: $guard, logger: $logger);
    }//end __construct()

    /**
     * Issue a signed download link for an object the customer can access.
     *
     * @return JSONResponse The signed link descriptor.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function sign(): JSONResponse
    {
        return $this->guarded(
                handler: function (): array {
                    $ctx        = $this->requireSession();
                    $objectId   = $this->strParam(name: 'objectId');
                    $objectType = $this->strParam(name: 'objectType', default: 'invoice');

                    // Only sign links for objects this account can actually see.
                    if ($this->ensureAccess(account: $ctx['account'], objectType: $objectType, objectId: $objectId) === false) {
                        return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
                    }

                    $signed = $this->signing->generateUrl(objectId: $objectId, objectType: $objectType, accountId: $ctx['accountId']);
                    return [['downloadUrl' => $signed['path'], 'expiresAt' => $signed['expiresAt']], Http::STATUS_OK];
                }
                );
    }//end sign()

    /**
     * Download a document via a signed token.
     *
     * @param string $token The signed token.
     *
     * @return JSONResponse|DataDownloadResponse The document, or an error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/customer-portal/specs.md#REQ-005
     */
    public function download(string $token)
    {
        try {
            $result = $this->signing->validateToken(token: $token);
            if ($result === 'expired') {
                return new JSONResponse(
                    ['errorCode' => 'linkExpired', 'message' => 'Deze link is verlopen. Download het document opnieuw vanuit het portaal.'],
                    Http::STATUS_GONE
                );
            }

            if (is_array($result) === false) {
                return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
            }

            $accountId  = (string) ($result['accountId'] ?? '');
            $objectId   = (string) ($result['objectId'] ?? '');
            $objectType = (string) ($result['objectType'] ?? '');
            $account    = $this->repository->find(self::ACCOUNT_SCHEMA, $accountId);

            // Defence in depth: re-verify the bound account still has access.
            if ($account === null
                || ($account['status'] ?? 'active') === 'closed'
                || ($objectType !== 'data-export' && $this->ensureAccess(account: $account, objectType: $objectType, objectId: $objectId) === false)
            ) {
                return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
            }

            $this->audit->log(
                    $accountId,
                    (string) ($account['tenantId'] ?? ''),
                    'document-download',
                    'success',
                    [
                        'targetObjectType' => $objectType,
                        'targetObjectId'   => $objectId,
                    ]
                    );

            // The portal models documents as JSON descriptors over OR objects; an
            // instance that stores a PDF file id streams the file here via
            // IRootFolder instead. Streaming the bound file is deferred to a live
            // instance (see DEPLOYMENT.md), the descriptor below is the canonical
            // machine-readable representation in the meantime.
            $payload = $this->documentPayload(objectType: $objectType, objectId: $objectId);
            return new DataDownloadResponse(
                (string) json_encode($payload),
                $objectType.'-'.$objectId.'.json',
                'application/json'
            );
        } catch (Throwable $e) {
            // This endpoint is #[PublicPage]: it is reachable unauthenticated,
            // so an uncaught throw would return a framework 500 with a stack
            // trace to an anonymous caller. PortalObjectRepository::find()
            // throws when the bound account cannot be read, which is the
            // commonest way to get here. Answer with the same opaque
            // NOT_FOUND the authorization failures use, so a storage fault
            // cannot be told apart from an unknown token by probing.
            $this->logger->error(
                    'Portal document download failed',
                    ['exception' => $e]
                    );
            return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
        }//end try
    }//end download()

    /**
     * Ensure an account can access an object of a given type (currently
     * invoices/orders share the invoice facade's per-customer scope check)
     * — returns true when the account is allowed, false otherwise. Callers
     * surface a NOT_FOUND on false to avoid id enumeration.
     *
     * @param array<string, mixed> $account    The account.
     * @param string               $objectType The object type (reserved for
     *                                         per-type routing; all current
     *                                         types resolve via the invoice
     *                                         facade's scope check).
     * @param string               $objectId   The object id.
     *
     * @return bool True when accessible.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $objectType is part of the
     *  access-check signature and will route per object type as more document
     *  sources are added; today every type shares the per-customer scope check.
     */
    private function ensureAccess(array $account, string $objectType, string $objectId): bool
    {
        if ($objectId === '') {
            return false;
        }

        return $this->invoices->getOneForAccount(account: $account, id: $objectId) !== null;
    }//end ensureAccess()

    /**
     * Build the document descriptor payload for an object.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object id.
     *
     * @return array<string, mixed> The descriptor.
     */
    private function documentPayload(string $objectType, string $objectId): array
    {
        return [
            'objectType'  => $objectType,
            'objectId'    => $objectId,
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    }//end documentPayload()
}//end class
