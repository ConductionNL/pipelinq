<?php

/**
 * Pipelinq AdminSettings.
 *
 * Admin settings form for the Pipelinq application.
 *
 * @category Settings
 * @package  OCA\Pipelinq\Settings
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

namespace OCA\Pipelinq\Settings;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Admin settings for Pipelinq.
 */
class AdminSettings implements ISettings
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param IAppManager     $appManager      The app manager.
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Get the admin settings form.
     *
     * @return TemplateResponse The settings form template.
     */
    public function getForm(): TemplateResponse
    {
        $config  = $this->settingsService->getSettings();
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

        return new TemplateResponse(
                Application::APP_ID,
                'settings/admin',
                [
                    'config'  => json_encode($config),
                    'version' => $version,
                ]
                );
    }//end getForm()

    /**
     * Get the settings section ID.
     *
     * @return string The section ID.
     */
    public function getSection(): string
    {
        return 'pipelinq';
    }//end getSection()

    /**
     * Get the settings priority.
     *
     * @return int The priority.
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()
}//end class
