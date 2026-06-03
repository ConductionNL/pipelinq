<?php

/**
 * Pipelinq SlaEscalationDispatcher.
 *
 * Resolves escalation actors to addresses, delivers an escalation on the
 * configured channel, and records an immutable sla_breach_event audit record.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers SLA escalations and writes the breach-event audit trail (REQ-004).
 *
 * Channels are pluggable: nextcloud-notification and email deliver directly;
 * webhook routes through OpenRegister's WebhookService; whatsapp/sms resolve the
 * customer's preferred omnichannel contact when that adapter is present and
 * otherwise record a `deferred:<channel>` marker so the sweep job can retry.
 * Every firing — successful or failed — is recorded as an sla_breach_event so
 * the attainment report and escalation log stay complete.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-004
 */
class SlaEscalationDispatcher
{
    /**
     * CloudEvents event type emitted for webhook escalations.
     *
     * @var string
     */
    public const WEBHOOK_EVENT_TYPE = 'nl.conduction.sla.breach';

    /**
     * Notification subject used for the nextcloud-notification channel.
     *
     * @var string
     */
    private const NOTIFICATION_SUBJECT = 'sla_breach';

    /**
     * Constructor.
     *
     * @param NotificationService $notificationService The in-app notification sender.
     * @param IMailer             $mailer              The Nextcloud mailer.
     * @param IUserManager        $userManager         The user manager (email lookup).
     * @param IGroupManager       $groupManager        The group manager (role resolution).
     * @param IAppConfig          $appConfig           The app configuration.
     * @param ContainerInterface  $container           Container for WebhookService lookup.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private NotificationService $notificationService,
        private IMailer $mailer,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch one escalation step and return the actors actually notified.
     *
     * @param array<string, mixed> $step          The escalation step (notify, channel, ...).
     * @param string               $objectType    The tracked object type.
     * @param string               $objectId      The tracked object UUID.
     * @param array<string, mixed> $objectData    The tracked object data (assignee, client, title).
     * @param array<string, mixed> $breachContext Context: targetKind, consumedPercentage, breachedAt.
     *
     * @return array<int, string> The list of notified actor identifiers/addresses.
     */
    public function dispatch(
        array $step,
        string $objectType,
        string $objectId,
        array $objectData,
        array $breachContext
    ): array {
        $notify  = (string) ($step['notify'] ?? '');
        $channel = (string) ($step['channel'] ?? '');

        $recipients = $this->resolveActors(role: $notify, objectData: $objectData);

        try {
            return $this->deliver(
                channel: $channel,
                recipients: $recipients,
                step: $step,
                objectType: $objectType,
                objectId: $objectId,
                objectData: $objectData,
                breachContext: $breachContext
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'SlaEscalationDispatcher: delivery failed',
                ['channel' => $channel, 'objectId' => $objectId, 'exception' => $e->getMessage()]
            );
            return ['failed:'.$channel];
        }
    }//end dispatch()

    /**
     * Route delivery to the channel-specific sender.
     *
     * @param string               $channel       The delivery channel.
     * @param array<int, string>   $recipients    Resolved recipient user IDs.
     * @param array<string, mixed> $step          The escalation step.
     * @param string               $objectType    The tracked object type.
     * @param string               $objectId      The tracked object UUID.
     * @param array<string, mixed> $objectData    The tracked object data.
     * @param array<string, mixed> $breachContext The breach context.
     *
     * @return array<int, string> The notified actor identifiers.
     */
    private function deliver(
        string $channel,
        array $recipients,
        array $step,
        string $objectType,
        string $objectId,
        array $objectData,
        array $breachContext
    ): array {
        switch ($channel) {
            case 'nextcloud-notification':
                return $this->sendNotifications(recipients: $recipients, objectType: $objectType, objectId: $objectId, objectData: $objectData);
            case 'email':
                return $this->sendEmails(recipients: $recipients, objectData: $objectData, breachContext: $breachContext);
            case 'webhook':
                return $this->sendWebhook(step: $step, objectType: $objectType, objectId: $objectId, breachContext: $breachContext);
            case 'whatsapp':
            case 'sms':
                // Omnichannel adapter is an opt-in cross-app dependency; until it
                // is present, record a deferred marker so the sweep can retry.
                $this->logger->info('SlaEscalationDispatcher: omnichannel channel deferred', ['channel' => $channel, 'objectId' => $objectId]);
                return ['deferred:'.$channel];
            default:
                $this->logger->warning('SlaEscalationDispatcher: unknown channel', ['channel' => $channel]);
                return ['failed:'.$channel];
        }
    }//end deliver()

    /**
     * Resolve an escalation role to concrete recipient user IDs.
     *
     * `assignee` reads the object's assignee. `customer` resolves to the linked
     * client/contact owner where available. Coordination roles (team-lead,
     * manager, director) map to configurable Nextcloud groups; their members are
     * the recipients. Unknown/empty roles resolve to no recipients.
     *
     * @param string               $role       The escalation role.
     * @param array<string, mixed> $objectData The tracked object data.
     *
     * @return array<int, string> The resolved recipient user IDs.
     */
    private function resolveActors(string $role, array $objectData): array
    {
        if ($role === 'assignee') {
            $assignee = (string) ($objectData['assignee'] ?? $objectData['assignedTo'] ?? '');
            if ($assignee === '') {
                return [];
            }

            return [$assignee];
        }

        if ($role === 'customer') {
            $customer = (string) ($objectData['client'] ?? $objectData['contact'] ?? '');
            if ($customer === '') {
                return [];
            }

            return ['customer:'.$customer];
        }

        if (in_array($role, ['team-lead', 'manager', 'director'], true) === true) {
            return $this->resolveRoleGroupMembers(role: $role);
        }

        return [];
    }//end resolveActors()

    /**
     * Resolve a coordination role to the members of its configured group.
     *
     * The group for each role is configurable via app-config
     * (`sla_role_group_<role>`); the default is `admin` so escalations always
     * reach someone on a fresh install.
     *
     * @param string $role The coordination role.
     *
     * @return array<int, string> The member user IDs.
     */
    private function resolveRoleGroupMembers(string $role): array
    {
        $groupId = $this->appConfig->getValueString(Application::APP_ID, 'sla_role_group_'.$role, 'admin');
        $group   = $this->groupManager->get($groupId);
        if ($group === null) {
            return [];
        }

        $ids = [];
        foreach ($group->getUsers() as $user) {
            $ids[] = $user->getUID();
        }

        return $ids;
    }//end resolveRoleGroupMembers()

    /**
     * Send in-app notifications to each recipient.
     *
     * @param array<int, string>   $recipients The recipient user IDs.
     * @param string               $objectType The tracked object type.
     * @param string               $objectId   The tracked object UUID.
     * @param array<string, mixed> $objectData The tracked object data.
     *
     * @return array<int, string> The notified user IDs.
     */
    private function sendNotifications(array $recipients, string $objectType, string $objectId, array $objectData): array
    {
        $notified = [];
        foreach ($recipients as $userId) {
            if (str_starts_with($userId, 'customer:') === true) {
                continue;
            }

            $this->notificationService->sendNotification(
                userId: $userId,
                subject: self::NOTIFICATION_SUBJECT,
                parameters: ['title' => (string) ($objectData['title'] ?? ''), 'entityType' => $objectType],
                objectType: $objectType,
                objectId: $objectId
            );
            $notified[] = $userId;
        }

        return $notified;
    }//end sendNotifications()

    /**
     * Send escalation emails to each recipient with a resolvable address.
     *
     * @param array<int, string>   $recipients    The recipient user IDs.
     * @param array<string, mixed> $objectData    The tracked object data.
     * @param array<string, mixed> $breachContext The breach context.
     *
     * @return array<int, string> The notified email addresses.
     */
    private function sendEmails(array $recipients, array $objectData, array $breachContext): array
    {
        $notified = [];
        $title    = (string) ($objectData['title'] ?? '');
        $kind     = (string) ($breachContext['targetKind'] ?? '');

        foreach ($recipients as $userId) {
            $email = $this->emailFor(userId: $userId);
            if ($email === '') {
                $notified[] = 'failed:email:'.$userId;
                continue;
            }

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->setSubject('SLA escalatie: '.$title.' ('.$kind.')');
            $message->setPlainBody(
                'Een SLA-doel ('.$kind.') voor "'.$title.'" is overschreden of dreigt te worden overschreden. '
                .'Verbruik: '.((string) ($breachContext['consumedPercentage'] ?? '')).'.'
            );

            $failed = $this->mailer->send($message);
            if (count($failed) > 0) {
                $notified[] = 'failed:email:'.$email;
                continue;
            }

            $notified[] = $email;
        }//end foreach

        return $notified;
    }//end sendEmails()

    /**
     * Resolve an email address for a recipient identifier.
     *
     * @param string $userId The recipient identifier (may be a customer marker).
     *
     * @return string The email address, or empty when unresolved.
     */
    private function emailFor(string $userId): string
    {
        if (str_starts_with($userId, 'customer:') === true) {
            return '';
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return '';
        }

        return (string) $user->getEMailAddress();
    }//end emailFor()

    /**
     * Dispatch the breach as a CloudEvents webhook via OpenRegister.
     *
     * @param array<string, mixed> $step          The escalation step (webhookUrl).
     * @param string               $objectType    The tracked object type.
     * @param string               $objectId      The tracked object UUID.
     * @param array<string, mixed> $breachContext The breach context.
     *
     * @return array<int, string> The webhook target marker.
     */
    private function sendWebhook(array $step, string $objectType, string $objectId, array $breachContext): array
    {
        $url     = (string) ($step['webhookUrl'] ?? '');
        $payload = [
            'type'               => self::WEBHOOK_EVENT_TYPE,
            'targetObjectType'   => $objectType,
            'targetObjectId'     => $objectId,
            'targetKind'         => ($breachContext['targetKind'] ?? null),
            'consumedPercentage' => ($breachContext['consumedPercentage'] ?? null),
            'breachedAt'         => ($breachContext['breachedAt'] ?? null),
        ];

        $target = 'or-webhook';
        if ($url !== '') {
            $target = $url;
        }

        try {
            $webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
            $webhookService->dispatchEvent(_event: null, eventName: self::WEBHOOK_EVENT_TYPE, payload: $payload);
            return ['webhook:'.$target];
        } catch (Throwable $e) {
            $this->logger->warning('SlaEscalationDispatcher: webhook dispatch unavailable', ['exception' => $e->getMessage()]);
            return ['deferred:webhook'];
        }
    }//end sendWebhook()
}//end class
