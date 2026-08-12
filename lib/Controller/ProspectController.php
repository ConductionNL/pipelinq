<?php

/**
 * Pipelinq ProspectController.
 *
 * Controller for prospect discovery API endpoints.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-to-lead-conversion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for prospect discovery.
 */
class ProspectController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProspectDiscoveryService $discoveryService The discovery service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ProspectDiscoveryService $discoveryService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get prospect results based on configured ICP.
	 *
	 * @return JSONResponse The prospect results.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-1
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$refresh = $this->request->getParam(key: 'refresh', default: 'false') === 'true';

		try {
			$result = $this->discoveryService->discover(refresh: $refresh);

			if (isset($result['error']) === true) {
				return new JSONResponse(data: $result, statusCode: 400);
			}

			return new JSONResponse(data: $result);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'api_unavailable',
					'message' => $this->l10n->t('Prospect discovery service is currently unavailable.'),
				],
				statusCode: 503
			);
		}//end try
	}//end index()

	/**
	 * Create a Client + Lead from a prospect result.
	 *
	 * @return JSONResponse The created client and lead.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-to-lead-conversion
	 */
	public function createLead(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->request->getParams();

		if (isset($data['tradeName']) === false || $data['tradeName'] === '') {
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Trade name is required')],
				statusCode: 400
			);
		}

		try {
			$result = $this->discoveryService->createLeadFromProspect(prospectData: $data);

			if (isset($result['error']) === true) {
				return new JSONResponse(data: $result, statusCode: 400);
			}

			return new JSONResponse(data: $result, statusCode: 201);
		} catch (\Exception $e) {
			$this->logger->error('ProspectController::createLead failed', ['exception' => $e]);
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Operation failed')],
				statusCode: 500
			);
		}//end try
	}//end createLead()
}//end class
