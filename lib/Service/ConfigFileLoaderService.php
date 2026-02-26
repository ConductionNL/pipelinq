<?php

/**
 * Pipelinq ConfigFileLoaderService.
 *
 * Service for loading and parsing the Pipelinq register configuration JSON file.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use RuntimeException;

/**
 * Service for loading and parsing configuration JSON files.
 */
class ConfigFileLoaderService
{
    /**
     * Path to the register config file.
     *
     * @var string
     */
    private const REGISTER_FILE = '/lib/Settings/pipelinq_register.json';

    /**
     * Constructor.
     *
     * @param IAppManager $appManager The app manager.
     */
    public function __construct(
        private IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Load and parse the configuration JSON file.
     *
     * @return array The parsed configuration data.
     *
     * @throws RuntimeException If the file cannot be read or parsed.
     */
    public function loadConfigurationFile(): array
    {
        $appPath          = $this->appManager->getAppPath(Application::APP_ID);
        $absoluteFilePath = $appPath.self::REGISTER_FILE;

        if (file_exists($absoluteFilePath) === false) {
            throw new RuntimeException("Configuration file not found: {$absoluteFilePath}");
        }

        $jsonContent = file_get_contents($absoluteFilePath);
        if ($jsonContent === false) {
            throw new RuntimeException("Failed to read configuration file: {$absoluteFilePath}");
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in configuration file: '.json_last_error_msg());
        }

        return $data;
    }//end loadConfigurationFile()

    /**
     * Ensure the x-openregister sourceType is set on configuration data.
     *
     * @param array $data The configuration data.
     *
     * @return array The data with sourceType ensured.
     */
    public function ensureSourceType(array $data): array
    {
        if (isset($data['x-openregister']) === false) {
            $data['x-openregister'] = [];
        }

        if (isset($data['x-openregister']['sourceType']) === false) {
            $data['x-openregister']['sourceType'] = 'local';
        }

        return $data;
    }//end ensureSourceType()
}//end class
