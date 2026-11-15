<?php

/**
 * Pipelinq ListQueryWhereCapability.
 *
 * Whether this instance's OpenRegister accepts the `_where` condition group
 * on its list queries (openregister change `list-query-condition-groups`),
 * read from OpenRegister's published capabilities once and cached for an
 * hour. Until OpenRegister lists `listQuery.where` as true, segments are
 * evaluated the way they always were: every row loaded, the tree checked in
 * PHP (design D4, case 3).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads OpenRegister's `listQuery.where` capability once.
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */
class ListQueryWhereCapability {

	/**
	 * OpenRegister's capabilities service.
	 *
	 * @var string
	 */
	private const CAPABILITIES_SERVICE = 'OCA\\OpenRegister\\Service\\ApiVersion\\ApiCapabilitiesServiceInterface';

	/**
	 * The cache key, and how long an answer is kept.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'listQueryWhere';

	/**
	 * Seconds an answer is kept.
	 *
	 * @var integer
	 */
	private const CACHE_TTL = 3600;

	/**
	 * The answer for this request, once read.
	 *
	 * @var boolean|null
	 */
	private ?bool $available = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Resolves OpenRegister's capabilities service.
	 * @param ICacheFactory      $cacheFactory Keeps the answer between requests.
	 * @param LoggerInterface    $logger       Logs a capabilities read that failed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister accepts `_where`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/segment-groups-its-rules/design.md
	 */
	public function isAvailable(): bool {
		if ($this->available !== null) {
			return $this->available;
		}

		$cache = $this->cacheFactory->createLocal('pipelinq_segments');
		$cached = $cache->get(self::CACHE_KEY);
		if (is_bool($cached) === true) {
			$this->available = $cached;
			return $cached;
		}

		$this->available = $this->read();
		$cache->set(self::CACHE_KEY, $this->available, self::CACHE_TTL);

		return $this->available;
	}//end isAvailable()

	/**
	 * Read the capability from OpenRegister; anything but an explicit true is false.
	 *
	 * @return bool
	 */
	private function read(): bool {
		try {
			$capabilities = $this->container->get(self::CAPABILITIES_SERVICE)->publicCapabilities();
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq segments: OpenRegister capabilities not readable, counting in PHP', ['exception' => $e->getMessage()]);
			return false;
		}

		if (is_array($capabilities) === false) {
			return false;
		}

		return (($capabilities['listQuery']['where'] ?? null) === true);
	}//end read()
}//end class
