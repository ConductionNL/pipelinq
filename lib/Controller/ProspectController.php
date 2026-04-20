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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for prospect discovery.
 */
class ProspectController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                 $request          The request.
     * @param ProspectDiscoveryService $discoveryService The discovery service.
     * @param IL10N                    $l10n             The localization service.
     */
    public function __construct(
        IRequest $request,
        private ProspectDiscoveryService $discoveryService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get prospect results based on configured ICP.
     *
     * @return JSONResponse The prospect results.
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
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
                    'error'   => 'api_unavailable',
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
     */
    public function createLead(): JSONResponse
    {
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
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500
            );
        }//end try
    }//end createLead()
}//end class
