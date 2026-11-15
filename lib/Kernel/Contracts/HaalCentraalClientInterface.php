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

use DateTimeImmutable;
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
	 * Health-check: returns the certificate expiry (UTC) or null when unknown.
	 *
	 * Reads the configured client certificate file and inspects its notAfter field.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-02
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) The @ mutes fs/OpenSSL warnings on unreadable certs; failures handled via false checks.
	 */
	public function getCertificateExpiry(): ?DateTimeImmutable;

	/**
	 * Lookup a person by BSN. Returns a BrpPersoon-shaped array or null when not found.
	 *
	 * Throws HaalCentraalException on transport / auth / server errors so the caller
	 * can decide how to surface the failure.
	 *
	 * OR-first (ADR-022, safe-partial): the lookup tries the OpenRegister BRP leaf
	 * first. On HTTP 200 the leaf returns the raw HAL+JSON person under `results`
	 * plus the Wet-BRP audit metadata under `meta` ({ correlationId, durationMs,
	 * status }); the person is mapped through the EXACT SAME {@see normalisePerson()}
	 * as the legacy path, and the meta is attached as `_correlationId` /
	 * `_responseDurationMs` / `_responseStatus` so the caller persists a
	 * byte-identical `brpLookupVerzoek` audit record regardless of source. On a
	 * 503 (source unconfigured/down) / non-200 / OR-absent the method falls back
	 * to the legacy OAuth2 + mTLS direct HaalCentraal path below, unchanged, so
	 * configured envs keep working until an operator enables the OR source.
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
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-03
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          BsnValidationService::mask is a pure stateless helper.
	 */
	public function lookupPersoon(
		#[\SensitiveParameter]
		string $bsn,
		?string $requestIdContext = null,
	): ?array;
}//end interface
