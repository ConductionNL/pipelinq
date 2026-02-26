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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Activity;

use OCP\Activity\IEvent;

/**
 * Handler for applying activity subject text and rich parameters.
 */
class ProviderSubjectHandler
{
    /**
     * Simple subject map: subject => [parsedKey, richKey].
     *
     * @var array<string, array{string, string}>
     */
    private const SIMPLE_SUBJECTS = [
        'lead_created'    => ['Lead created: %s', 'Lead created: {title}'],
        'lead_assigned'   => ['Lead assigned: %s', 'Lead assigned: {title}'],
        'request_created' => ['Request created: %s', 'Request created: {title}'],
    ];

    /**
     * Apply subject text and rich parameters to the event based on its subject type.
     *
     * @param IEvent $event  The event to modify.
     * @param object $l      The l10n translator.
     * @param array  $params The subject parameters.
     *
     * @return void
     */
    public function applySubjectText(IEvent $event, object $l, array $params): void
    {
        $title      = $params['title'] ?? '';
        $richParams = $this->buildRichParams(
            event: $event,
            title: $title
        );

        $subject = $event->getSubject();

        if (isset(self::SIMPLE_SUBJECTS[$subject]) === true) {
            $this->applySimpleSubject(
                event: $event,
                l: $l,
                parsedKey: self::SIMPLE_SUBJECTS[$subject][0],
                richKey: self::SIMPLE_SUBJECTS[$subject][1],
                title: $title,
                richParams: $richParams
            );
            return;
        }

        if ($subject === 'lead_stage_changed') {
            $this->applyStageChangedSubject(
                event: $event,
                l: $l,
                title: $title,
                stage: ($params['stage'] ?? ''),
                richParams: $richParams
            );
            return;
        }

        if ($subject === 'request_status_changed') {
            $this->applyStatusChangedSubject(
                event: $event,
                l: $l,
                title: $title,
                status: ($params['status'] ?? ''),
                richParams: $richParams
            );
            return;
        }

        if ($subject === 'note_added') {
            $this->applyNoteAddedSubject(
                event: $event,
                l: $l,
                title: $title,
                entityType: ($params['entityType'] ?? 'item'),
                richParams: $richParams
            );
        }
    }//end applySubjectText()

    /**
     * Apply stage changed subject text.
     *
     * @param IEvent $event      The event.
     * @param object $l          The l10n translator.
     * @param string $title      The entity title.
     * @param string $stage      The new stage name.
     * @param array  $richParams The rich parameters.
     *
     * @return void
     */
    private function applyStageChangedSubject(
        IEvent $event,
        object $l,
        string $title,
        string $stage,
        array $richParams,
    ): void {
        $event->setParsedSubject($l->t('Lead %1$s moved to %2$s', [$title, $stage]));
        $event->setRichSubject(
            subject: $l->t('{title} moved to %1$s', [$stage]),
            parameters: $richParams
        );
    }//end applyStageChangedSubject()

    /**
     * Apply status changed subject text.
     *
     * @param IEvent $event      The event.
     * @param object $l          The l10n translator.
     * @param string $title      The entity title.
     * @param string $status     The new status name.
     * @param array  $richParams The rich parameters.
     *
     * @return void
     */
    private function applyStatusChangedSubject(
        IEvent $event,
        object $l,
        string $title,
        string $status,
        array $richParams,
    ): void {
        $event->setParsedSubject($l->t('Request %1$s: %2$s', [$title, $status]));
        $event->setRichSubject(
            subject: $l->t('{title}: %1$s', [$status]),
            parameters: $richParams
        );
    }//end applyStatusChangedSubject()

    /**
     * Apply note added subject text.
     *
     * @param IEvent $event      The event.
     * @param object $l          The l10n translator.
     * @param string $title      The entity title.
     * @param string $entityType The entity type.
     * @param array  $richParams The rich parameters.
     *
     * @return void
     */
    private function applyNoteAddedSubject(
        IEvent $event,
        object $l,
        string $title,
        string $entityType,
        array $richParams,
    ): void {
        $event->setParsedSubject($l->t('New note on %1$s: %2$s', [$entityType, $title]));
        $event->setRichSubject(
            subject: $l->t('New note on %1$s: {title}', [$entityType]),
            parameters: $richParams
        );
    }//end applyNoteAddedSubject()

    /**
     * Build rich parameters for an event.
     *
     * @param IEvent $event The event.
     * @param string $title The entity title.
     *
     * @return array The rich parameters.
     */
    private function buildRichParams(IEvent $event, string $title): array
    {
        return [
            'title' => [
                'type' => 'highlight',
                'id'   => (string) $event->getObjectId(),
                'name' => $title,
            ],
        ];
    }//end buildRichParams()

    /**
     * Apply a simple parsed and rich subject to the event.
     *
     * @param IEvent $event      The event.
     * @param object $l          The l10n translator.
     * @param string $parsedKey  The parsed subject translation key.
     * @param string $richKey    The rich subject translation key.
     * @param string $title      The entity title.
     * @param array  $richParams The rich parameters.
     *
     * @return void
     */
    private function applySimpleSubject(
        IEvent $event,
        object $l,
        string $parsedKey,
        string $richKey,
        string $title,
        array $richParams,
    ): void {
        $event->setParsedSubject($l->t($parsedKey, [$title]));
        $event->setRichSubject(
            subject: $l->t($richKey),
            parameters: $richParams
        );
    }//end applySimpleSubject()
}//end class
