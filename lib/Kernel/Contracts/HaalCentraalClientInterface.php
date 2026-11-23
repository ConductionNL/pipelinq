<?php

/**
 * Contract of HaalCentraalClient
 *
 * The public surface of HaalCentraalClient that other components call, so they depend
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

use OCA\Pipelinq\Service\HaalCentraalException;

/**
 * Contract of HaalCentraalClient.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HaalCentraalClientInterface {

	/**
	 * The status a lookup without a working integriq source fails with.
	 *
	 * @var int
	 */
	public const NO_SOURCE_STATUS = 503;

	/**
	 * The message a lookup without a working integriq source fails with.
	 *
	 * @var string
	 */
	public const NO_SOURCE_MESSAGE = 'BRP lookups go through integriq. Ask your administrator to set up the BRP connection there.';

	/**
	 * Lookup a person by BSN. Returns a BrpPersoon-shaped array or null when not found.
	 *
	 * The lookup goes through integriq's `brp-haalcentraal` source; there is
	 * no direct path to Haal Centraal. Without a working source it throws a
	 * HaalCentraalException whose message names integriq.
	 *
	 * @param string $bsn Raw 9-digit BSN.
	 * @param string|null $requestIdContext Optional context UUID for correlation logging.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws HaalCentraalException
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-01
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          BsnValidationService::mask is a pure stateless helper.
	 */
	public function lookupPersoon(
		#[\SensitiveParameter]
		string $bsn,
		?string $requestIdContext = null,
	): ?array;
}//end interface
