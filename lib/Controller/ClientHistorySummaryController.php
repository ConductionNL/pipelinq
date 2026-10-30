<?php

/**
 * Pipelinq ClientHistorySummaryController.
 *
 * GET /api/clients/{id}/history-summary: a written summary of a client's
 * recent history through hermiq, generated on request and never stored.
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCA\Pipelinq\Service\ClientReadAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client history summary endpoint.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */
class ClientHistorySummaryController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Current user.
	 * @param ClientReadAccess $readAccess The per-object read guard on the client.
	 * @param ClientHistorySummaryService $historySummary Writes the summary through hermiq.
	 * @param LoggerInterface $logger Logger, also the access-log sink.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ClientReadAccess $readAccess,
		private ClientHistorySummaryService $historySummary,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether a summary can be written, and with `generate=true` the summary.
	 *
	 * Only a caller who may read the client gets anything, and the summary
	 * reads the client's records as that caller. Without hermiq the answer
	 * is `available: false`, so the card stays hidden.
	 *
	 * @param string $id The client UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		[$access] = $this->readAccess->check(clientId: $id);
		$refusal = $this->readAccess->refusal(access: $access);
		if ($refusal !== null) {
			return new JSONResponse(['message' => $refusal['message']], $refusal['status']);
		}

		if ($this->historySummary->available() === false) {
			return new JSONResponse(['available' => false]);
		}

		if (filter_var($this->request->getParam('generate', false), FILTER_VALIDATE_BOOLEAN) === false) {
			return new JSONResponse(['available' => true]);
		}

		try {
			$result = $this->historySummary->summarise(clientId: $id, viewerId: $user->getUID());
		} catch (Throwable $e) {
			return new JSONResponse(['available' => true, 'message' => 'No summary could be written'], Http::STATUS_BAD_GATEWAY);
		}

		$this->logger->info(
			'Client history summarised',
			['audit' => 'client-history-summary', 'actor' => $user->getUID(), 'clientId' => $id, 'recordsRead' => $result['recordsRead']]
		);

		return new JSONResponse(array_merge(['available' => true], $result));
	}//end show()
}//end class
