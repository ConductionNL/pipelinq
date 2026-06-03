<?php

/**
 * Pipelinq ZgwBridgeException.
 *
 * Base exception for all ZGW (zaakgericht-werken) API bridge failures. Concrete
 * subclasses carry the contextual data needed for a beheerder to diagnose the
 * fault (clock offsets, missing scopes, conflicting representations, ...).
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
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

use RuntimeException;

/**
 * Base exception for the ZGW API bridge.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class ZgwBridgeException extends RuntimeException
{
}//end class
