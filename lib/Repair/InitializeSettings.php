<?php

/**
 * Pipelinq InitializeSettings.
 *
 * Repair step to initialize Pipelinq registers, schemas, and default data.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step for initializing Pipelinq settings.
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager.
     * @param ContainerInterface $container  The container.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The repair step name.
     */
    public function getName(): string
    {
        return 'Initialize Pipelinq register and schemas via ConfigurationService';
    }//end getName()

    private const DEFAULT_LEAD_SOURCES = [
        'website',
        'email',
        'phone',
        'referral',
        'partner',
        'campaign',
        'social_media',
        'event',
        'other',
    ];

    private const DEFAULT_REQUEST_CHANNELS = [
        'phone',
        'email',
        'website',
        'counter',
        'post',
    ];

    /**
     * Run the repair step.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->startProgress(4);

        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister app is not installed -- skipping configuration import');
            $this->logger->warning('Pipelinq: OpenRegister not available, skipping register initialization');
            $output->advance(3);
            $output->finishProgress();
            return;
        }

        $output->info('Loading Pipelinq configuration...');
        $output->advance(1);

        try {
            $settingsService = $this->container->get(SettingsService::class);
            $result          = $settingsService->loadSettings(force: false);

            $registerCount = count($result['registers'] ?? []);
            $schemaCount   = count($result['schemas'] ?? []);

            $output->info("Configuration loaded: {$registerCount} registers, {$schemaCount} schemas");
            $this->logger->info('Pipelinq: Register and schemas initialized successfully');
        } catch (\Exception $e) {
            $output->warning('Failed to load configuration: '.$e->getMessage());
            $this->logger->error('Pipelinq initialization failed', ['exception' => $e->getMessage()]);
        }

        $output->advance(1);

        // Create default pipelines if none exist.
        $output->info('Checking default pipelines...');
        try {
            $settingsService = $this->container->get(SettingsService::class);
            $settingsService->createDefaultPipelines();
            $output->info('Default pipelines checked/created');
        } catch (\Exception $e) {
            $output->warning('Failed to create default pipelines: '.$e->getMessage());
            $this->logger->error('Pipelinq default pipeline creation failed', ['exception' => $e->getMessage()]);
        }

        $output->advance(1);

        // Create default lead sources and request channels.
        $output->info('Checking default lead sources and request channels...');
        try {
            $systemTagService = $this->container->get(SystemTagService::class);
            $systemTagService->ensureDefaults(
                objectType: 'pipelinq_lead_source',
                defaults: self::DEFAULT_LEAD_SOURCES
            );
            $systemTagService->ensureDefaults(
                objectType: 'pipelinq_request_channel',
                defaults: self::DEFAULT_REQUEST_CHANNELS
            );
            $output->info('Default lead sources and request channels checked/created');
        } catch (\Exception $e) {
            $output->warning('Failed to create default tags: '.$e->getMessage());
            $this->logger->error('Pipelinq default tag creation failed', ['exception' => $e->getMessage()]);
        }

        $output->advance(1);
        $output->finishProgress();
    }//end run()
}//end class
