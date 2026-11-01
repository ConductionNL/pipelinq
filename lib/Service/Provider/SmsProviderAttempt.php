<?php

/**
 * Pipelinq SmsProviderAttempt.
 *
 * One provider tried within an SmsAdapter::send() call.
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
 * The send run, the provider being tried and its client.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsProviderAttempt {
	/**
	 * Constructor.
	 *
	 * @param SmsSendRun                 $run        The send this attempt belongs to.
	 * @param string                     $providerId Provider UUID.
	 * @param SmsProviderClientInterface $client     The provider's client.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SmsSendRun $run,
		public readonly string $providerId,
		public readonly SmsProviderClientInterface $client,
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
			return 'SmsAdapter.send: transient — failing over';
		}

		if ($error instanceof PermanentSmsProviderException) {
			return 'SmsAdapter.send: permanent provider failure';
		}

		return 'SmsAdapter.send: unexpected exception — failing over';
	}//end failureLog()
}//end class
