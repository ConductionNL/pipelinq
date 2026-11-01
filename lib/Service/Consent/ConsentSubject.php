<?php

/**
 * Pipelinq ConsentSubject.
 *
 * Who a consent wish is about, and on which channel.
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * The contact, channel and address one consent wish is recorded for.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */
class ConsentSubject {
	/**
	 * Constructor.
	 *
	 * @param string $contactId Contact UUID, or empty for a number with no contact behind it.
	 * @param string $channel   `whatsapp`, `sms` or `email`.
	 * @param string $address   The address the wish is for; looked up when empty.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $channel,
		public readonly string $address,
	) {
	}//end __construct()
}//end class
