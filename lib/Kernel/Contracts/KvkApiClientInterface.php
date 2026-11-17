<?php

/**
 * Contract of KvkApiClient
 *
 * The public surface of KvkApiClient that other components call, so they depend
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
 * Contract of KvkApiClient.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KvkApiClientInterface {

	/**
	 * The KvK rows for one KvK number, through OpenRegister's KvK source.
	 *
	 * @param string $kvkNumber The KvK number.
	 *
	 * @return array<int, array<string, mixed>> The rows; empty when the source answers nothing.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	public function companyByNumber(string $kvkNumber): array;

	/**
	 * The KvK rows for a company name, through OpenRegister's KvK source.
	 *
	 * @param string $name The company name.
	 *
	 * @return array<int, array<string, mixed>> The rows; empty when the source answers nothing.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	public function searchByName(string $name): array;
}//end interface
