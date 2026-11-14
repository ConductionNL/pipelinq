<?php

/**
 * Contract of PartyLinkService
 *
 * The public surface of PartyLinkService that other components call, so they depend
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
 * Contract of PartyLinkService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyLinkServiceInterface {

	/**
	 * End a party link, without deleting the record of it.
	 *
	 * Ending rather than deleting: replacing a single-kind holder is an
	 * explicit act, and who held the kind until when is part of the file.
	 *
	 * @param string $linkId The link's uuid.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
	 */
	public function end(string $linkId): array;

	/**
	 * Write every accepted row of a batch, refusing the rest by the same rule.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param array<int, array<string, string>> $rows Each with `party` and `kind`.
	 *
	 * @return array<string, mixed> `status`, the rows that landed and the rows refused.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function import(string $recordType, string $recordId, array $rows): array;

	/**
	 * Link a party to a record under a kind.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param string $party The party.
	 * @param string $kind The party kind code.
	 *
	 * @return array<string, mixed> `status` plus either `partyLink` or `error`.
	 *
	 * @spec openspec/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function link(string $recordType, string $recordId, string $party, string $kind): array;
}//end interface
