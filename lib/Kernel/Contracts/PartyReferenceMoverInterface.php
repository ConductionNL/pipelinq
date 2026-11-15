<?php

/**
 * Contract of PartyReferenceMover
 *
 * The public surface of PartyReferenceMover that other components call, so they depend
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
 * Contract of PartyReferenceMover.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyReferenceMoverInterface {

	/**
	 * Point every reference to the merged-away record at the record that stays.
	 *
	 * @param string $partyType `client` or `contact`.
	 * @param string $fromUuid  The merged-away record.
	 * @param string $intoUuid  The record that stays.
	 *
	 * @return list<array{schema: string, uuid: string, property: string}> The rows moved.
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
	 */
	public function move(string $partyType, string $fromUuid, string $intoUuid): array;

	/**
	 * Point the rows a merge moved back at the merged-away record.
	 *
	 * A row that no longer points at the record that stays, or is gone, is skipped.
	 *
	 * @param array<int, mixed> $ledger   The rows the merge moved, as move() listed them.
	 * @param string            $fromUuid The merged-away record, restored.
	 * @param string            $intoUuid The record that stayed.
	 *
	 * @return array{restored: list<array<string, string>>, skipped: list<array<string, string>>}
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
	 */
	public function restore(array $ledger, string $fromUuid, string $intoUuid): array;
}//end interface
