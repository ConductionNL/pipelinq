<?php

/**
 * Test stub for OCA\OpenRegister\BackgroundJob\ActorForwardedJob.
 *
 * Mirrors the real abstract base
 * (openregister/lib/BackgroundJob/ActorForwardedJob.php): the same constructor
 * parameter list, the same `protected abstract runDeferred()` contract, and the
 * same `protected readonly LoggerInterface $logger` that subclasses use.
 *
 * `run()` is deliberately NOT re-implemented — the real base re-establishes the
 * acting user and restores it in a `finally`. Tests here call `runDeferred()`
 * directly, which is the method this app actually writes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

if (class_exists(ActorForwardedJob::class) === false) {
	/**
	 * Stub base for ActorForwardedJob — used only in standalone unit tests.
	 */
	abstract class ActorForwardedJob extends QueuedJob {

		/**
		 * Wire the identity plumbing shared by all actor-forwarded jobs.
		 *
		 * @param ITimeFactory $time Time factory for the parent job class.
		 * @param IUserSession $userSession Session to impersonate on / restore.
		 * @param IUserManager $userManager Resolver for the captured user id.
		 * @param OrganisationService $organisation Active-organisation resolver.
		 * @param LoggerInterface $logger PSR logger shared with subclasses.
		 *
		 * @return void
		 */
		public function __construct(
			ITimeFactory $time,
			private readonly IUserSession $userSession,
			private readonly IUserManager $userManager,
			private readonly OrganisationService $organisation,
			protected readonly LoggerInterface $logger,
		) {
			parent::__construct(time: $time);
		}//end __construct()

		/**
		 * Re-establish the captured actor, run the deferred work, restore.
		 *
		 * Mirrors the real base's identity rules: a captured user that no longer
		 * resolves SKIPS the work rather than running it under whatever identity
		 * the worker holds, and the previous user is restored in a `finally` so a
		 * cron process never carries one job's identity into the next.
		 *
		 * @param array<string, mixed> $argument Serialized DeferredListenerContext.
		 *
		 * @return void
		 */
		protected function run($argument): void {
			$context = DeferredListenerContext::fromJobArguments($argument);
			if (count($context->getEntries()) === 0) {
				return;
			}

			$userId = $context->getUserId();
			$user = null;
			if ($userId !== null) {
				$user = $this->userManager->get($userId);
				if ($user === null) {
					return;
				}
			}

			$previousUser = $this->userSession->getUser();
			if ($user !== null) {
				$this->userSession->setUser($user);
			}

			try {
				$this->runDeferred(context: $context);
			} finally {
				$this->userSession->setUser($previousUser);
			}
		}//end run()

		/**
		 * The deferred listener work, executed under the re-established actor.
		 *
		 * @param DeferredListenerContext $context The captured dispatch-time context.
		 *
		 * @return void
		 */
		abstract protected function runDeferred(DeferredListenerContext $context): void;
	}
}
