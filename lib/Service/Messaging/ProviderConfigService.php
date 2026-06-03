<?php

/**
 * Pipelinq ProviderConfigService.
 *
 * Resolves configured channelProvider objects and their secrets, and builds
 * ready-to-use provider clients.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves provider configuration and secrets (ADR-005).
 *
 * `channelProvider` objects live in OpenRegister and carry no secret material —
 * only a `credentialRef`. The actual secrets (API tokens, webhook signing keys)
 * are read here from sensitive app-config values keyed by that ref, and passed
 * transiently into the provider client. Secrets are never returned to callers
 * nor written back onto the OR object.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates OR objects, app config and the registry
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.4
 */
class ProviderConfigService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig The app config (holds register/schema ids + secrets).
     * @param ProviderRegistry   $registry  The provider-client registry.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private ProviderRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List active channelProvider objects of the given kinds, priority-ascending.
     *
     * @param string[] $kinds The provider kinds to include (e.g. ['sms']).
     *
     * @return array<int, array<string, mixed>> The active provider objects.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.4
     */
    public function activeProviders(array $kinds): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'channelProvider_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Provider config query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $providers = [];
        foreach ($results as $result) {
            $provider = $this->serialize(result: $result);
            if (($provider['active'] ?? false) !== true) {
                continue;
            }

            if (in_array((string) ($provider['kind'] ?? ''), $kinds, true) === false) {
                continue;
            }

            $providers[] = $provider;
        }

        usort(
            $providers,
            static fn(array $a, array $b): int => ((int) ($a['priority'] ?? 1)) <=> ((int) ($b['priority'] ?? 1))
        );

        return $providers;
    }//end activeProviders()

    /**
     * Find a single active provider by its id (OR uuid or @self.slug).
     *
     * @param string $providerId The provider identifier.
     *
     * @return array<string, mixed>|null The provider object, or null when absent/inactive.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.4
     */
    public function findActiveById(string $providerId): ?array
    {
        foreach ($this->activeProviders(kinds: ['whatsapp-cloud-api', 'whatsapp-bsp', 'sms']) as $provider) {
            if ($this->providerId(provider: $provider) === $providerId) {
                return $provider;
            }
        }

        return null;
    }//end findActiveById()

    /**
     * Build a configured client for a provider object (secrets resolved).
     *
     * @param array<string, mixed> $provider The channelProvider object.
     *
     * @return ChannelProviderInterface|null The configured client, or null when the vendor is unknown.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.4
     */
    public function buildClient(array $provider): ?ChannelProviderInterface
    {
        $vendor = strtolower((string) ($provider['vendor'] ?? ''));
        if ($this->registry->has($vendor) === false) {
            return null;
        }

        $client  = $this->registry->get($vendor);
        $secrets = $this->resolveSecrets(provider: $provider);
        $client->configure($provider, $secrets);

        return $client;
    }//end buildClient()

    /**
     * Resolve the webhook signing secret for a provider (ADR-005).
     *
     * @param array<string, mixed> $provider The channelProvider object.
     *
     * @return string The webhook signing secret (empty when unset).
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.6
     */
    public function webhookSecret(array $provider): string
    {
        $ref = (string) ($provider['credentialRef'] ?? '');
        if ($ref === '') {
            return '';
        }

        return $this->appConfig->getValueString(Application::APP_ID, $ref.'__webhook_secret', '');
    }//end webhookSecret()

    /**
     * Derive a stable id for a provider object (uuid, else @self.slug).
     *
     * @param array<string, mixed> $provider The provider object.
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.4
     */
    public function providerId(array $provider): string
    {
        $self = ($provider['@self'] ?? []);
        if (is_array($self) === true) {
            $id = (string) ($self['id'] ?? ($self['uuid'] ?? ($self['slug'] ?? '')));
            if ($id !== '') {
                return $id;
            }
        }

        return (string) ($provider['id'] ?? '');
    }//end providerId()

    /**
     * Resolve the per-provider secret material from sensitive app config.
     *
     * The secret bundle is stored as a JSON object under
     * `<credentialRef>__secrets` as a sensitive value (ADR-005); each vendor
     * client reads only the keys it needs.
     *
     * @param array<string, mixed> $provider The channelProvider object.
     *
     * @return array<string, string> The secret key/value bundle (empty when unset).
     */
    private function resolveSecrets(array $provider): array
    {
        $ref = (string) ($provider['credentialRef'] ?? '');
        if ($ref === '') {
            return [];
        }

        $raw = $this->appConfig->getValueString(Application::APP_ID, $ref.'__secrets', '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return [];
        }

        $secrets = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) === true && (is_string($value) === true || is_int($value) === true)) {
                $secrets[$key] = (string) $value;
            }
        }

        return $secrets;
    }//end resolveSecrets()

    /**
     * Serialise an OpenRegister result (entity or array) to a plain array.
     *
     * @param mixed $result The raw result.
     *
     * @return array<string, mixed> The serialised provider.
     */
    private function serialize(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serialize()
}//end class
