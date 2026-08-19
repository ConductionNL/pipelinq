<?php

/**
 * Pipelinq ZgwResourceNotFoundException.
 *
 * Raised when a GET against a ZGW component (ZRC/DRC/BRC) returns HTTP 404
 * for a resource that we expected to exist (e.g. a zaak whose URL is
 * stored on a `ZgwResourceMapping`). Distinct from `ZaaktypeNotInCatalogusException`
 * (which is the ZTC lookup variant) so callers can choose between
 * notifying the operator and quietly archiving the mapping.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * ZGW resource (zaak / besluit / informatieobject / rol) not found.
 */
class ZgwResourceNotFoundException extends ZgwException
{
    /**
     * Constructor.
     *
     * @param string $url The resource URL that returned 404.
     */
    public function __construct(public readonly string $url)
    {
        parent::__construct(message: sprintf('ZGW: resource not found at URL "%s"', $url));
    }//end __construct()
}//end class
