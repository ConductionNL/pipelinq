<?php

/**
 * Pipelinq Metrics Controller
 *
 * Thin adopter of the OpenRegister AppHost engine's GenericMetricsController
 * (ADR-040). The Prometheus metric set is now declared in `src/manifest.json`
 * (`observability.metrics`) and rendered by the engine through OpenRegister's
 * portable object/table aggregation — no PHP-side JSON aggregation. This
 * subclass exists only because Nextcloud resolves the `metrics#index` route to
 * this app-namespaced class by name.
 *
 * Auth posture is the engine's: there is intentionally NO `#[NoAdminRequired]`
 * / `#[PublicPage]`, so NC requires an admin session (ADR-006). This is a
 * deliberate change from the previous public endpoint — Prometheus scrapers
 * now need admin credentials. Only `#[NoCSRFRequired]` is set (machine clients
 * carry no CSRF token).
 *
 * The parent class is only autoloaded when NC instantiates this controller on a
 * request to `/api/metrics`, so a disabled / absent OpenRegister does not fatal
 * Nextcloud bootstrap.
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

use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Admin-only declarative Prometheus metrics endpoint backed by the AppHost engine.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */
class MetricsController extends GenericMetricsController
{
    /**
     * Constructor.
     *
     * Pins the engine's `$appName` to pipelinq so the engine reads pipelinq's
     * manifest metric descriptors.
     *
     * @param IRequest       $request        The HTTP request.
     * @param ManifestLoader $manifestLoader Loads pipelinq's observability config.
     * @param MetricsEngine  $engine         Renders the declarative metrics.
     */
    public function __construct(
        IRequest $request,
        ManifestLoader $manifestLoader,
        MetricsEngine $engine
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request,
            manifestLoader: $manifestLoader,
            engine: $engine
        );
    }//end __construct()

    /**
     * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
     *
     * Admin-only by the deliberate absence of `#[NoAdminRequired]`.
     *
     * @return TextPlainResponse Prometheus text exposition 0.0.4.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
     */
    #[NoCSRFRequired]
    public function index(): TextPlainResponse
    {
        return parent::index();
    }//end index()
}//end class
