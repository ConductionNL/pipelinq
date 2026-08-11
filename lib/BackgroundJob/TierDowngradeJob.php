<?php

/**
 * Pipelinq TierDowngradeJob.
 *
 * Daily job that processes scheduled tier downgrades. Iterates accounts whose
 * tierValidUntil has passed, re-evaluates the tier via TierService, and applies
 * a downgrade when warranted (also emits the tier-changed event for notifications).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\TierService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily scheduled tier downgrade processor (loyalty-program REQ-LOY-003).
 */
class TierDowngradeJob extends TimedJob
{
    private const DEFAULT_INTERVAL = 86400;

    /**
     * Constructor.
     *
     * @param ITimeFactory          $time           The time factory.
     * @param IAppConfig            $appConfig      The app configuration.
     * @param ContainerInterface    $container      The DI container.
     * @param LoyaltyAccountService $loyaltyService The account service.
     * @param TierService           $tierService    The tier service.
     * @param LoggerInterface       $logger         The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoyaltyAccountService $loyaltyService,
        private TierService $tierService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(
            seconds: $this->appConfig->getValueInt(
                Application::APP_ID,
                'loyalty.tier_downgrade.poll_interval_seconds',
                self::DEFAULT_INTERVAL
            )
        );
    }//end __construct()

    /**
     * Run the downgrade processor.
     *
     * @param mixed $argument Cron argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $programmes = $this->getActiveProgrammes();
        $now        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

        foreach ($programmes as $programme) {
            $programmeId = $this->extractUuid(object: $programme);
            if ($programmeId === null) {
                continue;
            }

            $accounts = $this->loyaltyService->listAccountsForProgramme(
                programmeId: $programmeId,
                limit: 10000
            );

            foreach ($accounts as $account) {
                $accountId      = (string) $this->extractUuid(object: $account);
                $tierValidUntil = (string) ($account['tierValidUntil'] ?? '');
                if ($accountId === '' || $tierValidUntil === '' || $tierValidUntil > $now) {
                    continue;
                }

                try {
                    $this->tierService->updateTierIfNeeded(accountId: $accountId);
                } catch (Throwable $e) {
                    $this->logger->warning(
                        'Pipelinq: tier downgrade processing failed for account',
                        ['accountId' => $accountId, 'exception' => $e->getMessage()]
                    );
                }
            }
        }//end foreach
    }//end run()

    /**
     * Get active programmes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getActiveProgrammes(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'loyaltyProgramme_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                config: [
                    'filters' => [
                        'status'   => 'actief',
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                    'limit'   => 500,
                ]
            );
        } catch (Throwable $e) {
            return [];
        }

        $rowsToIterate = [];
        if (is_array($rows) === true) {
            $rowsToIterate = $rows;
        }

        $result = [];
        foreach ($rowsToIterate as $row) {
            $arr = $this->rowToArray(row: $row);
            if ($arr !== null) {
                $result[] = $arr;
            }
        }

        return $result;
    }//end getActiveProgrammes()

    /**
     * Normalise an OpenRegister row (array or entity) to a plain array.
     *
     * @param mixed $row Row from ObjectService::findAll().
     *
     * @return array<string, mixed>|null Array representation, or null when unusable.
     */
    private function rowToArray(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialised = $row->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return null;
    }//end rowToArray()

    /**
     * Extract UUID from an OR entity array.
     *
     * @param array<string, mixed> $object The OR entity.
     *
     * @return ?string
     */
    private function extractUuid(array $object): ?string
    {
        $self = $object['@self'] ?? [];
        if (is_array($self) === true && isset($self['id']) === true) {
            return (string) $self['id'];
        }

        return $object['accountId'] ?? $object['programmeId'] ?? $object['uuid'] ?? $object['id'] ?? null;
    }//end extractUuid()
}//end class
