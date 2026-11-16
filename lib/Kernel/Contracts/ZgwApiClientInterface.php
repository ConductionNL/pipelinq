<?php

/**
 * Contract of ZgwApiClient
 *
 * The public surface of ZgwApiClient that other components call, so they depend
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
 * Contract of ZgwApiClient.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwApiClientInterface {

	/**
	 * Resolve a vault reference (`vault://...`) to a raw secret string.
	 *
	 * Falls back to the literal value when the reference is not a `vault://`
	 * URI — useful for dev/test setups where the secret is held in IAppConfig.
	 *
	 * @param string $reference The `secretKluisRef` value.
	 *
	 * @return string The resolved secret (empty string when unresolvable).
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function resolveClientSecret(string $reference): string;
}//end interface
