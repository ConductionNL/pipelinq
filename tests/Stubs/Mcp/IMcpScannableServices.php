<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\IMcpScannableServices.
 *
 * Mirrors the interface shipped in openregister PR #363
 * (change: or-mcp-tool-attribute, ADR-063 chain 3/3). Used only in
 * environments where the openregister runtime is not installed (e.g. bare
 * CI containers) — pipelinq's own composer.json has no real openregister
 * dependency.
 *
 * This file is loaded via Composer's autoload-dev PSR-4 mapping when the real
 * interface is absent. It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

if (interface_exists(IMcpScannableServices::class) === false) {
    /**
     * Stub interface for IMcpScannableServices — used only in standalone unit tests.
     *
     * Deferred until openregister PR #363 (or-mcp-tool-attribute) ships the
     * real interface. Pipelinq implements this stub in production; the stub
     * is replaced by the real interface when the openregister app is
     * installed.
     */
    interface IMcpScannableServices
    {

        /**
         * The app's own service classes eligible for `#[McpTool]` reflection.
         *
         * @return list<class-string> Fully-qualified service class names owned by this app.
         */
        public function getScannableServiceClasses(): array;

    }//end interface
}//end if
