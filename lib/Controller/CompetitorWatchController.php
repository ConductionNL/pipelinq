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
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\Contracts\CompetitorWatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ConnectorEgressInterface;
use OCA\Pipelinq\Kernel\Contracts\WatchEventStoreInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Watch events, and a manual run.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
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
	 * @param CompetitorWatchServiceInterface $watches The watch service.
	 * @param WatchEventStoreInterface $events The event store.
	 * @param ConnectorEgressInterface $egress Whether an egress source is configured at all.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CompetitorWatchServiceInterface $watches,
		private readonly WatchEventStoreInterface $events,
		private readonly ConnectorEgressInterface $egress,
		private readonly CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What the watches saw, newest first, with the watches and competitors
	 * the page needs to render it.
	 *
	 * @return JSONResponse `{configured, reason, competitors[], watches[], events[]}`.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$configured = $this->egress->isConfigured(configKey: CompetitorWatchServiceInterface::SOURCE_KEY);
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
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	#[NoAdminRequired]
	public function run(string $id): JSONResponse {
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$watch = $this->watches->watch(id: $id);
		if ($watch === null) {
			return new JSONResponse(['error' => 'No such watch'], Http::STATUS_NOT_FOUND);
		}

		$uid = $this->privilege->actingUserId();

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
}//end class
