<?php

/**
 * Pipelinq IcpConfigReader.
 *
 * Low-level reader for ICP configuration values from IAppConfig.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Low-level reader/writer for ICP config values.
 */
class IcpConfigReader
{
    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config service.
     */
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get a string value from app config.
     *
     * @param string $key     The config key.
     * @param string $default The default value.
     *
     * @return string The config value.
     */
    public function getString(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: $key,
            default: $default
        );
    }//end getString()

    /**
     * Set a string value in app config.
     *
     * @param string $key   The config key.
     * @param string $value The value to store.
     *
     * @return void
     */
    public function setString(string $key, string $value): void
    {
        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: $key,
            value: $value
        );
    }//end setString()

    /**
     * Get a JSON array from app config.
     *
     * @param string $key The config key.
     *
     * @return array The decoded array.
     */
    public function getJsonArray(string $key): array
    {
        $value   = $this->getString(key: $key, default: '[]');
        $decoded = json_decode(json: $value, associative: true);

        if (is_array(value: $decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getJsonArray()

    /**
     * Set a JSON array in app config.
     *
     * @param string $key   The config key.
     * @param mixed  $value The array to encode and store.
     *
     * @return void
     */
    public function setJsonArray(string $key, mixed $value): void
    {
        $arrayValue = [];
        if (is_array(value: $value) === true) {
            $arrayValue = $value;
        }

        $this->setString(key: $key, value: json_encode(value: $arrayValue));
    }//end setJsonArray()

    /**
     * Get a boolean value from app config.
     *
     * @param string $key     The config key.
     * @param string $default The default string ('true' or 'false').
     *
     * @return bool The boolean value.
     */
    public function isBoolTrue(string $key, string $default='true'): bool
    {
        return $this->getString(key: $key, default: $default) === 'true';
    }//end getBool()

    /**
     * Set a boolean value in app config.
     *
     * @param string $key   The config key.
     * @param mixed  $value The boolean value.
     *
     * @return void
     */
    public function setBool(string $key, mixed $value): void
    {
        $stringValue = 'false';
        if ($value === true) {
            $stringValue = 'true';
        }

        $this->setString(key: $key, value: $stringValue);
    }//end setBool()

    /**
     * Get an integer value from app config.
     *
     * @param string $key The config key.
     *
     * @return int The integer value.
     */
    public function getInt(string $key): int
    {
        return (int) $this->getString(key: $key, default: '0');
    }//end getInt()

    /**
     * Set an integer value in app config.
     *
     * @param string $key   The config key.
     * @param mixed  $value The integer value.
     *
     * @return void
     */
    public function setInt(string $key, mixed $value): void
    {
        $this->setString(key: $key, value: (string) (int) $value);
    }//end setInt()
}//end class
