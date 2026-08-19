<?php

/**
 * Unit tests for ContactVcardWriterService's fail-closed handling of an
 * unconfigured register / {objectType}_schema.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * storeContactsUidOnObject() wrote back with an unchecked schema.
 *
 * The config key is built by interpolating $objectType, so an unexpected type
 * resolves to a key that does not exist and yields the same empty id. An empty
 * id is not "no id" to OpenRegister — ObjectService skips setSchema() on '',
 * so the write would land in the request's leftover schema context.
 */
class ContactVcardWriterServiceGuardTest extends TestCase
{
    /**
     * Build the service over a config map, with a stub addressbook that
     * returns a fresh UID so the write-back path is reached.
     *
     * @param array<string, string> $config   The app-config contents.
     * @param ObjectService         $object   The ObjectService mock.
     * @param string                $register The resolved register id.
     *
     * @return ContactVcardWriterService
     */
    private function buildService(
        array $config,
        ObjectService $object,
        string $register='reg-1'
    ): ContactVcardWriterService {
        $addressBook = new class {
            /**
             * Stub createOrUpdate returning a card with a fresh UID.
             *
             * @param array<string, mixed> $properties The vCard properties.
             *
             * @return array<string, mixed>
             */
            public function createOrUpdate(array $properties): array
            {
                return ['UID' => 'nc-uid-1'];
            }//end createOrUpdate()
        };

        $contacts = $this->createMock(IContactsManager::class);
        $contacts->method('getUserAddressBooks')->willReturn([$addressBook]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($config): string {
                return ($config[$key] ?? $default);
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($object);

        $resolver = $this->createMock(RegisterResolverService::class);
        $resolver->method('resolve')->willReturn($register);

        return new ContactVcardWriterService(
            $contacts,
            $appConfig,
            $container,
            $this->createMock(LoggerInterface::class),
            $resolver
        );
    }//end buildService()

    /**
     * An unset {objectType}_schema refuses the write-back.
     *
     * @return void
     */
    public function testMissingSchemaRefusesTheWriteBack(): void
    {
        $object = $this->createMock(ObjectService::class);
        $object->expects($this->never())->method('saveObject');

        $service = $this->buildService([], $object);

        $uid = $service->writeToAddressBook(['FN' => 'Alice'], ['id' => 'o-1'], 'contact');

        $this->assertSame('nc-uid-1', $uid);
    }//end testMissingSchemaRefusesTheWriteBack()

    /**
     * An unresolvable register refuses the write-back even when the schema
     * is configured.
     *
     * @return void
     */
    public function testMissingRegisterRefusesTheWriteBack(): void
    {
        $object = $this->createMock(ObjectService::class);
        $object->expects($this->never())->method('saveObject');

        $service = $this->buildService(['contact_schema' => 'sch-contact'], $object, '');

        $uid = $service->writeToAddressBook(['FN' => 'Alice'], ['id' => 'o-1'], 'contact');

        $this->assertSame('nc-uid-1', $uid);
    }//end testMissingRegisterRefusesTheWriteBack()

    /**
     * An unexpected object type resolves to a config key that does not exist,
     * which is the same empty-schema hazard reached by a different route.
     *
     * @return void
     */
    public function testUnknownObjectTypeRefusesTheWriteBack(): void
    {
        $object = $this->createMock(ObjectService::class);
        $object->expects($this->never())->method('saveObject');

        $service = $this->buildService(['contact_schema' => 'sch-contact'], $object);

        $uid = $service->writeToAddressBook(['FN' => 'Alice'], ['id' => 'o-1'], 'nonsense');

        $this->assertSame('nc-uid-1', $uid);
    }//end testUnknownObjectTypeRefusesTheWriteBack()

    /**
     * A configured instance does write back — otherwise the negative
     * assertions above would pass for the wrong reason.
     *
     * @return void
     */
    public function testConfiguredWriteBackReachesOpenRegister(): void
    {
        $object = $this->createMock(ObjectService::class);
        $object->expects($this->once())->method('saveObject');

        $service = $this->buildService(['contact_schema' => 'sch-contact'], $object);

        $uid = $service->writeToAddressBook(['FN' => 'Alice'], ['id' => 'o-1'], 'contact');

        $this->assertSame('nc-uid-1', $uid);
    }//end testConfiguredWriteBackReachesOpenRegister()
}//end class
