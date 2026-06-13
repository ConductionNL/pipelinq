<?php

/**
 * Pipelinq DenialService.
 *
 * Creation, update and finalization of a Weigering (denial) on an AVG request.
 * Enforces the legal guardrails server-side (REQ-AVG-007): a substantive art. 23
 * motivation, a mandatory AP complaint reference URL before finalization, and
 * immutability once finalized. The denial record is never trusted from the
 * client for these invariants.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Denial (Weigering) business logic.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
 */
class DenialService
{
    /**
     * Minimum length of the art. 23 motivation.
     *
     * @var int
     */
    public const MIN_MOTIVATION = 100;

    /**
     * Valid art. 23 denial grounds.
     *
     * @var string[]
     */
    private const VALID_GROUNDS = [
        'art-23-lid-1-sub-a',
        'art-23-lid-1-sub-b',
        'art-23-lid-1-sub-c',
        'art-23-lid-1-sub-d',
        'art-23-lid-1-sub-e',
        'art-23-lid-1-sub-f',
        'art-23-lid-1-sub-g',
        'art-23-lid-1-sub-h',
        'art-23-lid-1-sub-i',
        'art-23-lid-1-sub-j',
        'art-23-lid-3',
    ];

    /**
     * Constructor.
     *
     * @param AvgRepository    $repository The AVG OR repository.
     * @param AvgAccessService $access     The access-control service.
     * @param LoggerInterface  $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private AvgAccessService $access,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create (or replace the draft of) a denial for a request.
     *
     * Validates the denial scope, ground and motivation length. The AP reference
     * is not yet mandatory at draft stage but is required before finalization.
     *
     * @param string               $verzoekId The parent request UUID.
     * @param array<string, mixed> $input     The denial input.
     * @param string               $userId    The acting handler UID.
     *
     * @return array<string, mixed> The created/updated denial.
     *
     * @throws OCSBadRequestException When validation fails.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    public function createOrUpdate(string $verzoekId, array $input, string $userId): array
    {
        $scope = (string) ($input['weigering'] ?? '');
        if (in_array($scope, ['geheel', 'gedeeltelijk'], true) === false) {
            throw new OCSBadRequestException('Kies of het verzoek geheel of gedeeltelijk wordt geweigerd.');
        }

        $ground = (string) ($input['grond'] ?? '');
        if (in_array($ground, self::VALID_GROUNDS, true) === false) {
            throw new OCSBadRequestException('Kies een geldige AVG art. 23 weigeringsgrond.');
        }

        $motivation = trim((string) ($input['toelichtingAvg23'] ?? ''));
        if (mb_strlen($motivation) < self::MIN_MOTIVATION) {
            throw new OCSBadRequestException(
                'De onderbouwing moet minimaal '.self::MIN_MOTIVATION.' tekens bevatten.'
            );
        }

        $existing = $this->findForRequest(verzoekId: $verzoekId);
        if ($existing !== null && (bool) ($existing['gefinaliseerd'] ?? false) === true) {
            throw new OCSBadRequestException('De weigering is al gefinaliseerd en kan niet worden gewijzigd.');
        }

        $denial = [
            'verzoekId'                  => $verzoekId,
            'weigering'                  => $scope,
            'geweigerdeOnderdelen'       => $this->normalizeParts(parts: $input['geweigerdeOnderdelen'] ?? []),
            'grond'                      => $ground,
            'toelichtingAvg23'           => $motivation,
            'verwijzingAp'               => trim((string) ($input['verwijzingAp'] ?? '')),
            'verwijzingBezwaarProcedure' => (bool) ($input['verwijzingBezwaarProcedure'] ?? false),
            'gefinaliseerd'              => false,
        ];

        $id = null;
        if ($existing !== null) {
            $id = $this->repository->idOf(object: $existing);
        }

        $saved = $this->repository->save(schemaKey: AvgRepository::SCHEMA_WEIGERING, object: $denial, id: $id);

        $this->logger->info(
            'Pipelinq AVG: denial drafted',
            ['verzoekId' => $verzoekId, 'ground' => $ground, 'userId' => $userId]
        );

        return $saved;
    }//end createOrUpdate()

    /**
     * Finalize and sign a denial.
     *
     * Blocks finalization without a non-empty AP complaint reference URL
     * (REQ-AVG-007 sc.3). Only an authorized handler / team lead may finalize.
     * Once finalized the denial is immutable.
     *
     * @param string $verzoekId The parent request UUID.
     * @param string $userId    The acting signer UID.
     *
     * @return array<string, mixed> The finalized denial.
     *
     * @throws OCSNotFoundException   When no denial draft exists.
     * @throws OCSForbiddenException  When the user may not sign.
     * @throws OCSBadRequestException When the AP reference is missing.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    public function finalize(string $verzoekId, string $userId): array
    {
        if ($this->access->isHandler(userId: $userId) === false) {
            throw new OCSForbiddenException('Alleen een AVG-behandelaar mag een weigering ondertekenen.');
        }

        $denial = $this->findForRequest(verzoekId: $verzoekId);
        if ($denial === null) {
            throw new OCSNotFoundException('Er is nog geen weigering opgesteld voor dit verzoek.');
        }

        if ((bool) ($denial['gefinaliseerd'] ?? false) === true) {
            return $denial;
        }

        if (trim((string) ($denial['verwijzingAp'] ?? '')) === '') {
            throw new OCSBadRequestException(
                'Een weigering kan niet worden verstuurd zonder verwijzing naar de Autoriteit '
                .'Persoonsgegevens (klachtprocedure-URL).'
            );
        }

        $denial['gefinaliseerd'] = true;
        $denial['verwijzingBezwaarProcedure'] = true;
        $denial['ondertekendDoor']            = $userId;
        $denial['ondertekendOp'] = $this->now();

        $saved = $this->repository->save(
            schemaKey: AvgRepository::SCHEMA_WEIGERING,
            object: $denial,
            id: $this->repository->idOf($denial)
        );

        $this->logger->info('Pipelinq AVG: denial finalized', ['verzoekId' => $verzoekId, 'userId' => $userId]);

        return $saved;
    }//end finalize()

    /**
     * Fetch the denial for a request, if one exists.
     *
     * @param string $verzoekId The parent request UUID.
     *
     * @return array<string, mixed>|null The denial, or null.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    public function findForRequest(string $verzoekId): ?array
    {
        $denials = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_WEIGERING,
            filters: ['verzoekId' => $verzoekId]
        );

        return ($denials[0] ?? null);
    }//end findForRequest()

    /**
     * Normalise the denied-parts input into a list of trimmed strings.
     *
     * @param mixed $parts The raw parts input.
     *
     * @return array<int, string> The normalised parts.
     */
    private function normalizeParts(mixed $parts): array
    {
        if (is_array($parts) === false) {
            return [];
        }

        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }//end normalizeParts()

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
