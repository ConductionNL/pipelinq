<?php

/**
 * Pipelinq PortalPageController.
 *
 * Serves the public customer-portal SPA shell. The page is `@PublicPage` (no
 * Nextcloud login) because portal customers are not Nextcloud users; the SPA
 * then authenticates the customer client-side with a bearer token against the
 * `/portal/api` endpoints. Serving a bare public template (no app data) keeps
 * the portal auth domain isolated from the Nextcloud session (ADR-005).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/customer-portal/specs.md#REQ-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Serves the public portal SPA shell.
 */
class PortalPageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest $request The request.
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the public portal SPA shell.
     *
     * @return TemplateResponse The portal page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(): TemplateResponse
    {
        $response = new TemplateResponse(
            Application::APP_ID,
            'portal',
            [],
            TemplateResponse::RENDER_AS_PUBLIC
        );

        // Allow the portal to be embedded as a widget on tenant-approved sites
        // (origin enforcement is done server-side per request, not via frame
        // ancestors, since the allow-list is tenant-scoped).
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFrameAncestorDomain('*');
        $response->setContentSecurityPolicy($csp);

        return $response;
    }//end index()

    /**
     * Render the portal SPA shell for any non-API sub-path (hash routing means
     * every deep link still resolves to the same shell).
     *
     * @param string $path The matched sub-path (unused; the hash-routed SPA
     *                     resolves the deep link client-side).
     *
     * @return TemplateResponse The portal page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $path is a route-binding
     *  placeholder; the SPA shell is identical for every sub-path.
     */
    public function subpath(string $path=''): TemplateResponse
    {
        return $this->index();
    }//end subpath()
}//end class
