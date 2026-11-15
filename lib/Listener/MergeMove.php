<?php

/**
 * Pipelinq MergeMove.
 *
 * One merged-away record and the survivor its references move to or back from.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The merged-away record, its uuid, and the uuid of the record that stays.
 *
 * @spec openspec/changes/clients-reversible-merge/tasks.md#2.3
 */
final class MergeMove {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntityInterface $record   The merged-away record.
	 * @param string                $fromUuid The merged-away record's uuid.
	 * @param string                $intoUuid The survivor's uuid.
	 */
	public function __construct(
		public readonly ObjectEntityInterface $record,
		public readonly string $fromUuid,
		public readonly string $intoUuid,
	) {
	}//end __construct()
}//end class
