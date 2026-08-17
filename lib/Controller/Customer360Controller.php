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
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
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
	 * @param IAppConfig $appConfig App config (register/schema resolution for the read guard).
	 * @param ObjectOwnerAccessPolicy $accessPolicy Owner-based access policy.
	 * @param ContainerInterface $container DI container (OpenRegister ObjectService).
	 * @param LoggerInterface $logger Logger — also the doelbinding access-log sink
	 *                                (MVP).
	 */
	public function __construct(
		IRequest $request,
		private Customer360SummaryService $summaryService,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/customer-360/summary?clientId=... — the consolidated customer 360 summary.
	 *
	 * Per-object read guard (no IDOR): the caller must be able to READ the
	 * client object itself — resolved through OpenRegister's ObjectService,
	 * which applies the caller's RBAC. A client the caller cannot read (hidden,
	 * wrong tenant, deleted) 404s exactly like any other RBAC-denied read,
	 * never leaking whether the id exists. On success, every access is logged
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
	 */
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// ADDED IN FRONT OF canReadClient(), NOT INSTEAD OF IT — and
		// canReadClient() is deliberately left alone (see #805).
		//
		// That helper calls ObjectService::find($clientId, $register, $schema)
		// POSITIONALLY and catches Throwable to return false. If those
		// positions do not match the current signature the call raises, the
		// catch swallows it, and the endpoint denies EVERYONE while reading
		// like a working guard. "Fixing" the call is the one change that must
		// not be made casually here: behind it sits `return $object !== null`,
		// an EXISTENCE test, so a repair would turn a dead endpoint into a
		// live IDOR over every client record.
		//
		// This check is strictly additive: it can only deny more, never less,
		// so it is safe to land while #805 is still open.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$clientId = (string)$this->request->getParam('clientId', '');
		if ($clientId === '') {
			return new JSONResponse(['message' => 'Missing required parameter: clientId'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->canReadClient(clientId: $clientId) === false) {
			return new JSONResponse(['message' => 'Client not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$summary = $this->summaryService->getSummary(clientId: $clientId);
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
	 * Per-object read guard: true only when the client object resolves through
	 * OpenRegister's RBAC-scoped ObjectService::find(). Fails closed — an OR
	 * outage or missing config denies the read rather than granting it, since
	 * this is the caller's only defense against reading another client's data.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return bool True when the caller may read this client.
	 */
	private function canReadClient(string $clientId): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find($clientId, $register, $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360Controller: client read-guard failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return $object !== null;
	}//end canReadClient()

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
