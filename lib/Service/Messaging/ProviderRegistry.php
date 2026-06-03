<?php

/**
 * Pipelinq ProviderRegistry.
 *
 * Registry that resolves a messaging provider client for a configured vendor.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Messaging\Provider\CmComClient;
use OCA\Pipelinq\Service\Messaging\Provider\MessageBirdClient;
use OCA\Pipelinq\Service\Messaging\Provider\MetaWhatsAppClient;
use OCA\Pipelinq\Service\Messaging\Provider\TwilioClient;
use Psr\Container\ContainerInterface;

/**
 * Resolves messaging provider clients by vendor slug.
 *
 * Built-in vendors (meta, twilio, messagebird, cm-com) are pre-registered.
 * Additional vendors register their client class at runtime via
 * {@see self::register()} with no change to the orchestrating services.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
 */
class ProviderRegistry
{

    /**
     * Map of vendor slug to client class-string.
     *
     * @var array<string, class-string<ChannelProviderInterface>>
     */
    private array $clients = [];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves client dependencies).
     */
    public function __construct(
        private ContainerInterface $container,
    ) {
        $this->register(vendor: 'meta', clientClass: MetaWhatsAppClient::class);
        $this->register(vendor: 'twilio', clientClass: TwilioClient::class);
        $this->register(vendor: 'messagebird', clientClass: MessageBirdClient::class);
        $this->register(vendor: 'cm-com', clientClass: CmComClient::class);
    }//end __construct()

    /**
     * Register (or override) a client for a vendor slug.
     *
     * @param string                                 $vendor      The lowercase vendor slug.
     * @param class-string<ChannelProviderInterface> $clientClass The client class to instantiate.
     *
     * @return void
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
     */
    public function register(string $vendor, string $clientClass): void
    {
        $this->clients[strtolower($vendor)] = $clientClass;
    }//end register()

    /**
     * Whether a client is registered for the given vendor.
     *
     * @param string $vendor The vendor slug.
     *
     * @return bool True when a client is registered.
     */
    public function has(string $vendor): bool
    {
        return isset($this->clients[strtolower($vendor)]);
    }//end has()

    /**
     * Resolve a fresh, unconfigured client instance for a vendor.
     *
     * @param string $vendor The vendor slug.
     *
     * @return ChannelProviderInterface The resolved client.
     *
     * @throws InvalidArgumentException When no client is registered for the vendor.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-3.2
     */
    public function get(string $vendor): ChannelProviderInterface
    {
        $key = strtolower($vendor);
        if (isset($this->clients[$key]) === false) {
            throw new InvalidArgumentException('No messaging client registered for vendor: '.$vendor);
        }

        $client = $this->container->get($this->clients[$key]);
        if (($client instanceof ChannelProviderInterface) === false) {
            throw new InvalidArgumentException('Registered messaging client for '.$vendor.' is invalid.');
        }

        return $client;
    }//end get()

    /**
     * List the vendor slugs that have a registered client.
     *
     * @return string[] The registered vendor slugs.
     */
    public function vendors(): array
    {
        return array_keys($this->clients);
    }//end vendors()
}//end class
