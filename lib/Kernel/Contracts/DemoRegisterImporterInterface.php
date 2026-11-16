<?php

/**
 * Contract of DemoRegisterImporter
 *
 * The public surface of DemoRegisterImporter that other components call, so they depend
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
 * Contract of DemoRegisterImporter.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoRegisterImporterInterface {

	/**
	 * Import the example records descriptor.
	 *
	 * @return array{imported: int, skipped: int} How many records arrived and how many did not.
	 *
	 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function import(): array;

	/**
	 * Remove every record the descriptor describes, wherever it was imported from.
	 *
	 * Two sources are covered. Imports made through {@see self::import()} are
	 * recorded by OpenRegister and soft-deleted by job. Installs that received
	 * the records from the old register descriptor have no job, so those are
	 * found by schema and slug, and only removed when their name still matches
	 * the descriptor: a record somebody has since renamed is theirs now.
	 *
	 * @return array{removed: int, retained: int}
	 *
	 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(): array;
}//end interface
