<?php

/**
 * Pipelinq AvgNotificationService.
 *
 * Notification and citizen-correspondence helper for the AVG workflow. Citizen
 * emails (receipt, extension, denial) are returned as DRAFTS for handler
 * approval — the 4-eyes control means nothing is auto-sent to a data subject
 * from this service. Internal alerts to handlers / FG are pushed through
 * Nextcloud's notification framework.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTime;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Notifications and citizen email drafts for the AVG workflow.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
 *
 * Seam 4 (declarative-notification migration) verdict — KEEP IMPERATIVE (GDPR/legal seam):
 * the AVG deadline reminder/escalation/breach notifications cannot move to OpenRegister's
 * scheduled `x-openregister-notifications` because the breach path mutates the request object
 * (`termijnOverschreden`, `fgGeinformeerd`) and every milestone records an immutable
 * `TermijnEvent` audit row that also serves as the dispatch idempotency guard
 * ({@see \OCA\Pipelinq\Service\Avg\DeadlineTrackerService}); OR scheduled notifications do
 * neither. The staged legal escalation chain (7-day -> <72h -> breach) is likewise
 * non-expressible as independent OR rules.
 *
 * @spec openspec/changes/pipelinq-notifications-to-or/tasks.md#task-3.2
 */
class AvgNotificationService
{
    /**
     * Notification subject for a deadline reminder to the handler.
     *
     * @var string
     */
    public const SUBJECT_DEADLINE = 'avg_deadline_reminder';

    /**
     * Notification subject for a team-lead escalation.
     *
     * @var string
     */
    public const SUBJECT_ESCALATION = 'avg_escalation';

    /**
     * Notification subject for a deadline breach / FG alert.
     *
     * @var string
     */
    public const SUBJECT_BREACH = 'avg_breach';

    /**
     * Constructor.
     *
     * @param IManager        $notificationManager The notification manager.
     * @param LoggerInterface $logger              The logger.
     */
    public function __construct(
        private IManager $notificationManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the citizen receipt-confirmation email draft (4-eyes; not sent).
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array{to: string, subject: string, body: string} The email draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function buildReceiptDraft(array $request): array
    {
        $kenmerk  = (string) ($request['kenmerk'] ?? '');
        $deadline = (string) ($request['wettelijkeTermijnVerloopt'] ?? '');

        return [
            'to'      => (string) ($request['verzoekerContact'] ?? ''),
            'subject' => 'Ontvangstbevestiging AVG-verzoek '.$kenmerk,
            'body'    => 'Geachte heer/mevrouw,'."\n\n"
                .'Wij hebben uw AVG-verzoek ontvangen onder kenmerk '.$kenmerk.'. '
                .'Wij beantwoorden uw verzoek uiterlijk op '.$deadline.' (wettelijke termijn). '
                .'U ontvangt bericht zodra uw verzoek is afgehandeld.'."\n\n"
                .'Met vriendelijke groet,'."\n".'Het AVG-team',
        ];
    }//end buildReceiptDraft()

    /**
     * Build the citizen extension-notification email draft (4-eyes; not sent).
     *
     * @param array<string, mixed> $request The request payload.
     * @param string               $reason  The extension justification.
     *
     * @return array{to: string, subject: string, body: string} The email draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function buildExtensionDraft(array $request, string $reason): array
    {
        $kenmerk  = (string) ($request['kenmerk'] ?? '');
        $deadline = (string) ($request['wettelijkeTermijnVerloopt'] ?? '');

        return [
            'to'      => (string) ($request['verzoekerContact'] ?? ''),
            'subject' => 'Verlenging behandeltermijn AVG-verzoek '.$kenmerk,
            'body'    => 'Geachte heer/mevrouw,'."\n\n"
                .'De behandeling van uw AVG-verzoek '.$kenmerk.' vergt meer tijd. '
                .'Op grond van artikel 12 lid 3 AVG verlengen wij de termijn met twee maanden, '
                .'tot uiterlijk '.$deadline.'.'."\n\n"
                .'Reden: '.$reason."\n\n"
                .'Met vriendelijke groet,'."\n".'Het AVG-team',
        ];
    }//end buildExtensionDraft()

    /**
     * Build the citizen denial-letter draft (4-eyes; not sent).
     *
     * Always includes the mandatory AP complaint passage and reference URL.
     *
     * @param array<string, mixed> $request The request payload.
     * @param array<string, mixed> $denial  The Weigering record.
     *
     * @return array{to: string, subject: string, body: string} The email draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function buildDenialDraft(array $request, array $denial): array
    {
        $kenmerk = (string) ($request['kenmerk'] ?? '');
        $apUrl   = (string) ($denial['verwijzingAp'] ?? '');

        return [
            'to'      => (string) ($request['verzoekerContact'] ?? ''),
            'subject' => 'Besluit op uw AVG-verzoek '.$kenmerk,
            'body'    => 'Geachte heer/mevrouw,'."\n\n"
                .'Naar aanleiding van uw AVG-verzoek '.$kenmerk.' delen wij u het volgende mede. '
                .(string) ($denial['toelichtingAvg23'] ?? '')."\n\n"
                .'U kunt een klacht indienen bij de Autoriteit Persoonsgegevens via '.$apUrl.'.'."\n\n"
                .'Met vriendelijke groet,'."\n".'Het AVG-team',
        ];
    }//end buildDenialDraft()

    /**
     * Push a deadline reminder notification to the handler.
     *
     * @param string $userId        The handler UID.
     * @param string $verzoekId     The request UUID.
     * @param int    $daysRemaining Days until the deadline.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function notifyDeadline(string $userId, string $verzoekId, int $daysRemaining): void
    {
        $this->push(
            userId: $userId,
            subject: self::SUBJECT_DEADLINE,
            parameters: ['daysRemaining' => $daysRemaining],
            verzoekId: $verzoekId
        );
    }//end notifyDeadline()

    /**
     * Push an escalation notification to a team lead.
     *
     * @param string $userId    The team-lead UID.
     * @param string $verzoekId The request UUID.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function notifyEscalation(string $userId, string $verzoekId): void
    {
        $this->push(
            userId: $userId,
            subject: self::SUBJECT_ESCALATION,
            parameters: [],
            verzoekId: $verzoekId
        );
    }//end notifyEscalation()

    /**
     * Push a breach notification to the FG.
     *
     * @param string $userId    The FG UID.
     * @param string $verzoekId The request UUID.
     *
     * @return void
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.7
     */
    public function notifyBreach(string $userId, string $verzoekId): void
    {
        $this->push(
            userId: $userId,
            subject: self::SUBJECT_BREACH,
            parameters: [],
            verzoekId: $verzoekId
        );
    }//end notifyBreach()

    /**
     * Push a Nextcloud notification, swallowing transport failures.
     *
     * @param string               $userId     The target UID.
     * @param string               $subject    The notification subject.
     * @param array<string, mixed> $parameters The subject parameters.
     * @param string               $verzoekId  The related request UUID.
     *
     * @return void
     */
    private function push(string $userId, string $subject, array $parameters, string $verzoekId): void
    {
        if ($userId === '') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new DateTime())
                ->setObject(type: 'avg_verzoek', id: $verzoekId)
                ->setSubject(subject: $subject, parameters: $parameters);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq AVG: notification not sent',
                ['subject' => $subject, 'exception' => $e->getMessage()]
            );
        }
    }//end push()
}//end class
