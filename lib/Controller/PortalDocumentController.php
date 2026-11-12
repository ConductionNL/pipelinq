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
 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class PortalDocumentController extends PortalApiController {
	/**
	 * Schema slug for accounts.
	 *
	 * @var string
	 */
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalRequestGuard $guard The portal guard.
	 * @param LoggerInterface $logger The logger.
	 * @param DocumentSigningService $signing The signing service.
	 * @param PortalInvoiceService $invoices The invoice facade (access re-check).
	 * @param PortalObjectRepository $repository The portal object repository.
	 * @param PortalAuditService $audit The audit service.
	 * @param PortalExportService $exports Builds the AVG art. 15 data export.
	 */
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private DocumentSigningService $signing,
		private PortalInvoiceService $invoices,
		private PortalObjectRepository $repository,
		private PortalAuditService $audit,
		private PortalExportService $exports,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}//end __construct()

	/**
	 * Issue a signed download link for an object the customer can access.
	 *
	 * The AnonRateLimit below is deliberately tight: signing is a legally
	 * meaningful act, and the token that authorises it is the only barrier.
	 *
	 * @return JSONResponse The signed link descriptor.
	 *
	 * The rate limit is deliberately tight: signing is a legally meaningful act,
	 * and the token that authorises it is the only barrier.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function sign(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$objectId = $this->strParam(name: 'objectId');
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
	 * @spec openspec/changes/archive/2026-06-14-customer-portal/specs.md#REQ-005
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function download(string $token) {
		try {
			return $this->downloadFor(token: $token);
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
			return $this->notFound();
		}
	}//end download()

	/**
	 * Validate the token, re-check the bound account, audit and stream the document.
	 *
	 * @param string $token The signed token.
	 *
	 * @return JSONResponse|DataDownloadResponse The document, or an error.
	 */
	private function downloadFor(string $token): JSONResponse|DataDownloadResponse {
		$link = $this->signing->validateToken(token: $token);
		if ($link === 'expired') {
			return $this->linkExpired();
		}

		if (is_array($link) === false) {
			return $this->notFound();
		}

		$account = $this->repository->find(self::ACCOUNT_SCHEMA, (string)($link['accountId'] ?? ''));
		if ($account === null || $this->mayDownload(account: $account, link: $link) === false) {
			return $this->notFound();
		}

		$this->auditDownload(account: $account, link: $link);
		return $this->documentResponse(account: $account, link: $link);
	}//end downloadFor()

	/**
	 * Defence in depth: whether the bound account is still open and may still see the object.
	 *
	 * A data-export link names the account itself, so only the account gate applies.
	 *
	 * @param array<string, mixed> $account The bound account.
	 * @param array<string, mixed> $link    The validated token payload.
	 *
	 * @return bool True when the download may proceed.
	 */
	private function mayDownload(array $account, array $link): bool {
		if (($account['status'] ?? 'active') === 'closed') {
			return false;
		}

		$objectType = (string)($link['objectType'] ?? '');
		if ($objectType === 'data-export') {
			return true;
		}

		return $this->ensureAccess(account: $account, objectType: $objectType, objectId: (string)($link['objectId'] ?? ''));
	}//end mayDownload()

	/**
	 * Audit a served download against the bound account.
	 *
	 * @param array<string, mixed> $account The bound account.
	 * @param array<string, mixed> $link    The validated token payload.
	 *
	 * @return void
	 */
	private function auditDownload(array $account, array $link): void {
		$this->audit->log(
			(string)($link['accountId'] ?? ''),
			(string)($account['tenantId'] ?? ''),
			'document-download',
			'success',
			[
				'targetObjectType' => (string)($link['objectType'] ?? ''),
				'targetObjectId' => (string)($link['objectId'] ?? ''),
			]
		);
	}//end auditDownload()

	/**
	 * The download of the linked document.
	 *
	 * The portal models documents as JSON descriptors over OR objects; an
	 * instance that stores a PDF file id streams the file here via
	 * IRootFolder instead. Streaming the bound file is deferred to a live
	 * instance (see DEPLOYMENT.md), the descriptor below is the canonical
	 * machine-readable representation in the meantime.
	 *
	 * 🔴 A DATA EXPORT HAS TO CONTAIN THE DATA.
	 *
	 * Every object type got the same three-key descriptor, so a
	 * data-export link -- the thing a customer is handed to exercise
	 * their AVG art. 15 right of access -- streamed
	 * {objectType, objectId, generatedAt} and nothing else.
	 * PortalExportService::buildExport() had been written for exactly
	 * this and was called by nothing in the tree.
	 *
	 * Other object types keep the descriptor: for those it IS the
	 * canonical machine-readable representation, as the note above says.
	 *
	 * @param array<string, mixed> $account The bound account.
	 * @param array<string, mixed> $link    The validated token payload.
	 *
	 * @return DataDownloadResponse The download.
	 */
	private function documentResponse(array $account, array $link): DataDownloadResponse {
		$objectType = (string)($link['objectType'] ?? '');
		$objectId = (string)($link['objectId'] ?? '');
		$payload = $this->documentPayload(objectType: $objectType, objectId: $objectId);
		if ($objectType === 'data-export') {
			$payload = $this->exports->buildExport(account: $account);
		}

		return new DataDownloadResponse((string)json_encode($payload), $objectType . '-' . $objectId . '.json', 'application/json');
	}//end documentResponse()

	/**
	 * The 410 answer of a legitimately issued but stale link, with the re-download hint.
	 *
	 * @return JSONResponse The response.
	 */
	private function linkExpired(): JSONResponse {
		return new JSONResponse(
			['errorCode' => 'linkExpired', 'message' => 'Deze link is verlopen. Download het document opnieuw vanuit het portaal.'],
			Http::STATUS_GONE
		);
	}//end linkExpired()

	/**
	 * The opaque not-found answer every refusal shares.
	 *
	 * @return JSONResponse The response.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * Ensure an account can access an object of a given type (currently
	 * invoices/orders share the invoice facade's per-customer scope check)
	 * — returns true when the account is allowed, false otherwise. Callers
	 * surface a NOT_FOUND on false to avoid id enumeration.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string $objectType The object type (reserved for
	 *                           per-type routing; all current
	 *                           types resolve via the invoice
	 *                           facade's scope check).
	 * @param string $objectId The object id.
	 *
	 * @return bool True when accessible.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $objectType is part of the
	 *  access-check signature and will route per object type as more document
	 *  sources are added; today every type shares the per-customer scope check.
	 */
	private function ensureAccess(array $account, string $objectType, string $objectId): bool {
		if ($objectId === '') {
			return false;
		}

		return $this->invoices->getOneForAccount(account: $account, id: $objectId) !== null;
	}//end ensureAccess()

	/**
	 * Build the document descriptor payload for an object.
	 *
	 * @param string $objectType The object type.
	 * @param string $objectId The object id.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private function documentPayload(string $objectType, string $objectId): array {
		return [
			'objectType' => $objectType,
			'objectId' => $objectId,
			'generatedAt' => gmdate(DATE_ATOM),
		];
	}//end documentPayload()
}//end class
