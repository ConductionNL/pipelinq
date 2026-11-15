<?php

/**
 * Pipelinq ProviderSubjectHandler.
 *
 * Handler for applying activity subject text and rich parameters to events.
 *
 * @category Activity
 * @package  OCA\Pipelinq\Activity
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Activity;

use OCP\Activity\IEvent;

/**
 * Handler for applying activity subject text and rich parameters.
 *
 * @spec openspec/specs/activity-stream-publishing/spec.md#requirement-activity-stream-publishing-uses-the-nextcloud-first-party-activity-api
 */
class ProviderSubjectHandler {
	/**
	 * Apply subject text and rich parameters to the event based on its subject type.
	 *
	 * An unknown subject leaves the event as it is.
	 *
	 * @param IEvent $event The event to modify.
	 * @param object $l The l10n translator.
	 * @param array $params The subject parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function applySubjectText(IEvent $event, object $l, array $params): void {
		$title = $params['title'] ?? '';
		$richParams = $this->buildRichParams(event: $event, title: $title);

		$texts = $this->subjectTexts(subject: $event->getSubject(), context: ['l' => $l, 'params' => $params, 'title' => $title]);
		if ($texts === null) {
			return;
		}

		$event->setParsedSubject($texts[0]);
		$event->setRichSubject(subject: $texts[1], parameters: $richParams);
	}//end applySubjectText()

	/**
	 * The parsed and the rich subject of a known subject, or null.
	 *
	 * @param string $subject The event subject.
	 * @param array<string, mixed> $context The translator (`l`), the subject parameters and the title.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function subjectTexts(string $subject, array $context): ?array {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		return match ($subject) {
			'lead_created' => [$l->t('Lead created: %s', [$title]), $l->t('Lead created: {title}')],
			'lead_assigned' => [$l->t('Lead assigned: %s', [$title]), $l->t('Lead assigned: {title}')],
			'request_created' => [$l->t('Request created: %s', [$title]), $l->t('Request created: {title}')],
			'lead_lost' => [$l->t('Deal lost: %s', [$title]), $l->t('Deal lost: {title}')],
			'lead_stage_changed' => $this->changeTexts(subject: $subject, context: $context),
			'request_status_changed' => $this->changeTexts(subject: $subject, context: $context),
			'note_added' => $this->changeTexts(subject: $subject, context: $context),
			'lead_won' => [
				$l->t('Deal won: %1$s (EUR %2$s)', [$title, $params['value'] ?? '']),
				$l->t('Deal won: {title} (EUR %1$s)', [$params['value'] ?? '']),
			],
			default => null,
		};
	}//end subjectTexts()

	/**
	 * The texts of a stage change, a status change or a new note; the stage,
	 * status and entity type must be strings.
	 *
	 * @param string $subject The event subject.
	 * @param array<string, mixed> $context The translator (`l`), the subject parameters and the title.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function changeTexts(string $subject, array $context): array {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		if ($subject === 'lead_stage_changed') {
			$stage = $this->stringParam(params: $params, key: 'stage');
			return [$l->t('Lead %1$s moved to %2$s', [$title, $stage]), $l->t('{title} moved to %1$s', [$stage])];
		}

		if ($subject === 'request_status_changed') {
			$status = $this->stringParam(params: $params, key: 'status');
			return [$l->t('Request %1$s: %2$s', [$title, $status]), $l->t('{title}: %1$s', [$status])];
		}

		$entityType = $this->stringParam(params: ($params + ['entityType' => 'item']), key: 'entityType');
		return [$l->t('New note on %1$s: %2$s', [$entityType, $title]), $l->t('New note on %1$s: {title}', [$entityType])];
	}//end changeTexts()

	/**
	 * A subject parameter that must be a string ('' when absent); any other
	 * type fails the return type.
	 *
	 * @param array<string, mixed> $params The subject parameters.
	 * @param string $key The parameter name.
	 *
	 * @return string
	 */
	private function stringParam(array $params, string $key): string {
		return $params[$key] ?? '';
	}//end stringParam()

	/**
	 * Build rich parameters for an event.
	 *
	 * @param IEvent $event The event.
	 * @param string $title The entity title.
	 *
	 * @return array The rich parameters.
	 */
	private function buildRichParams(IEvent $event, string $title): array {
		return [
			'title' => [
				'type' => 'highlight',
				'id' => (string)$event->getObjectId(),
				'name' => $title,
			],
		];
	}//end buildRichParams()
}//end class
