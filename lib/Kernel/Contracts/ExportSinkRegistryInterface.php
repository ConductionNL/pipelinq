<?php

/**
 * Contract of ExportSinkRegistry
 *
 * The public surface of ExportSinkRegistry that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Pipelinq\Adapter\ExportSinkInterface;

/**
 * Contract of ExportSinkRegistry.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-001
 */
interface ExportSinkRegistryInterface {

	/**
	 * Register (or replace) a sink adapter by its type.
	 *
	 * @param ExportSinkInterface $sink The adapter.
	 *
	 * @return void
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function register(ExportSinkInterface $sink): void;

	/**
	 * Whether an adapter is registered for the given type.
	 *
	 * @param string $type The destination type slug.
	 *
	 * @return bool True when supported.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function supports(string $type): bool;

	/**
	 * Resolve the adapter for a destination type.
	 *
	 * @param string $type The destination type slug.
	 *
	 * @return ExportSinkInterface The adapter.
	 *
	 * @throws \InvalidArgumentException When no adapter handles the type.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function get(string $type): ExportSinkInterface;
}//end interface
