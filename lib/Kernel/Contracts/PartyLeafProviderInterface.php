<?php

/**
 * Contract of PartyLeafProvider
 *
 * The public surface of PartyLeafProvider that other components call, so they depend
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
 * Contract of PartyLeafProvider.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyLeafProviderInterface {

	/**
	 * One party's declared fields, its values and its live indicators.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed> `status` plus either the panel or `error`.
	 *
	 * @spec openspec/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
	 */
	public function describe(string $partyId): array;
}//end interface
