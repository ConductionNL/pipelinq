<?php

/**
 * Pipelinq MoveMarketingArticlesToPortaliq repair step.
 *
 * Marketing articles belong to portaliq (marketing-articles-move-to-portaliq).
 * This step copies every pipelinq `article` into portaliq once, rewrites the
 * campaign templates and social posts that name them, and reports how many
 * moved, were already moved and failed. A no-op that says so without portaliq
 * or its article schema; idempotent by `legacyRef`; never fatal for an upgrade.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\Marketing\ArticleMoveService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the article move and reports its counts.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */
class MoveMarketingArticlesToPortaliq implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ArticleMoveService $move   The move.
	 * @param LoggerInterface    $logger The logger.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function __construct(
		private readonly ArticleMoveService $move,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function getName(): string {
		return 'Move pipelinq marketing articles into portaliq (marketing-articles-move-to-portaliq)';
	}//end getName()

	/**
	 * Run the move and report it.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function run(IOutput $output): void {
		try {
			$counts = $this->move->run();
		} catch (Throwable $e) {
			// Never fatal for the upgrade: nothing is marked moved that was not, and the next run retries.
			$this->logger->error('MoveMarketingArticlesToPortaliq failed: '.$e->getMessage(), ['exception' => $e]);
			$output->warning('Article move failed: '.$e->getMessage());
			return;
		}

		if ($counts['status'] === 'portaliq-missing') {
			$output->info('Article move skipped: portaliq or its article schema is not available');
			return;
		}

		$renamed = '';
		if ($counts['renamedSlugs'] !== []) {
			$renamed = '; slugs renamed with -pipelinq: '.implode(', ', $counts['renamedSlugs']);
		}

		$output->info(
			sprintf(
				'Article move %s: %d moved, %d already moved, %d failed, %d references rewritten%s',
				$counts['status'],
				$counts['moved'],
				$counts['alreadyMoved'],
				$counts['failed'],
				$counts['references'],
				$renamed
			)
		);
	}//end run()
}//end class
