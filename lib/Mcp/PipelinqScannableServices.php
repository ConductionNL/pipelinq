<?php
/**
 * Pipelinq scannable-services opt-in (ADR-063 chain 3/3).
 *
 * Declares which of Pipelinq's own service classes OpenRegister's
 * AttributeToolScanner may reflect for `#[McpTool]`-attributed methods.
 * Registered under the `OCA\OpenRegister\Mcp\IMcpScannableServices::pipelinq`
 * DI alias in Application.php (mirrors the retired per-app
 * `IMcpToolProvider::pipelinq` convention). This is Pipelinq's sole MCP
 * tool-provider seam after `plq-mcp-provider-surgery` — Pipelinq no longer
 * ships a hand-written `IMcpToolProvider` implementation.
 *
 * @category Mcp
 * @package  OCA\Pipelinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Mcp;

use OCA\OpenRegister\Mcp\IMcpScannableServices;
use OCA\Pipelinq\Service\LeadService;
use OCA\Pipelinq\Service\TicketService;

/**
 * PipelinqScannableServices
 *
 * Lists the service classes carrying `#[McpTool]`-attributed methods:
 * `LeadService` (createLead, pipelineForecast) and `TicketService`
 * (logContactmoment).
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md
 *   (Requirement: Hand-written service tools take precedence over derived tools)
 */
class PipelinqScannableServices implements IMcpScannableServices
{
    /**
     * Returns Pipelinq's `#[McpTool]`-attributed service classes.
     *
     * @return list<class-string>
     *
     * @spec openspec/specs/crm-mcp-tool-surface/spec.md
     *   (Requirement: Hand-written service tools take precedence over derived tools)
     */
    public function getScannableServiceClasses(): array
    {
        return [
            LeadService::class,
            TicketService::class,
        ];

    }//end getScannableServiceClasses()
}//end class
