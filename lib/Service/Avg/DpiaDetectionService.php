<?php

/**
 * Pipelinq DpiaDetectionService.
 *
 * Pattern detection over AVG requests: when many similar requests (same article
 * + scope) arrive inside a rolling 30-day window the matching requests are
 * flagged for DPIA review and the FG is informed (REQ-AVG-010). A flagged
 * request can be linked to a Procest improvement item where that app is wired.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DPIA pattern detection.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the repository, OR
 *  container (optional Procest link), app config and logger a pattern detector
 *  legitimately needs.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
 */
class DpiaDetectionService
{
    /**
     * Default number of similar requests in the window that triggers a flag.
     *
     * @var int
     */
    public const DEFAULT_THRESHOLD = 10;

    /**
     * Rolling analysis window, in days.
     *
     * @var int
     */
    public const WINDOW_DAYS = 30;

    /**
     * Constructor.
     *
     * @param AvgRepository      $repository The AVG OR repository.
     * @param ContainerInterface $container  The DI container (optional Procest).
     * @param IAppConfig         $appConfig  The app config.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Group recent requests by article+scope and return the over-threshold
     * patterns within the rolling window.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return array<int, array{key: string, artikel: string, scope: string, count: int, ids: array<int, string>}>
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
     */
    public function detectPatterns(DateTimeInterface $now): array
    {
        $threshold = $this->threshold();
        $cutoff    = $now->getTimestamp() - (self::WINDOW_DAYS * 86400);
        $requests  = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK);
        $byPattern = [];

        foreach ($requests as $request) {
            $ingediendOp = $this->timestampOf(value: (string) ($request['ingediendOp'] ?? ''));
            if ($ingediendOp === null || $ingediendOp < $cutoff) {
                continue;
            }

            $artikel = (string) ($request['artikel'] ?? '');
            foreach (((array) ($request['scope'] ?? [])) as $scope) {
                $key = $artikel.'|'.(string) $scope;
                $byPattern[$key]['artikel'] = $artikel;
                $byPattern[$key]['scope']   = (string) $scope;
                $byPattern[$key]['ids'][]   = $this->repository->idOf($request);
            }
        }

        $patterns = [];
        foreach ($byPattern as $key => $data) {
            $count = count($data['ids']);
            if ($count >= $threshold) {
                $patterns[] = [
                    'key'     => $key,
                    'artikel' => $data['artikel'],
                    'scope'   => $data['scope'],
                    'count'   => $count,
                    'ids'     => array_values(array_unique($data['ids'])),
                ];
            }
        }

        return $patterns;
    }//end detectPatterns()

    /**
     * Run the analysis and flag every request in an over-threshold pattern.
     *
     * @param DateTimeInterface $now The reference time.
     *
     * @return int The number of requests newly flagged.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
     */
    public function analyzeAndFlag(DateTimeInterface $now): int
    {
        $flagged = 0;
        foreach ($this->detectPatterns(now: $now) as $pattern) {
            foreach ($pattern['ids'] as $id) {
                $request = $this->repository->findOrNull(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id);
                if ($request === null || (bool) ($request['dpiaFlag'] ?? false) === true) {
                    continue;
                }

                $request['dpiaFlag']       = true;
                $request['fgGeinformeerd'] = true;
                $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);
                $flagged++;
            }

            $this->logger->info(
                'Pipelinq AVG: DPIA pattern flagged',
                ['pattern' => $pattern['key'], 'count' => $pattern['count']]
            );
        }

        return $flagged;
    }//end analyzeAndFlag()

    /**
     * Best-effort creation of a linked Procest improvement item for a pattern.
     *
     * No-op when Procest is not installed (its consumer is an optional cross-app
     * integration); never throws.
     *
     * @param array{key: string, artikel: string, scope: string, count: int} $pattern The pattern.
     *
     * @return bool Whether a Procest item was created.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
     */
    public function linkToProcest(array $pattern): bool
    {
        if ($this->appConfig->getValueString(Application::APP_ID, 'avg_dpia_auto_procest', 'no') !== 'yes') {
            return false;
        }

        try {
            if ($this->container->has('OCA\Procest\Service\ImprovementService') === false) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        // Procest improvement-item creation is an optional cross-app dependency;
        // the wiring lands when Procest exposes its improvement API. The pattern
        // is logged so the FG can act on it until then — a safe no-op that keeps
        // DPIA flagging independent of Procest.
        $this->logger->info(
            'Pipelinq AVG: DPIA pattern eligible for a Procest improvement item',
            ['pattern' => (string) $pattern['key'], 'count' => (int) $pattern['count']]
        );

        return false;
    }//end linkToProcest()

    /**
     * The configured DPIA threshold.
     *
     * @return int The threshold.
     */
    private function threshold(): int
    {
        return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'avg_dpia_threshold', self::DEFAULT_THRESHOLD));
    }//end threshold()

    /**
     * Parse an ISO 8601 timestamp to an epoch, or null.
     *
     * @param string $value The timestamp.
     *
     * @return int|null The epoch seconds, or null.
     */
    private function timestampOf(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }//end timestampOf()
}//end class
