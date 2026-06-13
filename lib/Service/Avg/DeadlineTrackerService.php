<?php

/**
 * Pipelinq DeadlineTrackerService.
 *
 * Orchestrates the deadline-monitoring jobs over the AVG request set: the 7-day
 * advance reminder, the <72h team-lead escalation, and the breach detection that
 * stamps the request, records the termijn-overschreden TermijnEvent and alerts
 * the FG. Each milestone is idempotent (guarded by an existing TermijnEvent) so
 * repeated job runs never double-notify (REQ-AVG-002).
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Deadline tracking orchestration for the AVG workflow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the collaborators
 *  the deadline jobs legitimately need (repository, deadline math, events,
 *  notifications, logger).
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
 */
class DeadlineTrackerService
{
    /**
     * The statuses still considered "open" for deadline purposes.
     *
     * @var string[]
     */
    private const OPEN_STATUSES = [
        'ingediend',
        'in-behandeling',
        'bewijs-verzamelen',
        'redactie',
        'bundle-genereren',
        'wachten-op-verzoeker',
        'weigering-opgesteld',
    ];

    /**
     * Constructor.
     *
     * @param AvgRepository          $repository    The AVG OR repository.
     * @param DeadlineService        $deadline      The deadline math service.
     * @param AvgEventService        $events        The TermijnEvent recorder.
     * @param AvgNotificationService $notifications The notification service.
     * @param LoggerInterface        $logger        The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private DeadlineService $deadline,
        private AvgEventService $events,
        private AvgNotificationService $notifications,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send the 7-day advance reminder for every open request due in 7 days.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of reminders sent.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function sendReminders(DateTimeInterface $now): int
    {
        $sent = 0;
        foreach ($this->openRequests() as $request) {
            $deadline = $this->deadlineOf(request: $request);
            if ($deadline === null) {
                continue;
            }

            $id = $this->repository->idOf($request);
            if ($this->deadline->isReminderDue(deadline: $deadline, now: $now) === false
                || $this->events->hasEvent(verzoekId: $id, type: 'herinnering-7dagen') === true
            ) {
                continue;
            }

            $this->events->record(
                verzoekId: $id,
                type: 'herinnering-7dagen',
                deadline: $deadline->format(DateTimeInterface::ATOM),
                details: '7-dagen herinnering aan behandelaar verstuurd.'
            );
            $this->notifications->notifyDeadline(
                userId: (string) ($request['behandelaar'] ?? ''),
                verzoekId: $id,
                daysRemaining: DeadlineService::REMINDER_DAYS
            );
            $sent++;
        }//end foreach

        return $sent;
    }//end sendReminders()

    /**
     * Escalate every open request within the escalation window (<72h) once.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of requests escalated.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function checkEscalations(DateTimeInterface $now): int
    {
        $escalated = 0;
        foreach ($this->openRequests() as $request) {
            $deadline = $this->deadlineOf(request: $request);
            if ($deadline === null) {
                continue;
            }

            $id = $this->repository->idOf($request);
            if ($this->deadline->isBreached(deadline: $deadline, now: $now) === true) {
                continue;
            }

            if ($this->deadline->shouldEscalate(deadline: $deadline, now: $now) === false
                || $this->events->hasEvent(verzoekId: $id, type: 'escalatie-3dagen') === true
            ) {
                continue;
            }

            $this->events->record(
                verzoekId: $id,
                type: 'escalatie-3dagen',
                deadline: $deadline->format(DateTimeInterface::ATOM),
                details: 'Minder dan 72 uur resterend; teamlead in cc.'
            );
            $this->notifications->notifyEscalation(
                userId: (string) ($request['behandelaar'] ?? ''),
                verzoekId: $id
            );
            $escalated++;
        }//end foreach

        return $escalated;
    }//end checkEscalations()

    /**
     * Detect and record breaches for every open request past its deadline.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of breaches newly recorded.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function checkBreaches(DateTimeInterface $now): int
    {
        $breached = 0;
        foreach ($this->openRequests() as $request) {
            $deadline = $this->deadlineOf(request: $request);
            if ($deadline === null) {
                continue;
            }

            $id = $this->repository->idOf($request);
            if ($this->deadline->isBreached(deadline: $deadline, now: $now) === false
                || $this->events->hasEvent(verzoekId: $id, type: 'termijn-overschreden') === true
            ) {
                continue;
            }

            $request['termijnOverschreden'] = true;
            $request['fgGeinformeerd']      = true;
            $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);

            $this->events->record(
                verzoekId: $id,
                type: 'termijn-overschreden',
                deadline: $deadline->format(DateTimeInterface::ATOM),
                details: 'Wettelijke termijn overschreden; FG genotificeerd.',
                geslaagd: false
            );
            $this->notifications->notifyBreach(
                userId: (string) ($request['behandelaar'] ?? ''),
                verzoekId: $id
            );
            $breached++;
        }//end foreach

        if ($breached > 0) {
            $this->logger->warning('Pipelinq AVG: deadline breaches recorded', ['count' => $breached]);
        }

        return $breached;
    }//end checkBreaches()

    /**
     * The open (not resolved/archived) AVG requests.
     *
     * @return array<int, array<string, mixed>> The open requests.
     */
    private function openRequests(): array
    {
        $all  = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK);
        $open = [];
        foreach ($all as $request) {
            if (in_array((string) ($request['status'] ?? ''), self::OPEN_STATUSES, true) === true) {
                $open[] = $request;
            }
        }

        return $open;
    }//end openRequests()

    /**
     * Parse the legal deadline from a request, or null.
     *
     * @param array<string, mixed> $request The request.
     *
     * @return DateTimeImmutable|null The deadline, or null when invalid.
     */
    private function deadlineOf(array $request): ?DateTimeImmutable
    {
        $value = (string) ($request['wettelijkeTermijnVerloopt'] ?? '');
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }
    }//end deadlineOf()
}//end class
