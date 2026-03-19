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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Controller for prospect ICP settings (admin only).
 */
class ProspectSettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * Constructor.
     *
     * @param IRequest           $request    The request.
     * @param ContainerInterface $container  The container.
     * @param IAppManager        $appManager The app manager.
     * @param IcpConfigService   $icpConfig  The ICP config service.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private IcpConfigService $icpConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        throw new RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()

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
