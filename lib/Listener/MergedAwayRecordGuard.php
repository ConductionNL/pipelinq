<?php

/**
 * Pipelinq MergedAwayRecordGuard.
 *
 * A record that was merged into another one is read-only: an update that
 * changes anything but the merge stamp is refused. Undoing the merge clears
 * the stamp, which is allowed.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md#requirement-clients-and-contacts-merge-through-openregisters-reversible-merge-req-crm-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Service\Merge\MergedAwayStamp;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;

/**
 * Refuses a change to a merged-away record.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md#requirement-clients-and-contacts-merge-through-openregisters-reversible-merge-req-crm-001
 */
class MergedAwayRecordGuard implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the refusal.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Handle an object-updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md#requirement-clients-and-contacts-merge-through-openregisters-reversible-merge-req-crm-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false || $this->refuses(event: $event) === false) {
			return;
		}

		$event->setErrors(['message' => $this->l10n->t('This record was merged into another record and can no longer be changed.')]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the update changes a merged-away record beyond its stamp.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 *
	 * @return bool True when the update is refused.
	 */
	private function refuses(ObjectUpdatingEvent $event): bool {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$new = array_merge($event->getNewObject()->getObject(), $event->getModifiedData());
		if (self::isStamped(data: $old) === false || self::isStamped(data: $new) === false) {
			return false;
		}

		$stamp = array_flip(MergedAwayStamp::FIELDS);
		return array_diff_key($old, $stamp) != array_diff_key($new, $stamp);
	}//end refuses()

	/**
	 * Whether data carries a merge stamp.
	 *
	 * @param array<string, mixed> $data The record data.
	 *
	 * @return bool True when it names the record it was merged into.
	 */
	private static function isStamped(array $data): bool {
		return trim((string)($data['mergedInto'] ?? '')) !== '';
	}//end isStamped()
}//end class
