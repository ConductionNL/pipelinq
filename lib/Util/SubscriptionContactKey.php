<?php

/**
 * Pipelinq SubscriptionContactKey.
 *
 * The key that identifies a subscription's contact.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * The subscription's contactId, or its email when it has none.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SubscriptionContactKey {

	/**
	 * The key the consent ledger is written under for a membership.
	 *
	 * A public signup that has not been matched to a contact still needs a
	 * ledger entry, so the address stands in for the contact id. It is
	 * stable, it is what the person gave us, and it is what a later match
	 * will join on.
	 *
	 * @param array<string, mixed> $subscription The membership.
	 *
	 * @return string The consent key.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function contactKeyFor(array $subscription): string {
		$contactId = (string)($subscription['contactId'] ?? '');
		if ($contactId !== '') {
			return $contactId;
		}

		return (string)($subscription['email'] ?? '');
	}//end contactKeyFor()
}//end trait
