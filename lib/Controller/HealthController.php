<?php

/**
 * Pipelinq Health Controller
 *
 * Thin adopter of the OpenRegister AppHost engine's GenericHealthController
 * (ADR-040). The health checks + the `{status, app, version, checks}` shape
 * are now declared in `src/manifest.json` (`observability.health`) and executed
 * by the engine; this subclass exists only because Nextcloud resolves the
 * `health#index` route to this app-namespaced class by name. The auth posture
 * (`#[PublicPage]`) is re-declared here so it is visible to NC's middleware and
 * to the route-auth gate, then delegates to the engine.
 *
 * The parent class is only autoloaded when NC instantiates this controller on a
 * request to `/api/health` — never at `Application::register()` — so a disabled
 * or absent OpenRegister does not fatal Nextcloud bootstrap; the first health
 * request degrades to a 5xx instead.
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
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public, declarative health endpoint backed by the AppHost engine.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */
class HealthController extends GenericHealthController
{
    /**
     * Constructor.
     *
     * Pins the engine's `$appName` to pipelinq so the engine reads pipelinq's
     * manifest + resolves pipelinq's version.
     *
     * @param IRequest            $request        The HTTP request.
     * @param ManifestLoader      $manifestLoader Loads pipelinq's observability config.
     * @param HealthCheckExecutor $executor       Runs the declarative checks.
     */
    public function __construct(
        IRequest $request,
        ManifestLoader $manifestLoader,
        HealthCheckExecutor $executor
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request,
            manifestLoader: $manifestLoader,
            executor: $executor
        );
    }//end __construct()

    /**
     * GET /api/health — declarative health check (ADR-006), public probe.
     *
     * @return JSONResponse `{status, app, version, checks}` with HTTP code per policy.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return parent::index();
    }//end index()
}//end class
