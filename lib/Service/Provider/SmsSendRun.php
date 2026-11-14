<?php

/**
 * Pipelinq SmsSendRun.
 *
 * One SmsAdapter::send() call: the message, the tenant and whether failover is allowed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * What every provider attempt of one send shares.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsSendRun {
	/**
	 * Constructor.
	 *
	 * @param SmsMessage $message       The message to send.
	 * @param string     $tenantId      Tenant id, for the budget.
	 * @param bool       $allowFailover Whether a failed provider hands over to the next one.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SmsMessage $message,
		public readonly string $tenantId,
		public readonly bool $allowFailover,
	) {
	}//end __construct()
}//end class
