<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\IMcpToolProvider.
 *
 * Mirrors the interface signature from openregister PR #1466
 * (change: ai-chat-companion-orchestrator). Used only in environments where
 * the openregister runtime is not installed (e.g. bare CI containers).
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

if (interface_exists(IMcpToolProvider::class) === false) {
    /**
     * Stub interface for IMcpToolProvider — used only in standalone unit tests.
     *
     * Deferred until openregister PR #1466 (ai-chat-companion-orchestrator) ships
     * the real interface. Pipelinq implements this stub in production; the stub is
     * replaced by the real interface when the openregister app is installed.
     */
    interface IMcpToolProvider
    {

        /**
         * Returns the app ID that namespaces every tool id this provider exposes.
         *
         * @return string The app slug (e.g. "pipelinq")
         */
        public function getAppId(): string;

        /**
         * Returns the full tool catalogue for this provider.
         *
         * Each descriptor is an associative array with keys:
         * `id`, `name`, `description`, `inputSchema`.
         *
         * @return array<int, array<string, mixed>>
         */
        public function getTools(): array;

        /**
         * Invoke a single tool by id with the given arguments.
         *
         * Returns a success payload or a structured error envelope.
         * MUST NOT throw — all failure paths return an array.
         *
         * @param string               $toolId    The tool id (e.g. "pipelinq.listRequests")
         * @param array<string, mixed> $arguments Tool arguments from the LLM call
         *
         * @return array<string, mixed>
         */
        public function invokeTool(string $toolId, array $arguments): array;

    }//end interface
}//end if
