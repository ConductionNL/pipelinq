<?php

/**
 * Bootstrap file for PHPUnit unit tests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP/NCU classes from nextcloud/ocp package.
// nextcloud/ocp has no autoload section in its composer.json, so we register it manually.
spl_autoload_register(function (string $class): void {
    $prefixMap = [
        'OCP\\' => __DIR__ . '/../vendor/nextcloud/ocp/OCP/',
        'NCU\\' => __DIR__ . '/../vendor/nextcloud/ocp/NCU/',
    ];

    foreach ($prefixMap as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = str_replace(search: '\\', replace: '/', subject: substr($class, strlen($prefix)));
        $file     = $dir . $relative . '.php';
        if (file_exists($file) === true) {
            require_once $file;
        }

        break;
    }//end foreach

});
