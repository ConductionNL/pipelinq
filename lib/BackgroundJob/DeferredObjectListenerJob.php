<?php

/**
 * Pipelinq DeferredObjectListenerJob.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Listener\DealUpdatedListener;
use OCA\Pipelinq\Listener\DeferredObjectWork;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\ExpenseApprovalListener;
use OCA\Pipelinq\Listener\SlaObjectCreatedListener;
use OCA\Pipelinq\Listener\SlaObjectUpdatedListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the work pipelinq's post-event listeners used to do inside the write.
 *
 * ADR-078: `Object*edEvent` listeners cannot influence the write they observe,
 * so their work is asynchronous by default. Each converted listener implements
 * {@see DeferredObjectWork} and hands an entry to OpenRegister's
 * `ListenerDeferralService`, which captures the acting user and enqueues this
 * job. The job re-establishes that user (via {@see ActorForwardedJob}) and
 * calls the listener back.
 *
 * ONE JOB FOR ALL SEVEN LISTENERS, NOT SEVEN JOBS. The deferral service buffers
 * per job class, so a single class lets one request's worth of listener work
 * coalesce into one job row instead of seven; and the re-entrancy guard below
 * has to be applied identically to every one of them, which is easier to keep
 * true in one place.
 *
 * THE HANDLER MAP IS AN ALLOW-LIST, NOT A LOOKUP. The handler key arrives from
 * a persisted job row, so a class name taken from it and resolved through the
 * container would be an instantiate-anything primitive. Only the keys below
 * resolve; anything else is logged and dropped.
 *
 * It extends `ActorForwardedJob`, which is a `QueuedJob`: it runs once and is
 * removed from the job list. It never re-queues itself, so it cannot starve the
 * cron queue behind it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The class exists precisely to
 *  fan one job out to the app's converted listeners; naming them is the point.
 *
 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
 */
class DeferredObjectListenerJob extends ActorForwardedJob {

	/**
	 * Handler key -> listener class. An allow-list; see the class docblock.
	 *
	 * @var array<string, class-string<DeferredObjectWork>>
	 */
	private const HANDLERS = [
		DealCreatedListener::HANDLER_KEY => DealCreatedListener::class,
		DealUpdatedListener::HANDLER_KEY => DealUpdatedListener::class,
		ExpenseApprovalListener::HANDLER_KEY => ExpenseApprovalListener::class,
		SlaObjectCreatedListener::HANDLER_KEY => SlaObjectCreatedListener::class,
		SlaObjectUpdatedListener::HANDLER_KEY => SlaObjectUpdatedListener::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id.
	 * @param OrganisationService $organisation Active-organisation resolver.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ContainerInterface $container DI container the listeners resolve from.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Run every entry's listener work under the re-established actor.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		// DeferredListenerContext::getEntries() is declared
		// `array<int, array<string, mixed>>` upstream, so an `is_array($entry)`
		// guard here is provably dead — phpstan: "Strict comparison using ===
		// between true and false will always evaluate to false".
		foreach ($context->getEntries() as $entry) {
			$this->runEntry(entry: $entry);
		}
	}//end runDeferred()

	/**
	 * Resolve and run one entry's listener, guarded against re-entry.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DeferredWorkGuard is a process-scoped
	 *  re-entrancy guard: its `$inFlight` map MUST be shared across every listener
	 *  instance in the request, which is exactly what an injected per-instance
	 *  service cannot give. Static is the mechanism, not an accident.
	 */
	private function runEntry(array $entry): void {
		$handler = ($entry['handler'] ?? '');
		if (is_string($handler) === false || isset(self::HANDLERS[$handler]) === false) {
			$this->logger->warning(
				'Pipelinq: deferred listener entry names no known handler',
				['handler' => $handler]
			);
			return;
		}

		try {
			$listener = $this->container->get(self::HANDLERS[$handler]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: deferred listener could not be resolved',
				['handler' => $handler, 'exception' => $e->getMessage()]
			);
			return;
		}

		if (($listener instanceof DeferredObjectWork) === false) {
			$this->logger->warning(
				'Pipelinq: deferred listener does not implement DeferredObjectWork',
				['handler' => $handler]
			);
			return;
		}

		$key = DeferredWorkGuard::key(handler: $handler, uuid: (string)($entry['uuid'] ?? ''));
		if (DeferredWorkGuard::enter(key: $key) === false) {
			// Already on this stack — the write we are about to make has
			// already re-entered us once. Doing it again is the loop.
			return;
		}

		try {
			$listener->runDeferredWork($entry);
		} catch (Throwable $e) {
			// Same blast radius as the inline listeners had: a failure here is
			// logged and dropped, never rethrown into cron.
			$this->logger->warning(
				'Pipelinq: deferred listener work failed',
				[
					'handler' => $handler,
					'uuid' => ($entry['uuid'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
		} finally {
			DeferredWorkGuard::leave(key: $key);
		}
	}//end runEntry()
}//end class
