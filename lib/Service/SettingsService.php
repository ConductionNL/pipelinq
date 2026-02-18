<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class SettingsService
{
    private const CONFIG_KEYS = [
        'register',
        'client_schema',
        'request_schema',
        'contact_schema',
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function getSettings(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }
        return $config;
    }

    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key])) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        $this->logger->info('Pipelinq settings updated', ['keys' => array_keys($data)]);

        return $this->getSettings();
    }

    public function getConfigValue(string $key, string $default = ''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }

    public function setConfigValue(string $key, string $value): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $key, $value);
    }
}
