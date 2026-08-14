<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\Attribute\McpTool.
 *
 * Mirrors the attribute shipped in openregister PR #363
 * (change: or-mcp-tool-attribute, ADR-063 chain 3/3), extended with the
 * optional `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` params
 * added in openregister PR #377 (or-mcp-attribute-hints, closes #374). Used
 * only in environments where the openregister runtime is not installed
 * (e.g. bare CI containers) — pipelinq's own composer.json has no real
 * openregister dependency.
 *
 * This file is loaded via Composer's autoload-dev PSR-4 mapping when the real
 * attribute class is absent. It is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Mcp\Attribute
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Attribute;

use Attribute;

if (class_exists(McpTool::class) === false) {
	/**
	 * Stub attribute for McpTool — used only in standalone unit tests.
	 *
	 * Deferred until openregister PR #363 (or-mcp-tool-attribute) ships the
	 * real attribute. Pipelinq attributes its service methods with this stub
	 * in tests; the stub is replaced by the real attribute when the
	 * openregister app is installed.
	 */
	#[Attribute(Attribute::TARGET_METHOD)]
	final class McpTool {
		/**
		 * Constructor.
		 *
		 * @param string|null $name Local tool name; defaults to the method name when null.
		 * @param string|null $description LLM-facing description; defaults to the docblock summary when null.
		 * @param bool|null $readOnlyHint Optional MCP 2025-11-25 annotation hint.
		 * @param bool|null $destructiveHint Optional MCP 2025-11-25 annotation hint.
		 * @param bool|null $idempotentHint Optional MCP 2025-11-25 annotation hint.
		 * @param string|null $scope Optional advisory scope (one of openregister's
		 *                           McpAnnotationValidator::SCOPES).
		 */
		public function __construct(
			public readonly ?string $name = null,
			public readonly ?string $description = null,
			public readonly ?bool $readOnlyHint = null,
			public readonly ?bool $destructiveHint = null,
			public readonly ?bool $idempotentHint = null,
			public readonly ?string $scope = null,
		) {
		}//end __construct()
	}//end class
}//end if
