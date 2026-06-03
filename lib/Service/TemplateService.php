<?php

/**
 * Pipelinq TemplateService.
 *
 * Resolves WhatsApp HSM templates and validates send parameters against them.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\OrSerializeTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Template resolution and parameter validation (REQ-001).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates OR objects + config
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.3
 */
class TemplateService
{
    use OrSerializeTrait;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig The app config (register/schema ids).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a template by its id (OR uuid or @self.slug).
     *
     * @param string $templateId The template identifier.
     *
     * @return array<string, mixed>|null The template object, or null when absent.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.3
     */
    public function find(string $templateId): ?array
    {
        foreach ($this->allTemplates() as $template) {
            if ($this->templateId(template: $template) === $templateId) {
                return $template;
            }
        }

        return null;
    }//end find()

    /**
     * Count the distinct `{{N}}` positional placeholders in a template body.
     *
     * @param string $body The template body.
     *
     * @return int The number of distinct placeholders.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.3
     */
    public function placeholderCount(string $body): int
    {
        $matches = [];
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        if ($matches[1] === []) {
            return 0;
        }

        return count(array_unique($matches[1]));
    }//end placeholderCount()

    /**
     * Whether a template is approved (available for send).
     *
     * @param array<string, mixed> $template The template object.
     *
     * @return bool True when the status is 'approved'.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
     */
    public function isApproved(array $template): bool
    {
        return (string) ($template['status'] ?? '') === 'approved';
    }//end isApproved()

    /**
     * Validate that the supplied parameters match the template placeholders.
     *
     * @param array<string, mixed> $template   The template object.
     * @param array<int, string>   $parameters The supplied positional parameters.
     *
     * @return array{valid: bool, expected: int, given: int} The validation result.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.3
     */
    public function validateParameters(array $template, array $parameters): array
    {
        $expected = $this->placeholderCount(body: (string) ($template['body'] ?? ''));
        $given    = count($parameters);

        return [
            'valid'    => ($expected === $given),
            'expected' => $expected,
            'given'    => $given,
        ];
    }//end validateParameters()

    /**
     * All `messageTemplate` objects.
     *
     * @return array<int, array<string, mixed>> The template objects.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
     */
    public function allTemplates(): array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Template query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $templates = [];
        foreach ($results as $result) {
            $templates[] = $this->serialize(result: $result);
        }

        return $templates;
    }//end allTemplates()

    /**
     * Resolve the configured register + messageTemplate schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'messageTemplate_schema', ''),
        ];
    }//end registerSchema()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The ObjectService, or null.
     */
    private function objectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end objectService()

    /**
     * Derive the id of a template (uuid, else @self.slug).
     *
     * @param array<string, mixed> $template The template object.
     *
     * @return string The template id.
     */
    private function templateId(array $template): string
    {
        $self = ($template['@self'] ?? []);
        if (is_array($self) === true) {
            $id = (string) ($self['id'] ?? ($self['uuid'] ?? ($self['slug'] ?? '')));
            if ($id !== '') {
                return $id;
            }
        }

        return (string) ($template['id'] ?? '');
    }//end templateId()
}//end class
