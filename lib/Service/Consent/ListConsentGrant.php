<?php

/**
 * Pipelinq ListConsentGrant.
 *
 * A list consent pipelinq hands to integriq.
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
 * The contact, list, channel, lawful basis, source and evidence of one list consent.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */
final class ListConsentGrant {
	/**
	 * Constructor.
	 *
	 * @param string              $contactId   Contact UUID, or a signup address.
	 * @param string              $listId      The list.
	 * @param string              $channel     The channel.
	 * @param string              $lawfulBasis The AVG article 6 basis.
	 * @param string              $source      How the consent was given.
	 * @param array<string,mixed> $evidence    What was shown and when.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $listId,
		public readonly string $channel,
		public readonly string $lawfulBasis,
		public readonly string $source,
		public readonly array $evidence,
	) {
	}//end __construct()
}//end class
