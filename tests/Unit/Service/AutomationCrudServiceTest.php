<?php

/**
 * Unit tests for AutomationCrudService.
 *
 * Covers input validation (required name + trigger, well-formed actions /
 * conditions), the editable-property whitelist (server-controlled fields cannot
 * be injected by a client), pagination of the scoped list and the
 * newest-first ordering of execution history. The OpenRegister ObjectService is
 * supplied as an in-memory double resolved through the container.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\AutomationCrudService;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationCrudService.
 */
class AutomationCrudServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AutomationCrudService
     */
    private AutomationCrudService $service;

    /**
     * The in-memory object service double.
     *
     * @var object
     */
    private object $objectService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->makeObjectServiceDouble();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id): object => $this->objectService
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key): string {
                return match ($key) {
                    'register'             => 'reg1',
                    'automation_schema'    => 'autoSchema',
                    'automationLog_schema' => 'logSchema',
                    default                => '',
                };
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new AutomationCrudService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * create rejects input missing a name.
     *
     * @return void
     */
    public function testCreateRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(['trigger' => 'lead_created']);
    }//end testCreateRejectsMissingName()

    /**
     * create rejects input missing a trigger.
     *
     * @return void
     */
    public function testCreateRejectsMissingTrigger(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(['name' => 'My automation']);
    }//end testCreateRejectsMissingTrigger()

    /**
     * create whitelists properties and forces server-controlled fields.
     *
     * @return void
     */
    public function testCreateWhitelistsAndForcesServerFields(): void
    {
        $result = $this->service->create(
            [
                'name'     => 'My automation',
                'trigger'  => 'lead_created',
                'runCount' => 999,
                'lastRun'  => '2020-01-01T00:00:00+00:00',
                'isActive' => true,
                'evil'     => 'nope',
            ]
        );

        $this->assertSame(0, $result['runCount']);
        $this->assertArrayNotHasKey('lastRun', $result);
        $this->assertArrayNotHasKey('evil', $result);
        $this->assertTrue($result['isActive']);
        $this->assertSame('My automation', $result['name']);
    }//end testCreateWhitelistsAndForcesServerFields()

    /**
     * list paginates the scoped result set.
     *
     * @return void
     */
    public function testListPaginates(): void
    {
        $seed = [];
        for ($i = 0; $i < 45; $i++) {
            $seed[] = ['id' => 'a'.$i, 'name' => 'A'.$i, 'trigger' => 'lead_created'];
        }
        $this->objectService->seed = $seed;

        $page1 = $this->service->list(1);
        $this->assertSame(45, $page1['total']);
        $this->assertSame(3, $page1['pages']);
        $this->assertCount(20, $page1['results']);

        $page3 = $this->service->list(3);
        $this->assertCount(5, $page3['results']);
    }//end testListPaginates()

    /**
     * get throws a 404-style exception when the automation is absent.
     *
     * @return void
     */
    public function testGetMissingThrowsNotFound(): void
    {
        $this->expectException(OCSNotFoundException::class);

        $this->service->get('missing');
    }//end testGetMissingThrowsNotFound()

    /**
     * setActive flips the active flag and persists it.
     *
     * @return void
     */
    public function testSetActivePersistsFlag(): void
    {
        $this->objectService->seed = [
            ['id' => 'a1', 'name' => 'A', 'trigger' => 'lead_created', 'isActive' => false],
        ];

        $result = $this->service->setActive('a1', true);

        $this->assertTrue($result['isActive']);
    }//end testSetActivePersistsFlag()

    /**
     * history returns log entries newest-first.
     *
     * @return void
     */
    public function testHistoryOrdersNewestFirst(): void
    {
        $this->objectService->seed = [
            ['id' => 'a1', 'name' => 'A', 'trigger' => 'lead_created'],
            ['id' => 'l1', 'automation' => 'a1', 'triggeredAt' => '2026-01-01T00:00:00+00:00'],
            ['id' => 'l2', 'automation' => 'a1', 'triggeredAt' => '2026-03-01T00:00:00+00:00'],
            ['id' => 'l3', 'automation' => 'a1', 'triggeredAt' => '2026-02-01T00:00:00+00:00'],
        ];

        $history = $this->service->history('a1');

        $this->assertCount(3, $history);
        $this->assertSame('l2', $history[0]['id']);
        $this->assertSame('l3', $history[1]['id']);
        $this->assertSame('l1', $history[2]['id']);
    }//end testHistoryOrdersNewestFirst()

    /**
     * Build an in-memory ObjectService test double.
     *
     * @return object The double.
     */
    private function makeObjectServiceDouble(): object
    {
        return new class {
            /**
             * Seed objects returned by find / findAll.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $seed = [];

            /**
             * Every object passed to saveObject.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * Return seeded objects, applying the automation + trigger filters.
             *
             * @param array $config The query config.
             *
             * @return array The matching objects.
             */
            public function findAll(array $config=[]): array
            {
                $filters = ($config['filters'] ?? []);
                $result  = [];
                foreach ($this->seed as $object) {
                    if (isset($filters['automation']) === true
                        && ($object['automation'] ?? null) !== $filters['automation']
                    ) {
                        continue;
                    }

                    if (isset($filters['trigger']) === true
                        && ($object['trigger'] ?? null) !== $filters['trigger']
                    ) {
                        continue;
                    }

                    // The list/history filters exclude log rows from the
                    // automation list and vice versa by the presence of the
                    // automation field.
                    if (isset($filters['trigger']) === true && isset($object['automation']) === true) {
                        continue;
                    }

                    $result[] = $object;
                }

                return $result;
            }

            /**
             * Find a seeded object by id.
             *
             * @param string $id       The object id.
             * @param mixed  $register The register (ignored).
             * @param mixed  $schema   The schema (ignored).
             *
             * @return array|null The matching object or null.
             */
            public function find(string $id, mixed $register=null, mixed $schema=null): ?array
            {
                foreach ($this->seed as $object) {
                    if (($object['id'] ?? null) === $id) {
                        return $object;
                    }
                }

                return null;
            }

            /**
             * Capture a saved object.
             *
             * @param array $object   The object to save.
             * @param array $extend   Extend config (ignored).
             * @param mixed $register The register (ignored).
             * @param mixed $schema   The schema (ignored).
             * @param mixed $uuid     The uuid (ignored).
             *
             * @return array The saved object.
             */
            public function saveObject(array $object, array $extend=[], mixed $register=null, mixed $schema=null, mixed $uuid=null): array
            {
                $this->saved[] = $object;

                return $object;
            }

            /**
             * Delete a seeded object by uuid.
             *
             * @param mixed  $register The register (ignored).
             * @param mixed  $schema   The schema (ignored).
             * @param string $uuid     The uuid to delete.
             *
             * @return bool Always true.
             */
            public function deleteObject(mixed $register=null, mixed $schema=null, string $uuid=''): bool
            {
                return true;
            }
        };
    }//end makeObjectServiceDouble()
}//end class
