<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Observability\ManifestLoader.
 *
 * Minimal declaration so static analysis + bare unit tests can type-hint the
 * AppHost observability collaborator without the openregister app installed.
 * Loaded via Composer autoload-dev ("OCA\\OpenRegister\\" => "tests/Stubs/");
 * replaced by the real class when openregister is present (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

if (class_exists(ManifestLoader::class) === false) {
    /**
     * Stub for the AppHost observability manifest loader.
     */
    class ManifestLoader
    {
    }//end class
}//end if
