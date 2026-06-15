<?php

/**
 * Shared test support for the AVG service unit tests.
 *
 * Provides an in-memory fake OpenRegister ObjectService and a factory that wires
 * a real AvgRepository on top of it (via a container + app-config stub), so the
 * AVG services can be exercised end-to-end without a live Nextcloud / OR.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory fake of OpenRegister's ObjectService used by the AVG tests.
 *
 * Objects are keyed by schema id then object id; saveObject assigns a generated
 * id on create and merges an @self block so AvgRepository::idOf resolves it.
 */
class FakeAvgObjectService
{
    /**
     * The store: schema id => object id => object.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $store = [];

    /**
     * Monotonic id counter for created objects.
     *
     * @var int
     */
    private int $counter = 0;

    /**
     * Find an object by id within a schema.
     *
     * @param string $id       The object id.
     * @param string $register The register id (ignored by the fake).
     * @param string $schema   The schema id.
     *
     * @return array<string, mixed>|null The object, or null.
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return ($this->store[$schema][$id] ?? null);
    }

    /**
     * Find all objects matching the config filters.
     *
     * @param array<string, mixed> $config The find config.
     *
     * @return array<int, array<string, mixed>> The matching objects.
     */
    public function findAll(array $config): array
    {
        $filters = (array) ($config['filters'] ?? []);
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($filters): bool {
                    foreach ($filters as $key => $value) {
                        if (in_array($key, ['register', 'schema'], true) === true) {
                            continue;
                        }

                        if ((string) ($row[$key] ?? '') !== (string) $value) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }

    /**
     * Persist (create or update) an object.
     *
     * @param array<string, mixed> $object   The object data.
     * @param array<string, mixed> $extend   Unused.
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     * @param string|null          $uuid     The id to update, or null to create.
     *
     * @return array<string, mixed> The saved object.
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid = null): array
    {
        if ($uuid === null || $uuid === '') {
            $this->counter++;
            $uuid = $schema.'-'.$this->counter;
        }

        $object['@self'] = ['id' => $uuid, 'register' => $register, 'schema' => $schema];
        $this->store[$schema][$uuid] = $object;

        return $object;
    }

    /**
     * Delete an object.
     *
     * @param string $uuid     The object id.
     * @param string $register The register id.
     * @param string $schema   The schema id.
     *
     * @return bool Always true.
     */
    public function deleteObject(string $uuid, string $register, string $schema): bool
    {
        unset($this->store[$schema][$uuid]);

        return true;
    }
}//end class

/**
 * Factory for a real AvgRepository backed by the in-memory fake.
 */
class AvgRepositoryFactory
{
    /**
     * Build a PSR-11 container exposing only the fake OR ObjectService.
     *
     * @param FakeAvgObjectService $objectService The fake OR ObjectService.
     *
     * @return ContainerInterface The container.
     */
    public static function container(FakeAvgObjectService $objectService): ContainerInterface
    {
        return new class($objectService) implements ContainerInterface {
            /**
             * @param FakeAvgObjectService $objectService The fake service.
             */
            public function __construct(private FakeAvgObjectService $objectService)
            {
            }

            /**
             * @param string $id The service id.
             *
             * @return mixed The service.
             */
            public function get(string $id): mixed
            {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                throw new \RuntimeException('not found: '.$id);
            }

            /**
             * @param string $id The service id.
             *
             * @return bool Whether the service exists.
             */
            public function has(string $id): bool
            {
                return ($id === 'OCA\OpenRegister\Service\ObjectService');
            }
        };
    }

    /**
     * Build an AvgRepository wired to the fake ObjectService and a caller-supplied
     * IAppConfig stub.
     *
     * The app-config stub must map the 'register' key and every '<slug>_schema'
     * key onto a synthetic id (the key itself is fine) so the fake's per-schema
     * store partitions exactly as production would.
     *
     * @param FakeAvgObjectService $objectService The fake OR ObjectService.
     * @param IAppConfig           $appConfig     The app-config stub.
     *
     * @return AvgRepository The repository.
     */
    public static function build(FakeAvgObjectService $objectService, IAppConfig $appConfig): AvgRepository
    {
        return new AvgRepository(
            container: self::container($objectService),
            appConfig: $appConfig,
            logger: new NullLogger()
        );
    }
}//end class
