<?php

/**
 * Pipelinq WhatsAppSendFailure.
 *
 * Why a WhatsAppAdapter::send() call ended without a delivered message.
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
 * The provider a WhatsApp send failed on and the error it gave.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class WhatsAppSendFailure {
	/**
	 * Constructor.
	 *
	 * @param string $providerId Provider UUID, or empty when every provider missed.
	 * @param string $error      Error description.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $error,
	) {
	}//end __construct()
}//end class
