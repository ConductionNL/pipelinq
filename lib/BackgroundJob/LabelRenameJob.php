<?php

/**
 * Pipelinq LabelRenameJob.
 *
 * The rest of a large label rename: rewrites the old name on every item that
 * still carries it, as the administrator who renamed the label.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queued once by LabelRenameService::rename() past its inline limit.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
 */
class LabelRenameJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time        The time factory (parent contract).
	 * @param LabelRenameService $service     The rename pass.
	 * @param IUserManager       $userManager Finds the administrator.
	 * @param IUserSession       $userSession Carries the administrator for OpenRegister's access check.
	 * @param LoggerInterface    $logger      The logger.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	public function __construct(
		ITimeFactory $time,
		private LabelRenameService $service,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Rewrite the rest as the administrator who renamed the label.
	 *
	 * @param mixed $argument {from, to, userId}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	protected function run(mixed $argument): void {
		$args = (array)$argument;
		$user = $this->userManager->get((string)($args['userId'] ?? ''));
		if ($user === null) {
			$this->logger->warning('LabelRenameJob: the administrator no longer exists; nothing rewritten');
			return;
		}

		$this->userSession->setUser($user);
		try {
			$count = $this->service->rewriteAll(
				from: (string)($args['from'] ?? ''),
				to: (string)($args['to'] ?? ''),
				userId: $user->getUID()
			);
			$this->logger->info('LabelRenameJob: items rewritten', ['count' => $count]);
		} catch (Throwable $e) {
			$this->logger->warning('LabelRenameJob: rewrite failed', ['exception' => $e->getMessage()]);
		} finally {
			$this->userSession->setUser(null);
		}
	}//end run()
}//end class
