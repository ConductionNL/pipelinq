<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Controller\GenericHealthController.
 *
 * Minimal declaration so pipelinq's thin HealthController subclass type-checks
 * + autoloads in a bare environment (no openregister app). Loaded via Composer
 * autoload-dev ("OCA\\OpenRegister\\" => "tests/Stubs/"); replaced by the real
 * engine controller when openregister is installed (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

if (class_exists(GenericHealthController::class) === false) {
    /**
     * Stub for the engine's generic health controller.
     */
    class GenericHealthController extends Controller
    {
        /**
         * Constructor mirroring the real engine controller surface.
         *
         * @param string              $appName        Calling app id.
         * @param IRequest            $request        HTTP request.
         * @param ManifestLoader      $manifestLoader Observability config loader.
         * @param HealthCheckExecutor $executor       Health check executor.
         */
        public function __construct(
            string $appName,
            IRequest $request,
            ManifestLoader $manifestLoader,
            HealthCheckExecutor $executor
        ) {
            parent::__construct(appName: $appName, request: $request);
        }//end __construct()

        /**
         * Render the declarative health check.
         *
         * @return JSONResponse
         */
        public function index(): JSONResponse
        {
            return new JSONResponse([]);
        }//end index()
    }//end class
}//end if
