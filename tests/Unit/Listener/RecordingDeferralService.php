<?php

/**
 * Recording double for OpenRegister's ListenerDeferralService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;

/**
 * Captures what a listener queued, so a test can assert on it and then run it.
 *
 * A subclass rather than a PHPUnit mock: the tests here need to BOTH assert the
 * entry's shape AND replay it through `runDeferredWork()`, which is what the
 * background job does. Asserting only that `defer()` was called would prove the
 * listener queued something — never that the queued thing does the work the
 * listener used to do inline. That gap is exactly how a deferral conversion gets
 * declared done while the behaviour is gone.
 *
 * It does NOT extend the deferral service's buffering or enqueue behaviour;
 * those belong to OpenRegister and are tested there.
 */
class RecordingDeferralService extends ListenerDeferralService {

	/**
	 * Entries handed to defer(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $entries = [];

	/**
	 * Job classes handed to defer(), in order.
	 *
	 * @var array<int, string>
	 */
	public array $jobClasses = [];

	/**
	 * Dedupe keys handed to defer(), in order.
	 *
	 * @var array<int, string|null>
	 */
	public array $dedupeKeys = [];

	/**
	 * Deliberately does NOT call parent::__construct().
	 *
	 * `ListenerDeferralService` is one of OpenRegister's CONCRETE classes, and
	 * tests/bootstrap.php only pre-declares three stubs — everything else "falls
	 * through to the real app". So in CI, where openregister IS checked out,
	 * this subclass binds to the REAL service, whose constructor requires five
	 * collaborators (IUserSession, OrganisationService, IJobList, IAppConfig,
	 * LoggerInterface). Without an explicit constructor,
	 * `new RecordingDeferralService()` dies with:
	 *
	 *   ArgumentCountError: Too few arguments to function
	 *   OCA\OpenRegister\Service\Deferral\ListenerDeferralService::__construct(),
	 *   0 passed
	 *
	 * and because tests/bootstrap.php `require_once`s THIS FILE during bootstrap,
	 * the failure lands on every listener test at once. A bare local run only
	 * ever has the constructor-less stub, so it cannot see this.
	 *
	 * The class docblock above already states that this double does not extend
	 * the real buffering/enqueue behaviour; `defer()` is fully overridden and no
	 * parent method is ever called, so the uninitialised parent state is never
	 * read.
	 *
	 * @return void
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Record one deferral instead of enqueueing it.
	 *
	 * @param string $jobClass FQCN of the ActorForwardedJob subclass.
	 * @param array<string, mixed> $entry Entry payload.
	 * @param int $chunkSize Maximum entries per enqueued job.
	 * @param string|null $dedupeKey Optional coalescing key.
	 *
	 * @return void
	 */
	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void {
		$this->jobClasses[] = $jobClass;
		$this->entries[] = $entry;
		$this->dedupeKeys[] = $dedupeKey;
	}//end defer()
}//end class
