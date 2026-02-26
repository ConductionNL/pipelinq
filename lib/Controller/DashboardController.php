<?php

/**
 * Pipelinq DashboardController.
 *
 * Controller for serving the Pipelinq SPA dashboard page.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the Pipelinq dashboard.
 */
class DashboardController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest $request The request.
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * Render the main dashboard page.
     *
     * @return TemplateResponse The template response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()
}//end class
