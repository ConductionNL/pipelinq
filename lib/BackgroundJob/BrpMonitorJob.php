<?php

/**
 * Pipelinq BrpMonitorJob.
 *
 * Daily aggregator that scans the BsnAuditRecord schema for the last 24 hours and
 * computes lookup counts, cache-hit ratio, average response time, and error rate.
 * Result is cached in app-config for the admin BRP-Monitor tile.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BRP availability + SLA monitor.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-010
 */
class BrpMonitorJob extends TimedJob
{
    /**
     * Default interval (24h).
     */
    private const DEFAULT_INTERVAL_SECONDS = 86400;

    /**
     * Default error-rate alert threshold (10% of attempts).
     */
    private const ERROR_RATE_ALERT_THRESHOLD = 0.10;

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                Time factory.
     * @param IAppConfig           $appConfig           App config.
     * @param ContainerInterface   $container           DI (OR lookup).
     * @param IGroupManager        $groupManager        Group manager.
     * @param INotificationManager $notificationManager NC notifications.
     * @param LoggerInterface      $logger              Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(
            seconds: $this->appConfig->getValueInt(
                Application::APP_ID,
                'brp.monitor_interval_seconds',
                self::DEFAULT_INTERVAL_SECONDS
            )
        );
    }//end __construct()

    /**
     * Aggregate the last 24h of BSN audit records.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        try {
            $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $schema   = $this->appConfig->getValueString(Application::APP_ID, 'bsnAuditRecord_schema', '');
            if ($register === '' || $schema === '') {
                $this->logger->info('BRP monitor: audit schema not configured; skipping');
                return;
            }

            $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $window = $now->modify('-24 hours');

            $records = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                filters: ['actie' => 'brp-lookup-uitgevoerd'],
                register: $register,
                schema: $schema,
            );

            $total     = 0;
            $errors    = 0;
            $hits      = 0;
            $cacheHits = 0;
            $durations = [];
            foreach (($records ?? []) as $rec) {
                if (is_array($rec) === true) {
                    $arr = $rec;
                } else if (method_exists($rec, 'jsonSerialize') === true) {
                    $arr = (array) $rec->jsonSerialize();
                } else {
                    $arr = [];
                }

                $tijdstip = (string) ($arr['tijdstip'] ?? '');
                if ($tijdstip === '') {
                    continue;
                }

                try {
                    $ts = new DateTimeImmutable($tijdstip);
                } catch (Throwable $e) {
                    continue;
                }

                if ($ts < $window) {
                    continue;
                }

                $total++;
                $uitkomst = (string) ($arr['uitkomst'] ?? '');
                if ($uitkomst === 'fout' || $uitkomst === 'timeout') {
                    $errors++;
                }

                if ($uitkomst === 'geslaagd') {
                    $hits++;
                }
            }//end foreach

            // Cache-hit ratio is sourced from brpLookupVerzoek.responseInCache (more accurate).
            $verzoekSchema = $this->appConfig->getValueString(Application::APP_ID, 'brpLookupVerzoek_schema', '');
            $totalVerzoek  = 0;
            if ($verzoekSchema !== '') {
                $verzoeken = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                    filters: [],
                    register: $register,
                    schema: $verzoekSchema,
                );
                foreach (($verzoeken ?? []) as $rec) {
                    if (is_array($rec) === true) {
                        $arr = $rec;
                    } else if (method_exists($rec, 'jsonSerialize') === true) {
                        $arr = (array) $rec->jsonSerialize();
                    } else {
                        $arr = [];
                    }

                    $tijdstip = (string) ($arr['verzoekTijdstip'] ?? '');
                    try {
                        $ts = new DateTimeImmutable($tijdstip);
                    } catch (Throwable $e) {
                        continue;
                    }

                    if ($ts < $window) {
                        continue;
                    }

                    $totalVerzoek++;
                    if (($arr['responseInCache'] ?? false) === true) {
                        $cacheHits++;
                    }

                    if (isset($arr['responseDuurMs']) === true) {
                        $durations[] = (int) $arr['responseDuurMs'];
                    }
                }//end foreach
            }//end if

            $errorRate = 0.0;
            if ($total > 0) {
                $errorRate = round($errors / $total, 4);
            }

            $cacheHitRatio = 0.0;
            if ($totalVerzoek > 0) {
                $cacheHitRatio = round($cacheHits / $totalVerzoek, 4);
            }

            $avgResponseMs = 0;
            if (count($durations) > 0) {
                $avgResponseMs = (int) round(array_sum($durations) / count($durations));
            }

            $report = [
                'windowStart'       => $window->format(DATE_ATOM),
                'windowEnd'         => $now->format(DATE_ATOM),
                'totalLookups'      => $total,
                'successfulLookups' => $hits,
                'errorCount'        => $errors,
                'errorRate'         => $errorRate,
                'cacheHits'         => $cacheHits,
                'cacheHitRatio'     => $cacheHitRatio,
                'avgResponseMs'     => $avgResponseMs,
                'generatedAt'       => $now->format(DATE_ATOM),
            ];

            $this->appConfig->setValueString(
                Application::APP_ID,
                'brp.monitor_report',
                json_encode($report, JSON_THROW_ON_ERROR)
            );

            if ($report['errorRate'] >= self::ERROR_RATE_ALERT_THRESHOLD && $total > 0) {
                $this->notifyAdmins(report: $report);
            }
        } catch (Throwable $e) {
            $this->logger->error('BRP monitor job failed', ['error' => $e->getMessage()]);
        }//end try
    }//end run()

    /**
     * Send an admin notification when the error rate breaches the alert threshold.
     *
     * @param array<string,mixed> $report Aggregated monitor report.
     *
     * @return void
     */
    private function notifyAdmins(array $report): void
    {
        $admins = $this->groupManager->get('admin');
        if ($admins === null) {
            return;
        }

        foreach ($admins->getUsers() as $admin) {
            try {
                $n = $this->notificationManager->createNotification();
                $n->setApp(Application::APP_ID)
                    ->setUser($admin->getUID())
                    ->setObject('brp-monitor', 'error-rate')
                    ->setSubject(
                          'brp_error_rate',
                          [
                              'errorRate'    => (string) $report['errorRate'],
                              'totalLookups' => (string) $report['totalLookups'],
                              'errorCount'   => (string) $report['errorCount'],
                          ]
                          )
                    ->setDateTime(new \DateTime());
                $this->notificationManager->notify($n);
            } catch (Throwable $e) {
                $this->logger->warning('BRP monitor notify failed', ['admin' => $admin->getUID(), 'error' => $e->getMessage()]);
            }
        }
    }//end notifyAdmins()
}//end class
