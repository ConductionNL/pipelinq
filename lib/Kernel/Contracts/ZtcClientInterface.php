<?php

/**
 * Contract of ZtcClient
 *
 * The public surface of ZtcClient that other components call, so they depend
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
 * Contract of ZtcClient.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZtcClientInterface {

	public const RESOURCE_ZAAKTYPE = 'zaaktypen';

	public const RESOURCE_STATUSTYPE = 'statustypen';

	public const RESOURCE_ROLTYPE = 'roltypen';

	public const RESOURCE_RESULTAATTYPE = 'resultaattypen';

	public const RESOURCE_BESLUITTYPE = 'besluittypen';

	/**
	 * Invalidate the cache for one resource type (called on catalogi NRC events).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $resourceType One of RESOURCE_* (or '*' for all).
	 *
	 * @return void
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function invalidateCache(array $endpoint, string $resourceType = '*'): void;

	/**
	 * Lookup the statustype omschrijving for a fully-qualified statustype URL.
	 *
	 * Used by `NrcNotificationListener` when a status notification arrives:
	 * we GET the status URL → extract its statustype URL → call this method
	 * → use the omschrijving to update the pipelinq Request.status field.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $statustypeUrl Fully-qualified statustype URL.
	 *
	 * @return string|null Omschrijving (or null when the lookup fails).
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveOmschrijvingFromUrl(array $endpoint, string $statustypeUrl): ?string;
}//end interface
