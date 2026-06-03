<?php

/**
 * Pipelinq AvgEventService.
 *
 * Recorder for immutable TermijnEvent audit records on the AVG workflow. All
 * deadline-related milestones (receipt confirmation, 7-day reminder, escalation,
 * breach, extension communicated, collection error) are written here so the
 * timeline is a single append-only audit source.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Append-only TermijnEvent recorder.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#1.2
 */
class AvgEventService
{
    /**
     * Constructor.
     *
     * @param AvgRepository   $repository The AVG OR repository.
     * @param LoggerInterface $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record a TermijnEvent for a request.
     *
     * @param string      $verzoekId   The parent request UUID.
     * @param string      $type        The event type.
     * @param string|null $deadline    The related deadline (ISO 8601), if any.
     * @param string      $details     A human-readable detail string.
     * @param bool        $automatisch Whether an automated job raised the event.
     * @param bool        $geslaagd    Whether the action behind the event succeeded.
     *
     * @return array<string, mixed> The created event.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `automatisch` and `geslaagd`
     *  are persisted audit fields of the TermijnEvent, not control flags.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#1.2
     */
    public function record(
        string $verzoekId,
        string $type,
        ?string $deadline=null,
        string $details='',
        bool $automatisch=true,
        bool $geslaagd=true
    ): array {
        $event = [
            'verzoekId'   => $verzoekId,
            'type'        => $type,
            'tijdstip'    => $this->now(),
            'deadline'    => ($deadline ?? ''),
            'automatisch' => $automatisch,
            'geslaagd'    => $geslaagd,
            'details'     => $details,
        ];

        $saved = $this->repository->save(schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT, object: $event);
        $this->logger->info('Pipelinq AVG: termijn event', ['verzoekId' => $verzoekId, 'type' => $type]);

        return $saved;
    }//end record()

    /**
     * Whether an event of the given type already exists for a request.
     *
     * Used to keep idempotent job-driven events (reminder, escalation, breach)
     * from being recorded more than once.
     *
     * @param string $verzoekId The parent request UUID.
     * @param string $type      The event type.
     *
     * @return bool True when such an event exists.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.5
     */
    public function hasEvent(string $verzoekId, string $type): bool
    {
        $events = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT,
            filters: ['verzoekId' => $verzoekId, 'type' => $type]
        );

        return (count($events) > 0);
    }//end hasEvent()

    /**
     * List the timeline events for a request, newest first.
     *
     * @param string $verzoekId The parent request UUID.
     *
     * @return array<int, array<string, mixed>> The events.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#1.2
     */
    public function timeline(string $verzoekId): array
    {
        $events = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT,
            filters: ['verzoekId' => $verzoekId]
        );

        usort(
            $events,
            static fn (array $a, array $b): int => strcmp(
                (string) ($b['tijdstip'] ?? ''),
                (string) ($a['tijdstip'] ?? '')
            )
        );

        return $events;
    }//end timeline()

    /**
     * The current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
