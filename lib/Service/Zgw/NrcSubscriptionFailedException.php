<?php

/**
 * Pipelinq NrcSubscriptionFailedException.
 *
 * Raised by `NrcSubscriptionService` when registering or unregistering an
 * abonnement against the NRC fails (network error, 4xx/5xx response, or
 * NRC reports the callbackUrl is unreachable from its side). Callers
 * decide whether to retry on a schedule or to surface a needs-input event
 * to the gemeente beheerder.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * NRC abonnement registration / deregistration failure.
 */
class NrcSubscriptionFailedException extends ZgwException
{
}//end class
