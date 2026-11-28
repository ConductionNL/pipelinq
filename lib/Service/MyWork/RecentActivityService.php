<?php

/**
 * Pipelinq RecentActivityService.
 *
 * The latest audit-trail entries of the work assigned to a user, for the
 * Recent activity section of My work: what happened lately to the tickets,
 * leads and follow-ups that are theirs.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MyWork
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MyWork;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RecentActivityServiceInterface;
use OCA\Pipelinq\Service\WorklistService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The recent activity of a user's assigned work.
 *
 * The user's items come from the worklist, which reads them as the user, so
 * only objects the user may read are looked at.
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
class RecentActivityService implements RecentActivityServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param WorklistService        $worklist      The user's work items.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, for the audit trail.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly WorklistService $worklist,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The latest audit-trail entries of the user's assigned work (see ActivityTrail::latest()).
	 *
	 * Work that cannot be read answers no entries, so My work shows its empty
	 * text instead of an error.
	 *
	 * @param string $userId The Nextcloud UID whose work to look at.
	 *
	 * @return array<int, array<string, string>> The entries, newest first.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	public function forUser(string $userId): array {
		try {
			$items = $this->worklist->items(userId: $userId)['items'];
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: My work read no work for its recent activity', ['exception' => $e->getMessage()]);
			return [];
		}

		return (new ActivityTrail(objectService: $this->objectService, logger: $this->logger))->latest(items: $items);
	}//end forUser()
}//end class
