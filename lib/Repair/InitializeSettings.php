<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class InitializeSettings implements IRepairStep
{
    private const REGISTER_NAME = 'client-management';

    private const SCHEMAS = [
        'client' => [
            'title' => 'Client',
            'description' => 'A client in the client management system',
            'properties' => [
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['person', 'organization']],
                'address' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
        ],
        'request' => [
            'title' => 'Request',
            'description' => 'A request (verzoek) — the pre-state of a case',
            'properties' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'client' => ['type' => 'string'],
                'status' => ['type' => 'string', 'default' => 'new'],
                'priority' => ['type' => 'string', 'default' => 'normal'],
                'requestedAt' => ['type' => 'string', 'format' => 'date-time'],
                'category' => ['type' => 'string'],
            ],
        ],
        'contact' => [
            'title' => 'Contact',
            'description' => 'A contact person linked to a client',
            'properties' => [
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string'],
                'role' => ['type' => 'string'],
                'client' => ['type' => 'string'],
            ],
        ],
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Initialize Pipelinq client-management register and schemas';
    }

    public function run(IOutput $output): void
    {
        $output->info('Initializing Pipelinq settings...');

        if (!$this->appManager->isEnabledForUser('openregister')) {
            $output->warning('OpenRegister is not installed or enabled. Skipping auto-configuration.');
            $this->logger->warning('Pipelinq: OpenRegister not available, skipping register initialization');
            return;
        }

        try {
            $this->initializeRegisterAndSchemas($output);
        } catch (\Exception $e) {
            $output->warning('Could not auto-configure: ' . $e->getMessage());
            $this->logger->error('Pipelinq initialization failed', ['exception' => $e->getMessage()]);
        }
    }

    private function initializeRegisterAndSchemas(IOutput $output): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerService = $this->container->get('OCA\OpenRegister\Service\RegisterService');
        } catch (\Exception $e) {
            $output->warning('Could not access OpenRegister services: ' . $e->getMessage());
            return;
        }

        // Look for existing register
        $registers = $registerService->findAll();
        $register = null;
        foreach ($registers as $reg) {
            if ($reg->getTitle() === self::REGISTER_NAME || $reg->getSlug() === self::REGISTER_NAME) {
                $register = $reg;
                break;
            }
        }

        if ($register === null) {
            $register = $registerService->createFromArray([
                'title' => self::REGISTER_NAME,
                'slug' => self::REGISTER_NAME,
                'description' => 'Client management register for Pipelinq',
            ]);
            $output->info('Created client-management register with ID ' . $register->getId());
        } else {
            $output->info('Found existing client-management register with ID ' . $register->getId());
        }

        $this->appConfig->setValueString(Application::APP_ID, 'register', (string) $register->getId());

        // Create or find schemas
        $schemaService = $this->container->get('OCA\OpenRegister\Service\SchemaService');
        $existingSchemas = $schemaService->findAll();

        foreach (self::SCHEMAS as $slug => $definition) {
            $configKey = $slug . '_schema';
            $existingId = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');

            if ($existingId !== '') {
                $output->info("Schema '$slug' already configured with ID $existingId");
                continue;
            }

            $found = null;
            foreach ($existingSchemas as $schema) {
                if ($schema->getSlug() === $slug && $schema->getRegister() === $register->getId()) {
                    $found = $schema;
                    break;
                }
            }

            if ($found === null) {
                $found = $schemaService->createFromArray([
                    'title' => $definition['title'],
                    'slug' => $slug,
                    'description' => $definition['description'],
                    'register' => $register->getId(),
                    'properties' => json_encode($definition['properties']),
                ]);
                $output->info("Created schema '$slug' with ID " . $found->getId());
            } else {
                $output->info("Found existing schema '$slug' with ID " . $found->getId());
            }

            $this->appConfig->setValueString(Application::APP_ID, $configKey, (string) $found->getId());
        }

        $this->logger->info('Pipelinq: Register and schemas initialized successfully');
    }
}
