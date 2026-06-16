<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Observability\MetricsEngine.
 *
 * Minimal declaration so static analysis + bare unit tests can type-hint the
 * AppHost metrics engine without the openregister app installed. Loaded via
 * Composer autoload-dev; replaced by the real class when openregister is
 * present (class_exists guard).
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

if (class_exists(MetricsEngine::class) === false) {
    /**
     * Stub for the AppHost metrics engine.
     */
    class MetricsEngine
    {
    }//end class
}//end if
