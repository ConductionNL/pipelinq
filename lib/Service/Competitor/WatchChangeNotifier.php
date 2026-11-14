<?php

/**
 * Pipelinq WatchChangeNotifier.
 *
 * Tells the owner of a page watch that the watched fragment changed, with a
 * Nextcloud notification that names the competitor and opens the
 * Competitors page.
 *
 * The first read of a page only records what the fragment looks like, so it
 * tells nobody anything: there is no earlier version for it to have changed
 * from.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
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
 * @link https://pipelinq.app
 *
 * @spec openspec/changes/specify-capability-rows/specs/marketing-competitor-watches/spec.md#requirement-the-owner-of-a-watch-is-told-when-the-watched-page-changes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\NotificationService;

/**
 * Sends the owner of a page watch a notification when its fragment changed.
 *
 * @spec openspec/changes/specify-capability-rows/specs/marketing-competitor-watches/spec.md#requirement-the-owner-of-a-watch-is-told-when-the-watched-page-changes
 */
class WatchChangeNotifier {

	/**
	 * The notification subject of a changed page.
	 *
	 * @var string
	 */
	public const SUBJECT = 'competitor_page_changed';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore     $store         Reads the competitor the watch belongs to.
	 * @param NotificationService $notifications Sends the notification.
	 */
	public function __construct(
		private ListObjectStore $store,
		private NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * Notify the watch owner when this run recorded a change of a page that was read before.
	 *
	 * @param array<string, mixed> $watch   The watch as it was before this run.
	 * @param int                  $created How many new watch events this run recorded.
	 *
	 * @return bool True when a notification was sent.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/marketing-competitor-watches/spec.md#requirement-the-owner-of-a-watch-is-told-when-the-watched-page-changes
	 */
	public function notifyChange(array $watch, int $created): bool {
		if ($created < 1 || (string)($watch['kind'] ?? '') !== 'page') {
			return false;
		}

		// A first read has nothing to compare with: it is a baseline, not a change.
		if (trim((string)($watch['fingerprint'] ?? '')) === '') {
			return false;
		}

		$owner = $this->ownerOf(watch: $watch);
		if ($owner === '') {
			return false;
		}

		$competitorId = trim((string)($watch['competitorId'] ?? ''));
		$this->notifications->sendNotification(
			userId: $owner,
			subject: self::SUBJECT,
			parameters: [
				'competitor' => $this->competitorName(competitorId: $competitorId),
				'page' => (string)($watch['target'] ?? ''),
			],
			objectType: 'competitor',
			objectId: $competitorId
		);

		return true;
	}//end notifyChange()

	/**
	 * The user who owns the watch: OpenRegister's object owner.
	 *
	 * @param array<string, mixed> $watch The watch.
	 *
	 * @return string The user id, or '' when the watch has no owner.
	 */
	private function ownerOf(array $watch): string {
		$self = $watch['@self'] ?? [];
		if (is_array($self) === true && trim((string)($self['owner'] ?? '')) !== '') {
			return trim((string)$self['owner']);
		}

		return trim((string)($watch['owner'] ?? ''));
	}//end ownerOf()

	/**
	 * The competitor's name, or its id when it cannot be read.
	 *
	 * @param string $competitorId The competitor id.
	 *
	 * @return string The name.
	 */
	private function competitorName(string $competitorId): string {
		if ($competitorId === '') {
			return '';
		}

		$competitor = $this->store->find(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitor_schema', default: CompetitorWatchService::COMPETITOR_SCHEMA),
			id: $competitorId
		);
		$name = trim((string)($competitor['name'] ?? ''));
		if ($name === '') {
			return $competitorId;
		}

		return $name;
	}//end competitorName()
}//end class
