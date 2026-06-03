<?php

/**
 * Pipelinq AdapterRegistry.
 *
 * Registry that resolves a CTI adapter instance for a configured platform.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use Psr\Container\ContainerInterface;

/**
 * Resolves CTI adapters by platform slug.
 *
 * Built-in platforms (callvoip, ringcentral, asterisk) are pre-registered.
 * Additional vendors register their adapter class at runtime via
 * {@see self::register()} with no change to CtiService/CtiController
 * (REQ-CTI-006 extensibility).
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
class AdapterRegistry
{

    /**
     * Map of platform slug to adapter class-string.
     *
     * @var array<string, class-string<CtiAdapterInterface>>
     */
    private array $adapters = [];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves adapter dependencies).
     */
    public function __construct(
        private ContainerInterface $container,
    ) {
        $this->register(platform: 'callvoip', adapterClass: CallVoipAdapter::class);
        $this->register(platform: 'ringcentral', adapterClass: RingCentralAdapter::class);
        $this->register(platform: 'asterisk', adapterClass: AsteriskAdapter::class);
    }//end __construct()

    /**
     * Register (or override) an adapter for a platform slug.
     *
     * @param string                            $platform     The lowercase platform slug.
     * @param class-string<CtiAdapterInterface> $adapterClass The adapter class to instantiate.
     *
     * @return void
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
     */
    public function register(string $platform, string $adapterClass): void
    {
        $this->adapters[strtolower($platform)] = $adapterClass;
    }//end register()

    /**
     * Whether an adapter is registered for the given platform.
     *
     * @param string $platform The platform slug.
     *
     * @return bool True when an adapter is registered.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
     */
    public function has(string $platform): bool
    {
        return isset($this->adapters[strtolower($platform)]);
    }//end has()

    /**
     * Resolve the adapter instance for a platform.
     *
     * @param string $platform The platform slug.
     *
     * @return CtiAdapterInterface The resolved adapter.
     *
     * @throws InvalidArgumentException When no adapter is registered for the platform.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
     */
    public function get(string $platform): CtiAdapterInterface
    {
        $key = strtolower($platform);
        if (isset($this->adapters[$key]) === false) {
            throw new InvalidArgumentException('No CTI adapter registered for platform: '.$platform);
        }

        $adapter = $this->container->get($this->adapters[$key]);
        if (($adapter instanceof CtiAdapterInterface) === false) {
            throw new InvalidArgumentException('Registered CTI adapter for '.$platform.' is invalid.');
        }

        return $adapter;
    }//end get()

    /**
     * List the platform slugs that have a registered adapter.
     *
     * @return string[] The registered platform slugs.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
     */
    public function platforms(): array
    {
        return array_keys($this->adapters);
    }//end platforms()
}//end class
