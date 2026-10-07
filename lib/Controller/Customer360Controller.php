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
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
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
	 * The caller may read the client.
	 */
	private const ACCESS_GRANTED = 'granted';

	/**
	 * OpenRegister refused the read.
	 */
	private const ACCESS_DENIED = 'denied';

	/**
	 * The client does not exist.
	 */
	private const ACCESS_MISSING = 'missing';

	/**
	 * The read failed or the app is not configured.
	 */
	private const ACCESS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param Customer360SummaryService $summaryService The customer 360 summary aggregator.
	 * @param IUserSession $userSession Current user.
	 * @param IAppConfig $appConfig App config (register/schema resolution for the read guard).
	 * @param ContainerInterface $container DI container (OpenRegister ObjectService).
	 * @param LoggerInterface $logger Logger — also the doelbinding access-log sink
	 *                                (MVP).
	 */
	public function __construct(
		IRequest $request,
		private Customer360SummaryService $summaryService,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
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
		$access = $this->clientAccess(clientId: $clientId);
		if ($access === self::ACCESS_DENIED) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($access === self::ACCESS_MISSING) {
			return new JSONResponse(['message' => 'Client not found'], Http::STATUS_NOT_FOUND);
		}

		if ($access === self::ACCESS_FAILED) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
	 * Whether the caller may read the client, through OpenRegister.
	 *
	 * The read runs as the caller with RBAC and multitenancy on, so the
	 * client schema's own read rights decide. OpenRegister refuses a read
	 * the caller may not do with NotAuthorizedException (403 here); a client
	 * that does not exist comes back as null or DoesNotExistException (404).
	 * Anything else fails closed as an error (500), never as a grant.
	 *
	 * The call uses named arguments. The positional form
	 * `find($clientId, $register, $schema)` put the register into
	 * `?array $_extend`, raised a TypeError that the old catch swallowed, and
	 * so denied every caller, which is why every Customer 360 widget showed a
	 * 404 (pipelinq#805).
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return string One of the ACCESS_* constants.
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	private function clientAccess(string $clientId): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return self::ACCESS_FAILED;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $clientId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true,
			);
		} catch (NotAuthorizedException $e) {
			return self::ACCESS_DENIED;
		} catch (Throwable $e) {
			// OCP's DoesNotExistException, matched by name to keep this
			// controller's coupling under the phpmd threshold.
			if (str_ends_with(get_class($e), '\\DoesNotExistException') === true) {
				return self::ACCESS_MISSING;
			}

			$this->logger->warning(
				'Customer360Controller: client read-guard failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return self::ACCESS_FAILED;
		}

		if ($object === null) {
			return self::ACCESS_MISSING;
		}

		return self::ACCESS_GRANTED;
	}//end clientAccess()

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
