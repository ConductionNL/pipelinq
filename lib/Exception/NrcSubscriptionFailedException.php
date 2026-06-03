<?php

/**
 * Pipelinq NrcSubscriptionFailedException.
 *
 * Raised when registering, syncing or unregistering an NRC abonnement fails
 * (NRC unreachable or rejected the request). The caller decides whether to
 * retry or notify the beheerder (REQ-ZGW-007).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Exception;

/**
 * Raised when an NRC abonnement operation fails.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#8.3
 */
class NrcSubscriptionFailedException extends ZgwBridgeException
{
}//end class
