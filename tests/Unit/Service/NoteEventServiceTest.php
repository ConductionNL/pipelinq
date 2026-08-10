<?php

/**
 * Unit tests for NoteEventService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityService;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for NoteEventService.
 */
class NoteEventServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var NoteEventService
     */
    private NoteEventService $service;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $notificationService = $this->createMock(NotificationService::class);
        $activityService     = $this->createMock(ActivityService::class);
        $settingsService     = $this->createMock(SettingsService::class);
        $userSession         = $this->createMock(IUserSession::class);
        $container           = $this->createMock(ContainerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new NoteEventService(
            $notificationService,
            $activityService,
            $settingsService,
            $userSession,
            $container,
            $this->logger,
        );
    }//end setUp()

    /**
     * Test triggerNoteEvents skips unknown object type.
     *
     * @return void
     */
    public function testTriggerSkipsUnknownObjectType(): void
    {
        // Should not throw; unknown type just returns early.
        $this->logger->expects($this->never())->method('warning');

        $this->service->triggerNoteEvents('unknown_type', '123');
    }//end testTriggerSkipsUnknownObjectType()

    /**
     * Test type map contains expected types.
     *
     * Each known pipelinq_* objectType should be handled without throwing
     * and without logging a warning — fetchEntityData returns null when
     * register/schema settings are empty (the default in this test), and
     * triggerNoteEvents returns early on null entity data.
     *
     * @return void
     */
    public function testTypeMapContainsExpectedTypes(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $this->service->triggerNoteEvents(objectType: 'pipelinq_client', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_contact', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_request', objectId: '123');
    }//end testTypeMapContainsExpectedTypes()

    /**
     * Build a service whose settings resolve, so fetchEntityData reaches
     * OpenRegister instead of returning early on empty register/schema.
     *
     * @param ContainerInterface $container      The container to inject.
     * @param ActivityService    $activityService The activity service to inject.
     *
     * @return NoteEventService The configured service.
     */
    private function serviceWithResolvedSettings(
        ContainerInterface $container,
        ActivityService $activityService
    ): NoteEventService {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')->willReturn(
            [
                'register'      => 'pipelinq',
                'lead_schema'   => 'lead',
            ]
        );

        return new NoteEventService(
            $this->createMock(NotificationService::class),
            $activityService,
            $settingsService,
            $this->createMock(IUserSession::class),
            $container,
            $this->logger,
        );
    }//end serviceWithResolvedSettings()

    /**
     * An absent or broken OpenRegister must degrade to "no entity data"
     * rather than propagating, and must say so in the log.
     *
     * Guards the ADR-080 rewrite: the read is now an in-process
     * ObjectService call, so container resolution is the new failure mode
     * that the previous HTTP path did not have.
     *
     * @return void
     */
    public function testMissingOpenRegisterIsLoggedAndPublishesNothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('OpenRegister not installed'));

        $activityService = $this->createMock(ActivityService::class);
        // Assert on the ITEM: no activity is published, not merely that the
        // call returned.
        $activityService->expects($this->never())->method('publishNoteAdded');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Could not read note entity from OpenRegister'),
                $this->callback(
                    static fn (array $c): bool => $c['entityType'] === 'lead' && $c['objectId'] === 'lead-1'
                )
            );

        $service = $this->serviceWithResolvedSettings($container, $activityService);
        $service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: 'lead-1');
    }//end testMissingOpenRegisterIsLoggedAndPublishesNothing()

    /**
     * A plain array from ObjectService is used directly, and the object is
     * requested with the register and schema resolved from settings.
     *
     * @return void
     */
    public function testEntityIsReadThroughObjectServiceWithResolvedScope(): void
    {
        $objectService = new class {
            /**
             * Captured call arguments.
             *
             * @var array<string, string>
             */
            public array $seen = [];

            /**
             * @param string $id       The object id.
             * @param string $register The register slug.
             * @param string $schema   The schema slug.
             *
             * @return array The stub object.
             */
            public function find(string $id, string $register, string $schema): array
            {
                $this->seen = [
                    'id'       => $id,
                    'register' => $register,
                    'schema'   => $schema,
                ];
                return ['title' => 'Acme deal', 'assignee' => 'alice'];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $this->logger->expects($this->never())->method('warning');

        $activityService = $this->createMock(ActivityService::class);
        $activityService->expects($this->once())->method('publishNoteAdded');

        $service = $this->serviceWithResolvedSettings($container, $activityService);
        $service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: 'lead-7');

        // The scope must come from settings, not be left empty — an empty
        // register/schema is the permissive value in OpenRegister.
        self::assertSame(
            ['id' => 'lead-7', 'register' => 'pipelinq', 'schema' => 'lead'],
            $objectService->seen,
            'ObjectService must be called with the resolved register and schema'
        );
    }//end testEntityIsReadThroughObjectServiceWithResolvedScope()

    /**
     * An ObjectEntity-shaped return is normalised through jsonSerialize()
     * rather than being discarded.
     *
     * @return void
     */
    public function testJsonSerialisableEntityIsNormalised(): void
    {
        $objectService = new class {
            /**
             * @param string $id       The object id.
             * @param string $register The register slug.
             * @param string $schema   The schema slug.
             *
             * @return object The stub entity.
             */
            public function find(string $id, string $register, string $schema): object
            {
                return new class implements \JsonSerializable {
                    /**
                     * @return array The serialised object.
                     */
                    public function jsonSerialize(): array
                    {
                        return ['title' => 'Serialised deal', 'assignee' => 'bob'];
                    }
                };
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $activityService = $this->createMock(ActivityService::class);
        $activityService->expects($this->once())->method('publishNoteAdded');

        $service = $this->serviceWithResolvedSettings($container, $activityService);
        $service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: 'lead-8');
    }//end testJsonSerialisableEntityIsNormalised()

    /**
     * A value that is neither an array nor normalisable yields null, and
     * nothing is published.
     *
     * @return void
     */
    public function testUnnormalisableEntityPublishesNothing(): void
    {
        $objectService = new class {
            /**
             * @param string $id       The object id.
             * @param string $register The register slug.
             * @param string $schema   The schema slug.
             *
             * @return object The stub entity with no normalisation surface.
             */
            public function find(string $id, string $register, string $schema): object
            {
                return new \stdClass();
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $activityService = $this->createMock(ActivityService::class);
        $activityService->expects($this->never())->method('publishNoteAdded');

        $service = $this->serviceWithResolvedSettings($container, $activityService);
        $service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: 'lead-9');
    }//end testUnnormalisableEntityPublishesNothing()
}//end class
