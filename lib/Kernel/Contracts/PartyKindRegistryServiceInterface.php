<?php

/**
 * Contract of PartyKindRegistryService
 *
 * The public surface of PartyKindRegistryService that other components call, so they depend
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
 * Contract of PartyKindRegistryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyKindRegistryServiceInterface {

	/**
	 * The ordered kinds a record type declared, or null when it declared none.
	 *
	 * Null and [] are different answers and are kept apart deliberately: null
	 * means nothing was declared, which is permissive, and [] means a record
	 * type declared that it accepts no party at all.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 *
	 * @return array<int, string>|null The declared codes, or null.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function acceptanceFor(string $recordType): ?array;

	/**
	 * The kinds a record type accepts, in the order they were declared.
	 *
	 * Where no acceptance exists, every ACTIVE kind is offered, so an app that
	 * has declared nothing keeps working. The declared order is never
	 * re-sorted: the first kind is what most handlers will take, and that is
	 * part of the declaration.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 *
	 * @return array<int, array<string, mixed>> The offered kinds, in order.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-the-picker-shall-offer-only-the-declared-kinds-in-the-declared-order-req-pkr-003
	 */
	public function kindsFor(string $recordType): array;

	/**
	 * Every declared kind, keyed by code.
	 *
	 * Includes inactive kinds: a link already written has to keep resolving
	 * its kind and its label after that kind is retired, or a retired kind
	 * takes the meaning of old links with it.
	 *
	 * @return array<string, array<string, mixed>> The vocabulary.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-pipelinq-shall-hold-the-vocabulary-of-party-kinds-req-pkr-001
	 */
	public function vocabulary(): array;
}//end interface
