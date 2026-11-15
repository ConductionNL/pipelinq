<?php

/**
 * Contract of SatisfactionAggregationService
 *
 * The public surface of SatisfactionAggregationService that other components call, so they depend
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

/**
 * Contract of SatisfactionAggregationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SatisfactionAggregationServiceInterface {

	/**
	 * The panel for one client.
	 *
	 * @param string $clientId The client's uuid.
	 * @param DateTimeImmutable|null $now The moment the windows are measured from.
	 *
	 * @return array<string, mixed> The panel, with `empty` true when there is
	 *   nothing to show.
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function forClient(string $clientId, ?DateTimeImmutable $now = null): array;
}//end interface
