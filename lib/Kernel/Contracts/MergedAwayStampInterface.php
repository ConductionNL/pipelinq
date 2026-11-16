<?php

/**
 * Contract of MergedAwayStamp
 *
 * The public surface of MergedAwayStamp that other components call, so they depend
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

use OCA\OpenRegister\Event\ObjectsMergedEvent;

/**
 * Contract of MergedAwayStamp.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MergedAwayStampInterface {

	/**
	 * The fields of the stamp.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['mergedInto', 'mergedIntoLabel', 'mergedAt'];

	/**
	 * Stamp one merged-away record, or clear its stamp when the merge is undone.
	 * Records that are not a client or contact are left alone.
	 *
	 * @param string             $uuid  The merged-away record.
	 * @param ObjectsMergedEvent $event The merge or its reversal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
	 */
	public function apply(string $uuid, ObjectsMergedEvent $event): void;
}//end interface
