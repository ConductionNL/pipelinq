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
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-prospect-to-client-conversion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ProspectDiscoveryServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

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
	 * @param ProspectDiscoveryServiceInterface $discoveryService The discovery service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N $l10n The localization service.
	 */
	public function __construct(
		IRequest $request,
		private ProspectDiscoveryServiceInterface $discoveryService,
		private CrmPrivilegeCheck $privilege,
		private IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
		// Prospects and leads are customer data. Admins bypass.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$refresh = $this->request->getParam(key: 'refresh', default: 'false') === 'true';
		// The top 10 by default (the dashboard widget); 0 returns every prospect.
		$limit = (string)$this->request->getParam(key: 'limit', default: '10');
		if (ctype_digit($limit) === false) {
			return new JSONResponse(['error' => 'invalid_limit'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->discoveryService->discover(refresh: $refresh, limit: (int)$limit);

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
