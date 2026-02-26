<?php

/**
 * Pipelinq Activity Provider.
 *
 * Provider for parsing and rendering Pipelinq activity events.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Activity provider for parsing Pipelinq events.
 */
class Provider implements IProvider
{
    /**
     * Subjects that are handled by this provider.
     *
     * @var string[]
     */
    private const HANDLED_SUBJECTS = [
        'lead_created',
        'lead_assigned',
        'lead_stage_changed',
        'request_created',
        'request_status_changed',
        'note_added',
    ];

    /**
     * Constructor.
     *
     * @param IFactory               $l10nFactory    The l10n factory.
     * @param IURLGenerator          $urlGenerator   The URL generator.
     * @param ProviderSubjectHandler $subjectHandler The subject handler.
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private ProviderSubjectHandler $subjectHandler,
    ) {
    }//end __construct()

    /**
     * Parse an activity event into a human-readable format.
     *
     * @param string  $language      The language code.
     * @param IEvent  $event         The event to parse.
     * @param ?IEvent $previousEvent The previous event or null.
     *
     * @return IEvent The parsed event.
     */
    public function parse($language, IEvent $event, ?IEvent $previousEvent=null): IEvent
    {
        if ($event->getApp() !== Application::APP_ID) {
            throw new UnknownActivityException();
        }

        if (in_array($event->getSubject(), self::HANDLED_SUBJECTS, true) === false) {
            throw new UnknownActivityException();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $language);
        $params = $event->getSubjectParameters();

        $this->subjectHandler->applySubjectText(
            event: $event,
            l: $l,
            params: $params
        );

        $event->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(app: Application::APP_ID, image: 'app.svg')
            )
        );

        return $event;
    }//end parse()
}//end class
