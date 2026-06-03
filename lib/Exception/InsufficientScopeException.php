<?php

/**
 * Pipelinq InsufficientScopeException.
 *
 * Raised by the client-side pre-flight guard when the configured ZgwClient does
 * not hold the autorisatie (AC) scope required for an operation on a given
 * zaaktype / informatieobjecttype / besluittype. No HTTP call to ZRC/DRC/BRC is
 * made when this is raised (REQ-ZGW-006).
 *
 * @category Exception
 * @package  OCA\Pipelinq\Exception
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when the client lacks the AC scope for an operation.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class InsufficientScopeException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string $scope     The missing scope, e.g. "zaken.aanmaken".
     * @param string $targetUrl The zaaktype/informatieobjecttype/besluittype URL the scope was needed for.
     */
    public function __construct(
        private string $scope,
        private string $targetUrl,
    ) {
        parent::__construct(
            message: sprintf(
                'Ontbrekende ZGW-autorisatie: scope "%s" is niet verleend voor %s. '
                .'Neem contact op met de gemeente-beheerder om deze permissie aan te vragen.',
                $scope,
                $targetUrl
            )
        );
    }//end __construct()

    /**
     * Get the missing scope name.
     *
     * @return string The scope, e.g. "zaken.aanmaken".
     */
    public function getScope(): string
    {
        return $this->scope;
    }//end getScope()

    /**
     * Get the target resource-type URL the scope was needed for.
     *
     * @return string The URL.
     */
    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }//end getTargetUrl()
}//end class
