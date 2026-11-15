<?php

/**
 * Contract of ContactSyncSettingsService
 *
 * The public surface of ContactSyncSettingsService that other components call, so they depend
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
 * Contract of ContactSyncSettingsService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactSyncSettingsServiceInterface {

	/**
	 * Write every linked record back to Nextcloud Contacts, and remember the run.
	 *
	 * @param string $userId The user who asked.
	 * @param string $now    The moment of the run (ISO 8601).
	 *
	 * @return array{at: string, updated: int, failed: array<int, array{name: string}>} The run.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function run(string $userId, string $now): array;

	/**
	 * Turn a user's write-back on or off.
	 *
	 * @param string $userId    The user.
	 * @param bool   $writeBack Whether saves go to Nextcloud Contacts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function saveWriteBack(string $userId, bool $writeBack): void;

	/**
	 * The status the settings section shows.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{writeBack: bool, linked: int, addressBook: string, lastRun: array<string, mixed>|null} The status.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function status(string $userId): array;

	/**
	 * Whether a user's saves are written back to Nextcloud Contacts (on unless turned off).
	 *
	 * @param string $userId The user.
	 *
	 * @return bool True when write-back is on.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function writesBack(string $userId): bool;
}//end interface
