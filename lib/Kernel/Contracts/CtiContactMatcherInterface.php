<?php

/**
 * Contract of CtiContactMatcher
 *
 * The public surface of CtiContactMatcher that other components call, so they depend
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
 * Contract of CtiContactMatcher.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CtiContactMatcherInterface {

	/**
	 * Normalise all stored phone numbers on contact and client records.
	 *
	 * Streams up to 5000 records per call (callers can loop). For each record
	 * that has a phone-bearing field and lacks an `e164` field, the normalised
	 * value is written back via OR `updateObject`.
	 *
	 * @return array{updated:int, skipped:int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses streaming records; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential guard clauses streaming records; extraction adds no clarity.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.3
	 */
	public function normaliseStoredPhoneNumbers(): array;
}//end interface
