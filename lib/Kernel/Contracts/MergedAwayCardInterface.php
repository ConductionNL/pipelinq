<?php

/**
 * Contract of MergedAwayCard
 *
 * The public surface of MergedAwayCard that other components call, so they depend
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
 * Contract of MergedAwayCard.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MergedAwayCardInterface {

	/**
	 * Keep a merged-away record's address book card and note in it where the record went.
	 *
	 * @param string $contactsUid The card's UID.
	 * @param string $intoUuid    The record that stays.
	 * @param string $intoName    Its name.
	 *
	 * @return bool True when the card was found and written.
	 *
	 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
	 */
	public function mark(string $contactsUid, string $intoUuid, string $intoName): bool;

	/**
	 * Take the merge note off a card again, so it belongs to its own record once more.
	 *
	 * @param string $contactsUid The card's UID.
	 *
	 * @return bool True when the card was found and written.
	 *
	 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
	 */
	public function unmark(string $contactsUid): bool;
}//end interface
