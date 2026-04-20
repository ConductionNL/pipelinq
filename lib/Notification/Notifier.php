<?php

/**
 * Pipelinq Notifier.
 *
 * Notifier for preparing Pipelinq notification messages.
 *
 * @category Notification
 * @package  OCA\Pipelinq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Notification;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifier for Pipelinq notifications.
 */
class Notifier implements INotifier
{
    /**
     * Constructor.
     *
     * @param IFactory      $l10nFactory  The l10n factory.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the notifier ID.
     *
     * @return string The notifier ID.
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Get the notifier name.
     *
     * @return string The notifier name.
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('Pipelinq');
    }//end getName()

    /**
     * Prepare a notification for display.
     *
     * @param INotification $notification The notification to prepare.
     * @param string        $languageCode The language code.
     *
     * @return INotification The prepared notification.
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();

        $this->applyNotificationSubject(
            notification: $notification,
            l: $l,
            params: $params
        );

        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
            )
        );
        $baseUrl    = $this->urlGenerator->linkToRouteAbsolute('pipelinq.dashboard.page');
        $objectPath = '#/'.$notification->getObjectType().'s/'.$notification->getObjectId();
        $notification->setLink($baseUrl.$objectPath);

        return $notification;
    }//end prepare()

    /**
     * Apply the subject text to a notification based on its subject type.
     *
     * @param INotification $notification The notification.
     * @param object        $l            The l10n translator.
     * @param array         $params       The subject parameters.
     *
     * @return void
     *
     * @throws UnknownNotificationException If the subject is not recognized.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — switch handles all notification types
     */
    private function applyNotificationSubject(INotification $notification, object $l, array $params): void
    {
        $title      = $params['title'] ?? '';
        $richParams = $this->buildRichParams(
            notification: $notification,
            title: $title
        );

        switch ($notification->getSubject()) {
            case 'lead_assigned':
                $this->applySimpleSubject(
                    notification: $notification,
                    l: $l,
                    parsedKey: 'Lead assigned: %s',
                    richKey: 'Lead assigned: {title}',
                    title: $title,
                    richParams: $richParams
                );
                break;

            case 'request_assigned':
                $this->applySimpleSubject(
                    notification: $notification,
                    l: $l,
                    parsedKey: 'Request assigned: %s',
                    richKey: 'Request assigned: {title}',
                    title: $title,
                    richParams: $richParams
                );
                break;

            case 'task_assigned':
                $this->applySimpleSubject(
                    notification: $notification,
                    l: $l,
                    parsedKey: 'Task assigned: %s',
                    richKey: 'Task assigned: {title}',
                    title: $title,
                    richParams: $richParams
                );
                break;

            case 'task_completed':
                $resultText = $params['resultText'] ?? '';
                $notification->setParsedSubject($l->t('Task completed: %1$s — %2$s', [$title, $resultText]));
                $notification->setRichSubject(
                        subject: $l->t('Task completed: {title}'),
                        parameters: $richParams
                        );
                break;

            case 'task_reassigned':
                $this->applySimpleSubject(
                    notification: $notification,
                    l: $l,
                    parsedKey: 'Task reassigned to you: %s',
                    richKey: 'Task reassigned to you: {title}',
                    title: $title,
                    richParams: $richParams
                );
                break;

            case 'task_expired':
                $deadline = $params['deadline'] ?? '';
                $notification->setParsedSubject($l->t('Task expired: %1$s (deadline: %2$s)', [$title, $deadline]));
                $notification->setRichSubject(
                        subject: $l->t('Task expired: {title}'),
                        parameters: $richParams
                        );
                break;

            case 'lead_stage_changed':
                $stage = $params['stage'] ?? '';
                $notification->setParsedSubject($l->t('Lead %1$s moved to %2$s', [$title, $stage]));
                $notification->setRichSubject(
                        subject: $l->t('{title} moved to %1$s', [$stage]),
                        parameters: $richParams
                        );
                break;

            case 'request_status_changed':
                $status = $params['status'] ?? '';
                $notification->setParsedSubject($l->t('Request %1$s: %2$s', [$title, $status]));
                $notification->setRichSubject(
                        subject: $l->t('{title}: %1$s', [$status]),
                        parameters: $richParams
                        );
                break;

            case 'note_added':
                $entityType = $params['entityType'] ?? 'item';
                $notification->setParsedSubject($l->t('New note on %1$s: %2$s', [$entityType, $title]));
                $notification->setRichSubject(
                        subject: $l->t('New note on %1$s: {title}', [$entityType]),
                        parameters: $richParams
                        );
                break;

            case 'lead_won':
                $value = $params['value'] ?? '';
                $notification->setParsedSubject($l->t('Deal won: %1$s (EUR %2$s)', [$title, $value]));
                $notification->setRichSubject(
                        subject: $l->t('Deal won: {title} (EUR %1$s)', [$value]),
                        parameters: $richParams
                        );
                break;

            case 'lead_lost':
                $this->applySimpleSubject(
                    notification: $notification,
                    l: $l,
                    parsedKey: 'Deal lost: %s',
                    richKey: 'Deal lost: {title}',
                    title: $title,
                    richParams: $richParams
                );
                break;

            default:
                throw new UnknownNotificationException();
        }//end switch
    }//end applyNotificationSubject()

    /**
     * Build rich parameters for a notification.
     *
     * @param INotification $notification The notification.
     * @param string        $title        The entity title.
     *
     * @return array The rich parameters.
     */
    private function buildRichParams(INotification $notification, string $title): array
    {
        return [
            'title' => [
                'type' => 'highlight',
                'id'   => $notification->getObjectId(),
                'name' => $title,
            ],
        ];
    }//end buildRichParams()

    /**
     * Apply a simple parsed and rich subject to a notification.
     *
     * @param INotification $notification The notification.
     * @param object        $l            The l10n translator.
     * @param string        $parsedKey    The parsed subject translation key.
     * @param string        $richKey      The rich subject translation key.
     * @param string        $title        The entity title.
     * @param array         $richParams   The rich parameters.
     *
     * @return void
     */
    private function applySimpleSubject(
        INotification $notification,
        object $l,
        string $parsedKey,
        string $richKey,
        string $title,
        array $richParams,
    ): void {
        $notification->setParsedSubject($l->t($parsedKey, [$title]));
        $notification->setRichSubject(
                subject: $l->t($richKey),
                parameters: $richParams
                );
    }//end applySimpleSubject()
}//end class
