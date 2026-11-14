<?php

/**
 * Contract of PhoneHistoryService
 *
 * The public surface of PhoneHistoryService that other components call, so they depend
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
 * Contract of PhoneHistoryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PhoneHistoryServiceInterface {

	/**
	 * The tickets about a number, newest first, at most MAX_RESULTS.
	 *
	 * @param string      $raw   The number as typed.
	 * @param string|null $orgId The organisation whose default country applies.
	 *
	 * @return array{number:string|null, tickets:array<int,array<string,mixed>>}
	 *         The query in E.164 (null when it does not normalise) and the
	 *         tickets, each with `_phoneMatch` set to exact or possible.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
	 */
	public function findTickets(string $raw, ?string $orgId = null): array;
}//end interface
