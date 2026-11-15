<?php

/**
 * Pipelinq SurveyInvitationOccasion.
 *
 * The rule, recipient, completed record and moment one survey invitation is built for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;

/**
 * What one survey invitation is built from.
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */
final class SurveyInvitationOccasion {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $rule    The matching dispatch rule.
	 * @param array<string, mixed> $contact The recipient.
	 * @param array<string, mixed> $entity  The record that completed.
	 * @param DateTimeImmutable    $now     The moment to judge at.
	 */
	public function __construct(
		public readonly array $rule,
		public readonly array $contact,
		public readonly array $entity,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
