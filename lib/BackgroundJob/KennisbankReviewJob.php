<?php

/**
 * Pipelinq KennisbankReviewJob.
 *
 * Background job for sending review reminders for stale knowledge base articles.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Timed background job for knowledge base article review reminders.
 *
 * Runs daily to check for articles that have not been updated
 * within the configured review interval and sends review notifications.
 */
class KennisbankReviewJob extends TimedJob
{

    private const DEFAULT_REVIEW_INTERVAL = 180;

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time                The time factory.
     * @param SettingsService     $settingsService     The settings service.
     * @param NotificationService $notificationService The notification service.
     * @param IAppManager         $appManager          The app manager.
     * @param ContainerInterface  $container           The DI container.
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SettingsService $settingsService,
        private readonly NotificationService $notificationService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Execute the review check job.
     *
     * Queries published articles and sends review reminders for stale ones.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     */
    protected function run(mixed $argument): void
    {
        try {
            if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
                return;
            }

            $config = $this->settingsService->getSettings();
            if (empty($config['register']) === true || empty($config['kennisartikel_schema']) === true) {
                return;
            }

            $intervalDays  = (int) ($config['kennisbank_review_interval'] ?? self::DEFAULT_REVIEW_INTERVAL);
            $thresholdDate = new \DateTime();
            $thresholdDate->modify("-{$intervalDays} days");
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService->findAll(
                register: $config['register'],
                schema: $config['kennisartikel_schema'],
                filters: ['status' => 'gepubliceerd', '_limit' => 500]
            );
            $articles      = ($result['results'] ?? []);
            foreach ($articles as $article) {
                $lastUpdated = $article['dateModified'] ?? $article['updatedAt'] ?? $article['dateCreated'] ?? null;
                if ($lastUpdated === null) {
                    continue;
                }

                if (new \DateTime($lastUpdated) < $thresholdDate) {
                    $author = ($article['author'] ?? '');
                    if (empty($author) === true) {
                        continue;
                    }

                    $this->notificationService->sendNotification(
                        userId: $author,
                        subject: 'kennisbank_review_needed',
                        parameters: [
                            'articleTitle' => ($article['title'] ?? 'Untitled'),
                            'articleId'    => ($article['id'] ?? ''),
                            'daysSince'    => $intervalDays,
                        ]
                    );
                    $this->logger->info('Kennisbank review reminder sent for article: '.($article['title'] ?? 'unknown'));
                }
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->error('KennisbankReviewJob error: '.$e->getMessage());
        }//end try
    }//end run()
}//end class
