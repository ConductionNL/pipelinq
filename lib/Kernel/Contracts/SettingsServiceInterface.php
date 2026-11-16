<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
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
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

	/**
	 * Create default pipelines if none exist.
	 * Delegates to DefaultPipelineService.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function createDefaultPipelines(): void;

	/**
	 * Create default skills if none exist.
	 * Delegates to DefaultSkillService.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-routing/spec.md
	 */
	public function createDefaultSkills(): void;

	/**
	 * Get all Pipelinq settings.
	 *
	 * @return array The settings as key-value pairs.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getSettings(): array;

	/**
	 * Get user settings for the given user.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return array The user settings as key-boolean pairs.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-settings/tasks.md#task-15
	 */
	public function getUserSettings(string $userId): array;

	/**
	 * Load settings by importing the register JSON via ConfigurationService.
	 * Delegates to SettingsLoadService.
	 *
	 * @param bool $force Whether to force re-import.
	 *
	 * @return array The import result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $force is a simple re-import toggle
	 * @spec                                        openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-settings/tasks.md#task-16
	 * @spec                                        openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
	 */
	public function loadSettings(bool $force = false): array;

	/**
	 * Update Pipelinq settings with the given data.
	 *
	 * @param array $data The settings data to update.
	 *
	 * @return array The updated settings.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
	 */
	public function updateSettings(array $data): array;

	/**
	 * Update user settings for the given user.
	 *
	 * @param string $userId The user ID.
	 * @param array $data The settings data to update.
	 *
	 * @return array The updated user settings.
	 * @spec   openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-settings/tasks.md#task-17
	 */
	public function updateUserSettings(string $userId, array $data): array;
}//end interface
