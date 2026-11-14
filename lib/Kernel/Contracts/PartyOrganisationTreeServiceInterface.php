<?php

/**
 * Contract of PartyOrganisationTreeService
 *
 * The public surface of PartyOrganisationTreeService that other components call, so they depend
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
 * Contract of PartyOrganisationTreeService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyOrganisationTreeServiceInterface {

	/**
	 * Give an organisation a parent, moving its subtree with it.
	 *
	 * @param string $nodeId The organisation to move.
	 * @param string|null $parentId The new parent, or null to make it a root.
	 *
	 * @return array<string, mixed> `status` plus either `moved` or `error`.
	 *
	 * @spec openspec/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	public function setParent(string $nodeId, ?string $parentId): array;
}//end interface
