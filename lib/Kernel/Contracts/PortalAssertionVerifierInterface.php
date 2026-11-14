<?php

/**
 * Contract of PortalAssertionVerifier
 *
 * The public surface of PortalAssertionVerifier that other components call, so they depend
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
 * Contract of PortalAssertionVerifier.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAssertionVerifierInterface {

	/**
	 * The header portaliq attaches the assertion to (contract v2, A6).
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * Verify an `X-Portal-Subject` assertion and return its claims.
	 *
	 * Fail-closed on EVERYTHING — the claims array is returned only when all
	 * of the following hold, null otherwise (no exception ever escapes):
	 *
	 *   1. compact JWS structure — exactly three non-empty segments;
	 *   2. header `alg` is exactly `HS256` (rejects `none`, case tricks,
	 *      and any asymmetric algorithm-confusion attempt);
	 *   3. HMAC signature matches — hash_equals, constant time;
	 *   4. claims decode to a JSON object;
	 *   5. `use` is exactly `assertion` (session tokens are refused);
	 *   6. `iss` is exactly `portaliq`;
	 *   7. `exp` present, integer, strictly in the future;
	 *   8. `iat` present, integer, not in the future (60s leeway), <= exp;
	 *   9. `sub` present, non-empty string — an assertion without a subject
	 *      can scope nothing, so it authorises nothing.
	 *
	 * @param string $jwt The raw header value (compact JWT).
	 *
	 * @return array<string, mixed>|null The verified claims, or null.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function verify(string $jwt): ?array;
}//end interface
