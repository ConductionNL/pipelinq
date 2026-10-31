<?php

/**
 * Pipelinq EventReminderService.
 *
 * Sends an event's reminder to everyone with a confirmed place
 * (PqEvenement: "Herinnering versturen"). The event is stamped with
 * reminderSentAt first, as the caller: a caller who may not change the
 * event is refused before a single mail goes out.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Event
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
 * @spec openspec/specs/campaign-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Event;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Sends the reminder of one event.
 *
 * @spec openspec/specs/campaign-events/spec.md
 */
class EventReminderService
{

    /**
     * Most registrations read for one event.
     */
    private const LIMIT = 1000;


    /**
     * Constructor.
     *
     * @param ObjectServiceInterface $objectService OpenRegister's object service.
     * @param CallerObjectReader     $reader        Reads the event as the caller.
     * @param IAppConfig             $appConfig     The register and schema ids.
     * @param IMailer                $mailer        Sends the mails.
     * @param IL10N                  $l10n          The mail's language.
     * @param LoggerInterface        $logger        Where a failed mail is named.
     */
    public function __construct(
        private readonly ObjectServiceInterface $objectService,
        private readonly CallerObjectReader $reader,
        private readonly IAppConfig $appConfig,
        private readonly IMailer $mailer,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Send the reminder to every confirmed registration with an e-mail address.
     *
     * @param string $eventId The event.
     *
     * @return array{sent: int, skipped: int} How many mails went out, and how many registrations had no usable address.
     *
     * @throws RuntimeException `event_not_found` when the caller may not read it, `event_not_saved` when the caller may not change it.
     *
     * @spec openspec/specs/campaign-events/spec.md
     */
    public function send(string $eventId): array
    {
        $event = $this->reader->read(id: $eventId, schemaKey: 'campaignEvent_schema');
        if ($event === null) {
            throw new RuntimeException('event_not_found');
        }

        $this->stamp(eventId: $eventId, event: $event);

        $sent    = 0;
        $skipped = 0;
        foreach ($this->confirmedRegistrations(eventId: $eventId) as $registration) {
            if ($this->mail(event: $event, registration: $registration) === true) {
                $sent++;
                continue;
            }

            $skipped++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];

    }//end send()


    /**
     * Write reminderSentAt on the event, as the caller.
     *
     * @param string               $eventId The event.
     * @param array<string, mixed> $event   The event's data.
     *
     * @return void
     *
     * @throws RuntimeException `event_not_saved` when the write is refused.
     */
    private function stamp(string $eventId, array $event): void
    {
        $register = $this->reader->register();
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'campaignEvent_schema', '');
        try {
            $this->objectService->saveObject(
                object: array_merge($event, ['reminderSentAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]),
                register: $register,
                schema: $schema,
                uuid: $eventId,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('event_not_saved: '.$e->getMessage(), 0, $e);
        }

    }//end stamp()


    /**
     * The event's registrations with a confirmed place.
     *
     * @param string $eventId The event.
     *
     * @return list<array<string, mixed>> The registrations.
     */
    private function confirmedRegistrations(string $eventId): array
    {
        $register = $this->reader->register();
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'eventRegistration_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        $rows = $this->objectService->findAll(
            [
                'filters' => ['register' => $register, 'schema' => $schema, 'event' => $eventId, 'status' => 'confirmed'],
                'limit'   => self::LIMIT,
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $data = $row;
            if (($row instanceof \JsonSerializable) === true) {
                $data = $row->jsonSerialize();
            }

            if (is_array($data) === true) {
                $out[] = $data;
            }
        }

        return $out;

    }//end confirmedRegistrations()


    /**
     * Mail one registration.
     *
     * @param array<string, mixed> $event        The event.
     * @param array<string, mixed> $registration The registration.
     *
     * @return bool True when the mail went out.
     */
    private function mail(array $event, array $registration): bool
    {
        $address = trim((string) ($registration['email'] ?? ''));
        if ($address === '' || $this->mailer->validateMailAddress($address) === false) {
            return false;
        }

        $title = (string) ($event['title'] ?? '');
        $when  = $this->when(event: $event);
        $where = trim((string) ($event['venue'] ?? ''));
        $body  = $this->l10n->t('You are registered for %1$s on %2$s at %3$s. We look forward to seeing you.', [$title, $when, $where]);

        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$address]);
            $message->setSubject($this->l10n->t('Reminder: %s', [$title]));
            $message->setPlainBody($body);
            $message->setHtmlBody('<p>'.htmlspecialchars($body, ENT_QUOTES, 'UTF-8').'</p>');
            $this->mailer->send($message);
            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'Pipelinq: an event reminder could not be sent',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return false;
        }

    }//end mail()


    /**
     * The event's start in the user's language, or '' without one.
     *
     * @param array<string, mixed> $event The event.
     *
     * @return string The date and time.
     */
    private function when(array $event): string
    {
        $start = trim((string) ($event['startAt'] ?? ''));
        if ($start === '') {
            return '';
        }

        try {
            return (string) $this->l10n->l('datetime', new DateTimeImmutable($start));
        } catch (Throwable $e) {
            return $start;
        }

    }//end when()


}//end class
