<?php

/**
 * Contract of OptOutService
 *
 * The public surface of OptOutService that other components call, so they depend
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
 * Contract of OptOutService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OptOutServiceInterface {

	/**
	 * Record a geheimhouding flag derived from a BRP response (indicatieGeheim).
	 *
	 * Creates the flag at most once: re-firing only updates `notitie`.
	 *
	 * @param string $rawBsn Raw BSN (caller MUST not log it).
	 * @param string $indicationSecret Value of BRP's indicatieGeheim ("0" = none, "1" = present).
	 *
	 * @return bool True when an OptOutVlag was newly created / refreshed.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-006-01
	 */
	public function recordFromBrpResponse(
		#[\SensitiveParameter]
		string $rawBsn,
		string $indicationSecret,
	): bool;

	/**
	 * Manually register a local opt-out (medewerker has captured a citizen request).
	 *
	 * @param string $rawBsn Raw BSN.
	 * @param string $actor The user UID who registered it.
	 * @param string|null $note Optional free-text explanation.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-006-02
	 */
	public function recordLocalOptOut(
		#[\SensitiveParameter]
		string $rawBsn,
		string $actor,
		?string $note = null,
	): bool;
}//end interface
