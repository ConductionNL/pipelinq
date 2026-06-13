<?php

/**
 * Pipelinq ExtensionService.
 *
 * The 60-day extension path for AVG requests (AVG art. 12 lid 3). Enforces the
 * legal rules server-side (REQ-AVG-003): an extension may only be granted on or
 * before the original deadline, only once, and only with a substantive
 * justification; the new deadline is recomputed from the intake date, never
 * trusted from the client.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * 60-day extension business logic.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.6
 */
class ExtensionService
{
    /**
     * Minimum length of the extension justification.
     *
     * @var int
     */
    public const MIN_JUSTIFICATION = 30;

    /**
     * Constructor.
     *
     * @param AvgRepository   $repository The AVG OR repository.
     * @param DeadlineService $deadline   The deadline computation service.
     * @param AvgEventService $events     The TermijnEvent recorder.
     * @param LoggerInterface $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private DeadlineService $deadline,
        private AvgEventService $events,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Grant a 60-day extension to a request.
     *
     * @param array<string, mixed> $request       The request payload.
     * @param string               $justification The extension justification.
     * @param DateTimeInterface    $now           The reference time.
     *
     * @return array<string, mixed> The updated request.
     *
     * @throws OCSBadRequestException When the extension is not permitted.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.6
     */
    public function extend(array $request, string $justification, DateTimeInterface $now): array
    {
        $reason = trim($justification);
        if (mb_strlen($reason) < self::MIN_JUSTIFICATION) {
            throw new OCSBadRequestException(
                'Vul een onderbouwing in van minimaal '.self::MIN_JUSTIFICATION.' tekens '
                .'(complexiteit / aantal verzoeken).'
            );
        }

        if ((int) ($request['verlengdMet'] ?? 0) > 0) {
            throw new OCSBadRequestException(
                'Het verzoek is al verlengd. Een tweede verlenging is niet toegestaan; '
                .'overweeg overdracht naar de juridische afdeling.'
            );
        }

        $currentDeadline = $this->parseDeadline(value: (string) ($request['wettelijkeTermijnVerloopt'] ?? ''));
        if ($currentDeadline === null) {
            throw new OCSBadRequestException('Het verzoek heeft geen geldige wettelijke termijn.');
        }

        if ($this->deadline->isBreached(deadline: $currentDeadline, now: $now) === true) {
            throw new OCSBadRequestException(
                'Verlenging moet uiterlijk op dag 30 worden gecommuniceerd (AVG art. 12 lid 3).'
            );
        }

        $intake = $this->parseDeadline(value: (string) ($request['ingediendOp'] ?? ''));
        if ($intake === null) {
            $intake = $now;
        }

        $newDeadline = $this->deadline->computeDeadline(
            submittedAt: $intake,
            extensionDays: DeadlineService::EXTENSION_DAYS
        );

        $request['verlengdMet']      = DeadlineService::EXTENSION_DAYS;
        $request['verlengingsgrond'] = $reason;
        $request['wettelijkeTermijnVerloopt'] = $newDeadline->format(DateTimeInterface::ATOM);
        $request['termijnOverschreden']       = false;

        $id    = $this->repository->idOf($request);
        $saved = $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);

        $this->events->record(
            verzoekId: $id,
            type: 'verlenging-gecommuniceerd',
            deadline: $newDeadline->format(DateTimeInterface::ATOM),
            details: 'Termijn met 60 dagen verlengd. Grond: '.$reason
        );

        $this->logger->info('Pipelinq AVG: extension granted', ['id' => $id]);

        return $saved;
    }//end extend()

    /**
     * Parse an ISO 8601 date string, returning null on failure.
     *
     * @param string $value The date string.
     *
     * @return DateTimeImmutable|null The parsed date or null.
     */
    private function parseDeadline(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }
    }//end parseDeadline()
}//end class
