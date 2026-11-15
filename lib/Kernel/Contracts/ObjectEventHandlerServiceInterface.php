<?php

/**
 * Contract of ObjectEventHandlerService
 *
 * The public surface of ObjectEventHandlerService that other components call, so they depend
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
 * Contract of ObjectEventHandlerService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectEventHandlerServiceInterface {

	/**
	 * Handle a newly created object.
	 *
	 * @param object $objectEntity The created object entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
	 */
	public function handleCreated(object $objectEntity): void;

	/**
	 * Handle an updated object.
	 *
	 * @param object $newObject The new object entity.
	 * @param ?object $oldObject The old object entity or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-crm-automation-triggers
	 */
	public function handleUpdated(object $newObject, ?object $oldObject): void;
}//end interface
