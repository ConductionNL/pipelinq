<?php

/**
 * Pipelinq ClientHistorySummaryController.
 *
 * Serves the written history summary of one client, generated on request.
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
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /api/clients/{id}/history-summary.
 *
 * The caller must be able to read the client itself, through OpenRegister with
 * RBAC and multitenancy on: a refused read is a 403, a missing client a 404,
 * and a failed read a 500, never a grant. With `check=1` only whether
 * summarising is available is answered, and nothing is read or generated.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.2
 */
class ClientHistorySummaryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request        The request.
	 * @param ClientHistorySummaryService $summaryService Writes the summary.
	 * @param ObjectServiceInterface      $objectService  OpenRegister, for the read guard.
	 * @param IUserSession                $userSession    The current user.
	 * @param IAppConfig                  $appConfig      Register and client schema.
	 * @param LoggerInterface             $logger         PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ClientHistorySummaryService $summaryService,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The client's history summary, or whether one can be written.
	 *
	 * @param string $id The client's id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$status = $this->clientAccess(clientId: $id);
		if ($status !== Http::STATUS_OK) {
			$messages = [
				Http::STATUS_FORBIDDEN => 'Forbidden',
				Http::STATUS_NOT_FOUND => 'Client not found',
			];
			return new JSONResponse(['message' => ($messages[$status] ?? 'Operation failed')], $status);
		}

		if ((string) $this->request->getParam('check', '') === '1') {
			return new JSONResponse(['available' => $this->summaryService->available()]);
		}

		try {
			$summary = $this->summaryService->summarise(clientId: $id, viewer: $user->getUID());
		} catch (Throwable $e) {
			$this->logger->error('ClientHistorySummaryController: summary failed', ['clientId' => $id, 'exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($summary);
	}//end show()

	/**
	 * Whether the caller may read the client: 200, 403, 404 or 500.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return int The HTTP status the access maps to.
	 */
	private function clientAccess(string $clientId): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '' || $clientId === '') {
			return Http::STATUS_INTERNAL_SERVER_ERROR;
		}

		return $this->accessFor(clientId: $clientId, register: $register, schema: $schema);
	}//end clientAccess()

	/**
	 * Read the client with RBAC on and map the outcome to an HTTP status.
	 *
	 * @param string $clientId The client's id.
	 * @param string $register The register id.
	 * @param string $schema The client schema id.
	 *
	 * @return int The HTTP status the access maps to.
	 */
	private function accessFor(string $clientId, string $register, string $schema): int {
		try {
			$object = $this->objectService->find(id: $clientId, register: $register, schema: $schema, _rbac: true, _multitenancy: true);
		} catch (NotAuthorizedException) {
			return Http::STATUS_FORBIDDEN;
		} catch (Throwable $e) {
			return $this->failureStatus(e: $e, clientId: $clientId);
		}

		if ($object === null) {
			return Http::STATUS_NOT_FOUND;
		}

		return Http::STATUS_OK;
	}//end accessFor()

	/**
	 * The status for a failed client read: 404 when it does not exist, else 500 (logged).
	 *
	 * @param Throwable $e The failure.
	 * @param string $clientId The client's id, for the log.
	 *
	 * @return int
	 */
	private function failureStatus(Throwable $e, string $clientId): int {
		if (str_ends_with(get_class($e), '\\DoesNotExistException') === true) {
			return Http::STATUS_NOT_FOUND;
		}

		$this->logger->warning('ClientHistorySummaryController: client read guard failed', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
		return Http::STATUS_INTERNAL_SERVER_ERROR;
	}//end failureStatus()
}//end class
