<?php

/**
 * Contract of ClientIdentifierService
 *
 * The public surface of ClientIdentifierService that other components call, so they depend
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
 * Contract of ClientIdentifierService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
interface ClientIdentifierServiceInterface {

	/**
	 * Why a number does not match its scheme's format, or null when it does.
	 *
	 * @param string $scheme The scheme: kvk, rsin, vat, duns or oin.
	 * @param string $value  The number.
	 *
	 * @return string|null The problem, in the user's language, or null.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function validateFormat(string $scheme, string $value): ?string;

	/**
	 * Every format problem in a client's identifiers list.
	 *
	 * @param mixed $identifiers The client's `identifiers` value.
	 *
	 * @return array<int, string> The problems; empty when the list is valid.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function problemsIn(mixed $identifiers): array;

	/**
	 * Check a KvK number against the KvK register.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function checkKvk(string $value): array;

	/**
	 * What the KvK register says about a KvK number, as client fields
	 * (`place`, `legalForm`, `industry`), and whether it was found.
	 *
	 * @param string $value The KvK number.
	 *
	 * @return array{checkStatus: string, facts: array<string, mixed>|null} The outcome, and the facts when found.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function kvkFacts(string $value): array;

	/**
	 * Check a VAT number through shillinq's VIES service.
	 *
	 * @param string $value The VAT number.
	 *
	 * @return array{checkStatus: string, checkedAt: string, checkSource: string, validUntil?: string} The outcome.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.3
	 */
	public function checkVat(string $value): array;

	/**
	 * Whether the calling user may open the client: a read with their rights.
	 *
	 * @param string $clientId The client's id.
	 *
	 * @return bool True when the client exists and the user may read it.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function canAccessClient(string $clientId): bool;

	/**
	 * Check one identifier of a client against its register and store the outcome.
	 *
	 * @param string $clientId The client's id.
	 * @param string $scheme   The identifier's scheme.
	 * @param string $value    The identifier's number.
	 *
	 * @return array{status: int, identifier?: array<string, mixed>, message?: string} The HTTP status and the checked identifier.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	public function check(string $clientId, string $scheme, string $value): array;
}//end interface
