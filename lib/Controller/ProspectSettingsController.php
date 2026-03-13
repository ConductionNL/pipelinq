<?php

/**
 * Pipelinq ProspectSettingsController.
 *
 * Controller for managing prospect ICP settings (admin only).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
use OCA\Pipelinq\Service\IcpConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for prospect ICP settings (admin only).
 */
class ProspectSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request   The request.
     * @param IcpConfigService $icpConfig The ICP config service.
     */
    public function __construct(
        IRequest $request,
        private IcpConfigService $icpConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get current ICP configuration.
     *
     * Admin-only: no @NoAdminRequired annotation.
     *
     * @return JSONResponse The ICP settings.
     */
    public function index(): JSONResponse
    {
        return new JSONResponse(data: $this->icpConfig->getSettings());
    }//end index()

    /**
     * Save ICP configuration.
     *
     * Admin-only: no @NoAdminRequired annotation.
     *
     * @return JSONResponse The save result.
     */
    public function update(): JSONResponse
    {
        $data = $this->request->getParams();

        try {
            $icpHash = $this->icpConfig->saveSettings(data: $data);

            return new JSONResponse(
                data: [
                    'status'  => 'saved',
                    'icpHash' => $icpHash,
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end update()
}//end class
