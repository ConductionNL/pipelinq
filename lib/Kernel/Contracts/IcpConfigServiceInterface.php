<?php

/**
 * Contract of IcpConfigService
 *
 * The public surface of IcpConfigService that other components call, so they depend
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
 * Contract of IcpConfigService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IcpConfigServiceInterface {

	/**
	 * Get all ICP settings.
	 *
	 * @return array The ICP configuration.
	 * @spec   openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-9
	 */
	public function getSettings(): array;

	/**
	 * Save ICP settings.
	 *
	 * @param array $data The ICP data to save.
	 *
	 * @return string The ICP hash.
	 * @spec   openspec/changes/reverse-2026-05-26-be-prospect/tasks.md#task-11
	 */
	public function saveSettings(array $data): string;
}//end interface
