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
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for prospect discovery.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProspectDiscoveryService $discoveryService The discovery service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private ProspectDiscoveryService $discoveryService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private ObjectOwnerAccessPolicy $policy,
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

		// Prospects and leads are customer data. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
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
}//end class
