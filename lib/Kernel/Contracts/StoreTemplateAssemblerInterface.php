<?php

/**
 * Contract of StoreTemplateAssembler
 *
 * The public surface of StoreTemplateAssembler that other components call, so they depend
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

/**
 * Contract of StoreTemplateAssembler.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StoreTemplateAssemblerInterface {

	/**
	 * The chosen objects as store item components, without their identity.
	 *
	 * @param string                                         $kind   The store item kind.
	 * @param array<int, array{schema?: mixed, id?: mixed}> $chosen The objects to include.
	 *
	 * @return array<int, array{schema: string, object: array<string, mixed>}>
	 *
	 * @throws InvalidArgumentException When an object is outside the kind or not on this instance.
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function components(string $kind, array $chosen): array;

	/**
	 * The local objects a template of this kind may carry, by title.
	 *
	 * @param string $kind The store item kind.
	 *
	 * @return array<int, array{schema: string, id: string, title: string}>
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function publishable(string $kind): array;
}//end interface
