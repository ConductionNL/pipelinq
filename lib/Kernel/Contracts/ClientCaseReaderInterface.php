<?php

/**
 * Contract of ClientCaseReader
 *
 * The public surface of ClientCaseReader that other components call, so they depend
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
 * Contract of ClientCaseReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ClientCaseReaderInterface {

	/**
	 * The client's cases: `status` (HTTP) plus `results`, `total` and
	 * `available` (whether dossiq answered), or `message`.
	 *
	 * @param string $clientId The client uuid.
	 * @param string $status   `open`, `closed` or `all`.
	 * @param int    $limit    The most rows to return.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
	 */
	public function casesOf(string $clientId, string $status, int $limit): array;
}//end interface
