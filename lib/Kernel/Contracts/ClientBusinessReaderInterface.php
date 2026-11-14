<?php

/**
 * Contract of ClientBusinessReader
 *
 * The public surface of ClientBusinessReader that other components call, so they depend
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
 * Contract of ClientBusinessReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ClientBusinessReaderInterface {

	/**
	 * The sentences for one client.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<string, mixed> `status`, and on 200 `leadsLine`, `contractsLine`, `projectsLine`, `invoicesLine` and `shillinqUrl`.
	 *
	 * @spec openspec/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
	 */
	public function businessOf(string $clientId): array;
}//end interface
