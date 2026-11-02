<?php

/**
 * Pipelinq ConsentAsk.
 *
 * What kind of message pipelinq asks integriq about.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Consent
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * The channel and category of a send, and whether it needs recorded consent.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */
class ConsentAsk {
	/**
	 * Constructor.
	 *
	 * @param string $channel         Channel (email, sms, whatsapp).
	 * @param string $category        Message category.
	 * @param bool   $requiresConsent Whether the send needs recorded consent.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $category,
		public readonly bool $requiresConsent,
	) {
	}//end __construct()

	/**
	 * The question for a plain send: a generated correlation id, no reply, no probe.
	 *
	 * @return ConsentQuestion The question.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function question(): ConsentQuestion {
		return new ConsentQuestion(ask: $this, correlationId: '', inReplyTo: null, probe: false);
	}//end question()
}//end class
