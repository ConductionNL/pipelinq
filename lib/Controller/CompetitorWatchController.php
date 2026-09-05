<?php

/**
 * Pipelinq CompetitorWatchController.
 *
 * The Competitors page: what the watches saw, and a manual run of one watch.
 *
 * The scheduled run is not here and is not anywhere in this app: ADR-094 gives
 * the firing to an OpenRegister flow. This endpoint is the "run it now" a
 * marketer needs after adding a watch, which is the same thing the occ command
 * offers to an administrator.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Watch events, and a manual run.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */
class CompetitorWatchController extends Controller {

	/**
	 * Events returned to the page, at most.
	 *
	 * @var int
	 */
	public const MAX_EVENTS = 200;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param CompetitorWatchService $watches The watch service.
	 * @param WatchEventStore $events The event store.
	 * @param ConnectorEgress $egress Whether an egress source is configured at all.
	 * @param IUserSession $userSession Session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CompetitorWatchService $watches,
		private readonly WatchEventStore $events,
		private readonly ConnectorEgress $egress,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What the watches saw, newest first, with the watches and competitors
	 * the page needs to render it.
	 *
	 * @return JSONResponse `{configured, reason, competitors[], watches[], events[]}`.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$configured = $this->egress->isConfigured(configKey: CompetitorWatchService::SOURCE_KEY);
		$reason = '';
		if ($configured === false) {
			$reason = 'No egress source is configured for competitor watches, so nothing is read.';
		}

		return new JSONResponse(
			[
				'configured' => $configured,
				'reason' => $reason,
				'competitors' => $this->watches->competitors(),
				'watches' => $this->watches->watches(),
				'events' => $this->newestFirst(events: $this->events->all()),
			]
		);
	}//end index()

	/**
	 * Run one watch now.
	 *
	 * @param string $id The watch id.
	 *
	 * @return JSONResponse The outcome, or the refusal.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	#[NoAdminRequired]
	public function run(string $id): JSONResponse {
		$refusal = $this->refuseUnprivileged();
		if ($refusal !== null) {
			return $refusal;
		}

		$watch = $this->watches->watch(id: $id);
		if ($watch === null) {
			return new JSONResponse(['error' => 'No such watch'], Http::STATUS_NOT_FOUND);
		}

		$user = $this->userSession->getUser();
		$uid = null;
		if ($user !== null) {
			$uid = $user->getUID();
		}

		return new JSONResponse($this->watches->runOne(watch: $watch, actingUserId: $uid));
	}//end run()

	/**
	 * Order events by when they were first seen, newest first, and cap them.
	 *
	 * @param array<int, array<string, mixed>> $events The events.
	 *
	 * @return array<int, array<string, mixed>> The page's events.
	 */
	private function newestFirst(array $events): array {
		usort(
			$events,
			static function (array $a, array $b): int {
				return (string)($b['seenAt'] ?? '') <=> (string)($a['seenAt'] ?? '');
			}
		);

		return array_slice($events, 0, self::MAX_EVENTS);
	}//end newestFirst()

	/**
	 * Refuse a caller without the CRM privilege.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function refuseUnprivileged(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnprivileged()
}//end class
