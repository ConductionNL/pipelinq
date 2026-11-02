<?php

/**
 * Pipelinq BlastSendRun.
 *
 * What one sendBlast() call knows once the audience is resolved.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
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
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * The blast, its channel, whether it is a dry run, and the resolved audience
 * with the contacts left out for missing consent and for a suppression.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */
class BlastSendRun {
	/**
	 * Constructor.
	 *
	 * @param string               $blastId  Blast UUID or slug.
	 * @param string               $channel  The blast channel.
	 * @param bool                 $isDraft  Whether this is a dry-run preview.
	 * @param array<string, mixed> $audience `members`, `missingConsent` and optionally `suppressed`.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The dry-run flag of sendBlast(), carried as data.
	 */
	public function __construct(
		public readonly string $blastId,
		public readonly string $channel,
		public readonly bool $isDraft,
		public readonly array $audience,
	) {
	}//end __construct()

	/**
	 * How many contacts were left out without consent.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function noConsent(): int {
		return count($this->audience['missingConsent']);
	}//end noConsent()

	/**
	 * How many contacts were left out by a suppression.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
	 */
	public function suppressed(): int {
		return count($this->audience['suppressed'] ?? []);
	}//end suppressed()
}//end class
