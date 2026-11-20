<?php

/**
 * Contract of EmailMatchService
 *
 * The public surface of EmailMatchService that other components call, so they depend
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
 * Contract of EmailMatchService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailMatchServiceInterface {

	/**
	 * Read the matching settings for a user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array{account:int,enabled:bool,excludedAddresses:array<int,string>,cursor:int}
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function getSettings(string $userId): array;

	/**
	 * Read the last-run status for a user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array{lastRunAt:?string,linked:int,scanned:int,error:?string}
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function getStatus(string $userId): array;

	/**
	 * The user's own Nextcloud Mail accounts, the only accounts the matcher may read.
	 *
	 * Empty when Nextcloud Mail is not installed or the user has no account.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array<int,array{id:int,email:string,name:string}>
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function listAccounts(string $userId): array;

	/**
	 * Whether a Nextcloud Mail account belongs to the user.
	 *
	 * @param string $userId    The user id.
	 * @param int    $accountId The Mail account id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function ownsAccount(string $userId, int $accountId): bool;

	/**
	 * Run the matching job for one user.
	 *
	 * Reads the user's matching settings (account + enabled). When sync is
	 * disabled or no account is configured, the run is a no-op. Otherwise
	 * iterates the inbound Mail messages since the last cursor and calls
	 * `matchAndLinkMessage` for each. Per-message failures do not abort
	 * the run.
	 *
	 * @param string $userId The user id to run for.
	 *
	 * @return array{linked:int,scanned:int} Counts for the run.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function runForUser(string $userId): array;

	/**
	 * Persist the matching settings for a user.
	 *
	 * @param string $userId The user id.
	 * @param array{account:int,enabled:bool,excludedAddresses:array<int,string>,cursor?:int} $settings Settings payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function writeSettings(string $userId, array $settings): void;

	/**
	 * Persist the last-run status for a user.
	 *
	 * @param string $userId The user id.
	 * @param int $linked Number of new leaf links.
	 * @param int $scanned Number of messages scanned.
	 * @param string|null $error Optional static error message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error = null): void;
}//end interface
