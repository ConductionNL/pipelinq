<?php

/**
 * Pipelinq Customer360Controller.
 *
 * Read endpoint for the consolidated customer-360 summary (klantbeeld-360-activation).
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
 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
 * @spec openspec/specs/customer-360/spec.md#requirement-customer-360-access-is-logged-doelbinding-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientReadAccess;
use OCA\Pipelinq\Service\ClientRiskPresenter;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the customer-360 consolidated summary endpoint.
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
 */
class Customer360Controller extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param Customer360SummaryService $summaryService The customer 360 summary aggregator.
	 * @param IUserSession $userSession Current user.
	 * @param ClientReadAccess $readAccess The per-object read guard on the client.
	 * @param LoggerInterface $logger Logger — also the doelbinding access-log sink
	 *                                (MVP).
	 * @param ClientRiskPresenter $riskPresenter Churn risk in the viewer's words.
	 */
	public function __construct(
		IRequest $request,
		private Customer360SummaryService $summaryService,
		private IUserSession $userSession,
		private ClientReadAccess $readAccess,
		private LoggerInterface $logger,
		private ClientRiskPresenter $riskPresenter,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/customer-360/summary?clientId=... — the consolidated customer 360 summary.
	 *
	 * Per-object read guard (no IDOR): the caller must be able to READ the
	 * client object itself, resolved through OpenRegister's ObjectService with
	 * the caller's RBAC and multitenancy. A client the caller may not read is
	 * a 403; a client that does not exist (or is in another tenant) is a 404.
	 * On success, every access is logged
	 * (doelbinding, MVP) with the acting user, the client id, and the time,
	 * reusing the app's existing audit/logging facility (design.md's
	 * provisional resolution — no new OR schema for the MVP).
	 *
	 * @return JSONResponse The summary, or an error response.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
	 * @spec openspec/specs/customer-360/spec.md#requirement-customer-360-access-is-logged-doelbinding-mvp
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$clientId = (string)$this->request->getParam('clientId', '');
		if ($clientId === '') {
			return new JSONResponse(['message' => 'Missing required parameter: clientId'], Http::STATUS_BAD_REQUEST);
		}

		// Access follows the client's own read rights in OpenRegister (Ruben,
		// 7 October): whoever may read the client sees its 360 summary.
		[$access, $client] = $this->readAccess->check(clientId: $clientId);
		$refusal = $this->readAccess->refusal(access: $access);
		if ($refusal !== null) {
			return new JSONResponse(['message' => $refusal['message']], $refusal['status']);
		}

		try {
			$summary = $this->summaryService->getSummary(clientId: $clientId);
			$summary['churnRisk'] = $this->riskPresenter->present(churnRisk: ($client['churnRisk'] ?? null));
		} catch (Throwable $e) {
			$this->logger->error(
				'Customer360Controller: summary failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logAccess(actor: $user->getUID(), clientId: $clientId);

		return new JSONResponse($summary);
	}//end summary()

	/**
	 * Log a customer 360 access (doelbinding, MVP) via the app's standard
	 * logger — {@see LoggerInterface} is the app's existing general-purpose
	 * audit facility (used the same way across the app for auditable events);
	 * design.md's Open Questions section resolves the access-log medium to
	 * this for the MVP, deferring a dedicated queryable access-report OR
	 * object to a follow-up.
	 *
	 * @param string $actor Acting user UID.
	 * @param string $clientId The accessed client's UUID.
	 *
	 * @return void
	 */
	private function logAccess(string $actor, string $clientId): void {
		$this->logger->info(
			'Customer 360 accessed',
			[
				'audit' => 'customer-360-access',
				'actor' => $actor,
				'clientId' => $clientId,
				'time' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
			]
		);
	}//end logAccess()
}//end class
