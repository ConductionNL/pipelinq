<?php

/**
 * Pipelinq TransientSmsProviderException.
 *
 * Marker exception thrown by SMS provider clients on 5xx / network /
 * timeout failures so {@see \OCA\Pipelinq\Service\SmsAdapter} knows
 * to fail over to the next priority provider.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use RuntimeException;

/**
 * Transient provider failure — eligible for failover.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.3
 */
class TransientSmsProviderException extends RuntimeException
{
}//end class
