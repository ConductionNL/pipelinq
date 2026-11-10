<?php

/**
 * Pipelinq ProspectSettingsController.
 *
 * Controller for managing prospect ICP settings (admin only).
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
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IcpConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Controller for prospect ICP settings (admin only).
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectSettingsController extends Controller {
	use OpenRegisterServiceLookup;

	/**
	 * The OpenRegister object service.
	 *
	 * @var \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister object service.
	 */
	private ?\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container The container.
	 * @param IAppManager $appManager The app manager.
	 * @param IcpConfigService $icpConfig The ICP config service.
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
	 * Get current ICP configuration.
	 *
	 * Admin-only endpoint (requires admin settings permission).
	 *
	 * @return JSONResponse The ICP settings.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->icpConfig->getSettings());
	}//end index()

	/**
	 * Save ICP configuration.
	 *
	 * Admin-only endpoint (requires admin settings permission).
	 *
	 * @return JSONResponse The save result.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();

		try {
			$icpHash = $this->icpConfig->saveSettings(data: $data);

			return new JSONResponse(
				data: [
					'status' => 'saved',
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
