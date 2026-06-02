<?php

/**
 * Pipelinq PaymentApiException.
 *
 * Internal exception carrying a user-safe message for a failed provider API
 * call. Caught inside the adapters and converted to a `status => failed` result
 * so a provider error never propagates a raw HTTP error or stack trace to the
 * client.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use RuntimeException;

/**
 * A provider API failure with a user-safe message (no secrets / stack traces).
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-010
 */
class PaymentApiException extends RuntimeException
{
}//end class
