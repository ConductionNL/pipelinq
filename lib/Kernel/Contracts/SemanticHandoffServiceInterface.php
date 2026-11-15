<?php

/**
 * Contract of SemanticHandoffService
 *
 * The public surface of SemanticHandoffService that other components call, so they depend
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
 * Contract of SemanticHandoffService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SemanticHandoffServiceInterface {

	/**
	 * Execute a declared handoff via OpenRegister's engine.
	 *
	 * Kind-addressed: the target app is resolved by OR from the entry's
	 * `targetSemanticType`; no app id is named here. Returns a normalised
	 * outcome; a missing engine, an unavailable provider, or any failure leaves
	 * the source object untouched (the caller must not mutate on `ok === false`).
	 *
	 * @param string $register Source register slug.
	 * @param string $schema Source schema slug.
	 * @param string $id Source object UUID.
	 * @param string $handoffId The declared `x-openregister-handoff` entry id.
	 *
	 * @return array{ok: bool, targetUuid: string, correlationId: string, reason: string} Outcome.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 */
	public function handoff(string $register, string $schema, string $id, string $handoffId): array;

	/**
	 * Whether an installed app implements the given semantic kind.
	 *
	 * Resolves lazily through the container so pipelinq loads without OR: a
	 * missing resolver, a resolver error, or no implementing schema all yield
	 * false, which hides the emit action rather than erroring.
	 *
	 * @param string $kindUri Canonical kind URI (e.g. https://openregister.app/ns#Case).
	 *
	 * @return bool True when at least one installed+enabled schema implements the kind.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 */
	public function hasImplementer(string $kindUri): bool;

	/**
	 * Where the installed implementer of a semantic kind keeps its objects.
	 *
	 * The register and schema slugs are read from OpenRegister at run time, so
	 * no other app's id or slug is written into pipelinq: whichever installed
	 * app implements the kind answers. Null when nothing implements it, when
	 * the schema sits in no register, or when either slug is empty, so a
	 * caller shows nothing instead of pointing at a place that does not exist.
	 *
	 * @param string $kindUri Canonical kind URI (e.g. https://openregister.app/ns#Case).
	 *
	 * @return array{register: string, schema: string}|null The slugs, or null.
	 *
	 * @spec openspec/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
	 */
	public function implementerLocation(string $kindUri): ?array;
}//end interface
