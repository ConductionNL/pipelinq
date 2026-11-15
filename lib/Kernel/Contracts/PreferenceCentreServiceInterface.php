<?php

/**
 * Contract of PreferenceCentreService
 *
 * The public surface of PreferenceCentreService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of PreferenceCentreService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PreferenceCentreServiceInterface {

	/**
	 * What the preference centre shows for a signed link.
	 *
	 * Returns every list the contact may hold, each with its current state,
	 * and nothing belonging to anyone else. An archived list is left out:
	 * offering it would let someone opt in to something that sends nothing.
	 *
	 * @param string $token The signed preferences token.
	 *
	 * @return array<int, array{id: string, name: string, description: string, subscribed: bool}>|null
	 *         Null when the token is unusable.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function preferencesForToken(string $token): ?array;

	/**
	 * Mint a preference-centre link for one contact.
	 *
	 * Minted rather than stored, so there is no link to leak from a row and
	 * none to invalidate when the contact changes.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return string Absolute URL.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function preferencesUrlFor(string $contactId): string;

	/**
	 * Save a preference choice.
	 *
	 * A ticked list the contact is not confirmed on is confirmed, and a list
	 * they hold and did not tick is closed. Both halves happen in one call,
	 * so a save can never leave the person half-moved.
	 *
	 * @param string $token The signed preferences token.
	 * @param array<int, string> $selectedListIds The lists that were ticked.
	 *
	 * @return array{status: string, confirmed: int, unsubscribed: int}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function savePreferences(string $token, array $selectedListIds): array;
}//end interface
