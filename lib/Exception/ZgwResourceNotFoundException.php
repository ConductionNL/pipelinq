<?php

/**
 * Pipelinq ZgwResourceNotFoundException.
 *
 * Raised when a ZGW resource (zaak, besluit, status, informatieobject, ...)
 * cannot be retrieved (HTTP 404) at the given URL.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when a ZGW resource is not found at its URL.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class ZgwResourceNotFoundException extends ZgwBridgeException
{
    /**
     * Constructor.
     *
     * @param string $url The URL of the resource that returned 404.
     */
    public function __construct(private string $url)
    {
        parent::__construct(message: sprintf('ZGW-resource niet gevonden: %s', $url));
    }//end __construct()

    /**
     * Get the resource URL.
     *
     * @return string The URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }//end getUrl()
}//end class
