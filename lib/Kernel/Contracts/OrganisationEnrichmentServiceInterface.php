<?php

/**
 * Contract of OrganisationEnrichmentService
 *
 * The public surface of OrganisationEnrichmentService that other components call, so they depend
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
 * Contract of OrganisationEnrichmentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationEnrichmentServiceInterface {

	/**
	 * Write the accepted proposals onto their clients.
	 *
	 * @param array<int, array{clientId?: string, field?: string, value?: string}> $changes The accepted proposals.
	 *
	 * @return array{updated: int} How many fields were written.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	public function apply(array $changes): array;

	/**
	 * Look the organisations up and propose values for their empty fields.
	 *
	 * A person is never looked up: personal data from the BRP is not filled
	 * in bulk, so only clients of type organization take part.
	 *
	 * @param array<int, string> $clientIds The selected clients.
	 *
	 * @return array{proposals: array<int, array<string, mixed>>, notFound: array<int, string>} Proposals and unknown names.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	public function propose(array $clientIds): array;
}//end interface
