<?php

/**
 * Pipelinq WhatsAppProviderAttempt.
 *
 * One provider tried during a WhatsAppAdapter::send() call.
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

use Throwable;

/**
 * One provider row tried for a WhatsApp send.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppProviderAttempt {
	/**
	 * Constructor.
	 *
	 * @param WhatsAppSendRun      $run        The send this attempt belongs to.
	 * @param array<string, mixed> $row        Provider row.
	 * @param string               $providerId Provider UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly WhatsAppSendRun $run,
		public readonly array $row,
		public readonly string $providerId,
	) {
	}//end __construct()

	/**
	 * The log line for a failed attempt, by what the client threw.
	 *
	 * @param Throwable $error What the client threw.
	 *
	 * @return string The log message.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function failureLog(Throwable $error): string {
		if ($error instanceof TransientSmsProviderException) {
			return 'WhatsAppAdapter.send: transient — failing over';
		}

		if ($error instanceof PermanentSmsProviderException) {
			return 'WhatsAppAdapter.send: permanent provider failure';
		}

		return 'WhatsAppAdapter.send: unexpected — failing over';
	}//end failureLog()
}//end class
