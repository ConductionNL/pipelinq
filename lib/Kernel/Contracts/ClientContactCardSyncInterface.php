<?php

/**
 * Contract of ClientContactCardSync
 *
 * The public surface of ClientContactCardSync that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ClientContactCardSync.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 */
interface ClientContactCardSyncInterface {

	/**
	 * Write a Contacts card for every client under the uid the client names.
	 *
	 * A card that already holds the uid is updated where it lives; a missing
	 * card is created under that uid in the acting user's first writable
	 * addressbook. A client without a `contactsUid` is skipped.
	 *
	 * @param bool $dryRun Report what would be written without writing.
	 *
	 * @return array{written: list<array{id: string, name: string, uid: string}>,
	 *               skipped: list<array{id: string, name: string}>,
	 *               failed: list<array{id: string, name: string, uid: string}>} The clients per outcome.
	 *
	 * @throws RuntimeException When Contacts is unavailable, or the contact register or client schema is not configured.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 *
	 * @spec openspec/changes/archive/2026-11-26-example-set-zuiddrecht/tasks.md#task-2.5
	 */
	public function run(bool $dryRun=false): array;
}//end interface
