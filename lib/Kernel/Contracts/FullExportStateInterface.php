<?php

/**
 * Contract of FullExportState
 *
 * The public surface of FullExportState that other components call, so they depend
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
 * Contract of FullExportState.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */
interface FullExportStateInterface {

	/**
	 * An export was asked for.
	 *
	 * @param string $userId The administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function queued(string $userId): void;

	/**
	 * The archive is in the administrator's Files.
	 *
	 * @param string     $userId The administrator.
	 * @param string     $name   The archive's file name.
	 * @param string|int $fileId The archive's file id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function ready(string $userId, string $name, string|int $fileId): void;

	/**
	 * The archive could not be built.
	 *
	 * @param string $userId The administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function failed(string $userId): void;

	/**
	 * The last record, or `{state: none}` when no export ran.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function read(): array;
}//end interface
