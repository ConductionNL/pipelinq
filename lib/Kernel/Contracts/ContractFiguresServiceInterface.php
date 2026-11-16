<?php

/**
 * Contract of ContractFiguresService
 *
 * The public surface of ContractFiguresService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ContractFiguresService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContractFiguresServiceInterface {

	/**
	 * The figures of a contract on a day.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param DateTimeImmutable $today The day.
	 *
	 * @return array<string, mixed> The figures.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-a-contract-page-reads-its-figures
	 */
	public function figures(array $contract, DateTimeImmutable $today): array;
}//end interface
