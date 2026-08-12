<?php

/**
 * Pipelinq PermanentSmsProviderException.
 *
 * Marker exception thrown by SMS provider clients on 4xx (invalid
 * payload, blocked number, ...) failures. {@see \OCA\Pipelinq\Service\SmsAdapter}
 * does NOT fail over on permanent errors — the message is persisted
 * as `failed`.
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
 * Permanent provider failure — caller MUST not fail over.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.3
 */
class PermanentSmsProviderException extends RuntimeException {
}//end class
