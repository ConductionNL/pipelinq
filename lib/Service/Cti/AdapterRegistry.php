<?php

/**
 * Pipelinq AdapterRegistry.
 *
 * In-process registry that maps platform identifiers to their adapter classes.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * CTI adapter registry.
 *
 * Built-in adapters (CallVoip, RingCentral, Asterisk) are auto-registered.
 * Extra adapters can register themselves via {@see self::register()}.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
class AdapterRegistry {

	/**
	 * Map of platform identifier to fully-qualified adapter class name.
	 *
	 * @var array<string,class-string<CtiAdapterInterface>>
	 */
	private array $adapterClasses = [];

	/**
	 * Cached adapter instances keyed by platform identifier.
	 *
	 * @var array<string,CtiAdapterInterface>
	 */
	private array $instances = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Service container used to resolve adapter classes.
	 */
	public function __construct(
		private ContainerInterface $container,
	) {
		$this->register(platform: 'callvoip', adapterClass: CallVoipAdapter::class);
		$this->register(platform: 'ringcentral', adapterClass: RingCentralAdapter::class);
		$this->register(platform: 'asterisk', adapterClass: AsteriskAdapter::class);
	}//end __construct()

	/**
	 * Register a new adapter class for a platform identifier.
	 *
	 * @param string $platform Platform identifier (lower-case).
	 * @param class-string<CtiAdapterInterface> $adapterClass Adapter class name.
	 *
	 * @return void
	 */
	public function register(string $platform, string $adapterClass): void {
		$this->adapterClasses[$platform] = $adapterClass;
		unset($this->instances[$platform]);
	}//end register()

	/**
	 * Get the adapter instance for the given platform.
	 *
	 * @param string $platform Platform identifier.
	 *
	 * @return CtiAdapterInterface Adapter instance.
	 *
	 * @throws RuntimeException When the platform has no registered adapter.
	 */
	public function get(string $platform): CtiAdapterInterface {
		$platform = strtolower(trim($platform));
		if (isset($this->instances[$platform]) === true) {
			return $this->instances[$platform];
		}

		if (isset($this->adapterClasses[$platform]) === false) {
			throw new RuntimeException(
				'CTI adapter not registered for platform: ' . $platform
			);
		}

		$adapter = $this->container->get($this->adapterClasses[$platform]);
		if (($adapter instanceof CtiAdapterInterface) === false) {
			throw new RuntimeException(
				'CTI adapter class did not implement CtiAdapterInterface: ' . $this->adapterClasses[$platform]
			);
		}

		$this->instances[$platform] = $adapter;
		return $adapter;
	}//end get()

	/**
	 * List all registered platform identifiers.
	 *
	 * @return array<int,string>
	 */
	public function listPlatforms(): array {
		return array_keys($this->adapterClasses);
	}//end listPlatforms()
}//end class
