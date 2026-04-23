<?php

/**
 * Pipelinq DefaultQueueService.
 *
 * Service for creating default queues and skills in the Pipelinq application.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/queue-management/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for creating default queues and skills.
 */
class DefaultQueueService
{
    /**
     * Default queue definitions.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEFAULT_QUEUES = [
        [
            'title'       => 'Algemeen',
            'description' => 'General intake queue for unclassified requests',
            'categories'  => [],
            'isActive'    => true,
            'sortOrder'   => 0,
        ],
        [
            'title'       => 'Vergunningen',
            'description' => 'Queue for permit-related requests',
            'categories'  => ['vergunningen'],
            'isActive'    => true,
            'sortOrder'   => 1,
        ],
        [
            'title'       => 'Klachten',
            'description' => 'Queue for complaints',
            'categories'  => ['klachten'],
            'isActive'    => true,
            'sortOrder'   => 2,
        ],
    ];

    /**
     * Default skill definitions.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEFAULT_SKILLS = [
        [
            'title'       => 'Algemene Dienstverlening',
            'description' => 'General public service',
            'categories'  => ['algemeen'],
            'isActive'    => true,
        ],
        [
            'title'       => 'Vergunningen',
            'description' => 'Permits and environmental law',
            'categories'  => ['vergunningen', 'omgevingsrecht'],
            'isActive'    => true,
        ],
        [
            'title'       => 'Belastingen',
            'description' => 'Municipal taxes',
            'categories'  => ['belastingen'],
            'isActive'    => true,
        ],
        [
            'title'       => 'WMO / Zorg',
            'description' => 'Social support and care',
            'categories'  => ['wmo', 'zorg'],
            'isActive'    => true,
        ],
        [
            'title'       => 'Klachten',
            'description' => 'Complaint handling',
            'categories'  => ['klachten'],
            'isActive'    => true,
        ],
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The container.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create default queues if none exist.
     *
     * @return void
     *
     * @spec openspec/changes/queue-management/tasks.md#task-1.1
     */
    public function createDefaultQueues(): void
    {
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $queueSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'queue_schema', '');

        if ($registerId === '' || $queueSchemaId === '') {
            $this->logger->warning(
                'Pipelinq: Cannot create default queues -- register or queue schema not configured'
            );
            return;
        }

        try {
            $objectService = $this->getObjectService();

            $existing = $objectService->findAll(
                [
                    'register'       => $registerId,
                    'schema'         => $queueSchemaId,
                    '_limit'         => 1,
                    '_rbac'          => false,
                    '_multitenancy'  => false,
                ]
            );

            if (empty($existing) === false) {
                $this->logger->info('Pipelinq: Default queues already exist, skipping creation');
                return;
            }

            foreach (self::DEFAULT_QUEUES as $queueData) {
                $objectService->saveObject(
                    $queueData,
                    [],
                    $registerId,
                    $queueSchemaId,
                    null,
                    _rbac: false,
                    _multitenancy: false
                );
                $this->logger->info("Pipelinq: Created default queue '{$queueData['title']}'");
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Pipelinq: Failed to create default queues',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end createDefaultQueues()

    /**
     * Create default skills if none exist.
     *
     * @return void
     *
     * @spec openspec/changes/queue-management/tasks.md#task-1.1
     */
    public function createDefaultSkills(): void
    {
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $skillSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');

        if ($registerId === '' || $skillSchemaId === '') {
            $this->logger->warning(
                'Pipelinq: Cannot create default skills -- register or skill schema not configured'
            );
            return;
        }

        try {
            $objectService = $this->getObjectService();

            $existing = $objectService->findAll(
                [
                    'register'       => $registerId,
                    'schema'         => $skillSchemaId,
                    '_limit'         => 1,
                    '_rbac'          => false,
                    '_multitenancy'  => false,
                ]
            );

            if (empty($existing) === false) {
                $this->logger->info('Pipelinq: Default skills already exist, skipping creation');
                return;
            }

            foreach (self::DEFAULT_SKILLS as $skillData) {
                $objectService->saveObject(
                    $skillData,
                    [],
                    $registerId,
                    $skillSchemaId,
                    null,
                    _rbac: false,
                    _multitenancy: false
                );
                $this->logger->info("Pipelinq: Created default skill '{$skillData['title']}'");
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Pipelinq: Failed to create default skills',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end createDefaultSkills()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
