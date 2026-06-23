<?php

/**
 * Test stub for OCA\OpenRegister\Service\Aggregation\AggregationRunner.
 *
 * Provides a minimal class declaration so unit tests running in a bare
 * environment (no Nextcloud server, no openregister installed) can type-hint
 * against the class. Tests supply a hand-written fake (extending or duck-typing
 * this surface) that computes the metric over an in-memory store so the pushdown
 * result can be asserted equal to the prior PHP reduce.
 *
 * Loaded via Composer's autoload-dev PSR-4 mapping
 * ("OCA\\OpenRegister\\" => "tests/Stubs/") and is a no-op when the real
 * openregister app is present (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

if (class_exists(AggregationRunner::class) === false) {
    /**
     * Stub AggregationRunner — used only in standalone unit tests.
     *
     * Replaced by the real implementation when openregister is installed.
     */
    class AggregationRunner
    {
        /**
         * Run an ad-hoc aggregation by register/schema ref.
         *
         * @param string           $registerRef The register ref.
         * @param string           $schemaRef   The schema ref.
         * @param AggregationQuery $query        The query value object.
         *
         * @return array<string, mixed> The result envelope.
         */
        public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array
        {
            return ['value' => null, 'backend' => 'stub', 'cached' => false];
        }//end runAdhocByRef()
    }//end class
}//end if
