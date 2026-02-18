<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Settings;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'settings/admin');
    }

    public function getSection(): string
    {
        return 'pipelinq';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
