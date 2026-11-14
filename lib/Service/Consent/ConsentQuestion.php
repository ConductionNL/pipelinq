<?php

/**
 * Pipelinq ConsentQuestion.
 *
 * One send-decision question pipelinq puts to integriq.
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
 * A consent ask plus how it is traced and whether it is a reply or a probe.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */
class ConsentQuestion {
	/**
	 * Constructor.
	 *
	 * @param ConsentAsk $ask           What kind of message it is.
	 * @param string     $correlationId Correlation id of the question.
	 * @param ?string    $inReplyTo     The inbound message a reply answers, or null.
	 * @param bool       $probe         True to ask without a log row.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ConsentAsk $ask,
		public readonly string $correlationId,
		public readonly ?string $inReplyTo,
		public readonly bool $probe,
	) {
	}//end __construct()
}//end class
