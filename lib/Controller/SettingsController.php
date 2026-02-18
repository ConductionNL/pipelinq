<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        return new JSONResponse([
            'success' => true,
            'config' => $this->settingsService->getSettings(),
        ]);
    }

    public function create(): JSONResponse
    {
        $data = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse([
            'success' => true,
            'config' => $config,
        ]);
    }
}
