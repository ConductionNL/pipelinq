<?php

/**
 * Unit tests for ContactSyncService::createWithContact (contact-FIRST create).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactImportService;
use OCA\Pipelinq\Service\ContactLinkedUidsService;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests that the contact-FIRST create provisions the NC contact and saves the
 * object with the required contactsUid + identity mirror.
 */
class ContactSyncServiceCreateTest extends TestCase
{
    /**
     * A happy-path create provisions the contact, then saves the object with
     * contactsUid + mirrored name/email/phone.
     *
     * @return void
     */
    public function testCreateWithContactSavesContactsUidAndMirror(): void
    {
        $vcard = $this->createMock(ContactVcardService::class);
        $vcard->expects($this->once())
            ->method('provisionContactFromForm')
            ->with($this->arrayHasKey('name'), 'client')
            ->willReturn([
                'contactsUid' => 'uid-42',
                'name'        => 'Acme BV',
                'email'       => 'acme@example.test',
                'phone'       => '+31 20 1',
            ]);

        // Capture the payload saved to OpenRegister.
        $captured      = null;
        $objectService = new class($captured) {
            /**
             * @var mixed
             */
            public $captured;

            /**
             * @param mixed $captured Reference holder.
             */
            public function __construct(&$captured)
            {
                $this->captured =& $captured;
            }

            /**
             * @param array  $data      The object data.
             * @param array  $extend    The extend list.
             * @param string $register  The register.
             * @param string $schema    The schema.
             * @param mixed  $uuid      The uuid.
             *
             * @return array The saved object.
             */
            public function saveObject(array $data, array $extend, string $register, string $schema, $uuid): array
            {
                $this->captured = $data;
                return array_merge(['id' => 'obj-1'], $data);
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key): string {
                if ($key === 'register') {
                    return '16';
                }

                if ($key === 'client_schema') {
                    return '60';
                }

                return '';
            }
        );

        $service = new ContactSyncService(
            $this->createMock(IContactsManager::class),
            $this->createMock(ContactImportService::class),
            $vcard,
            $this->createMock(ContactLinkedUidsService::class),
            $appConfig,
            $container
        );

        $created = $service->createWithContact('client', ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'acme@example.test', 'phone' => '+31 20 1']);

        $this->assertSame('obj-1', $created['id']);
        $this->assertSame('uid-42', $created['contactsUid']);
        $this->assertSame('Acme BV', $created['name']);
        $this->assertSame('acme@example.test', $created['email']);
        $this->assertSame('+31 20 1', $objectService->captured['phone']);
        $this->assertSame('uid-42', $objectService->captured['contactsUid']);
    }//end testCreateWithContactSavesContactsUidAndMirror()

    /**
     * A blank name is rejected before any contact is provisioned.
     *
     * @return void
     */
    public function testCreateWithContactRejectsBlankName(): void
    {
        $vcard = $this->createMock(ContactVcardService::class);
        $vcard->expects($this->never())->method('provisionContactFromForm');

        $service = new ContactSyncService(
            $this->createMock(IContactsManager::class),
            $this->createMock(ContactImportService::class),
            $vcard,
            $this->createMock(ContactLinkedUidsService::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ContainerInterface::class)
        );

        $this->expectException(RuntimeException::class);
        $service->createWithContact('client', ['name' => '   ']);
    }//end testCreateWithContactRejectsBlankName()

    /**
     * When the contact cannot be provisioned, a RuntimeException is raised (no
     * object is saved without a contactsUid).
     *
     * @return void
     */
    public function testCreateWithContactThrowsWhenProvisionFails(): void
    {
        $vcard = $this->createMock(ContactVcardService::class);
        $vcard->method('provisionContactFromForm')->willReturn(null);

        $service = new ContactSyncService(
            $this->createMock(IContactsManager::class),
            $this->createMock(ContactImportService::class),
            $vcard,
            $this->createMock(ContactLinkedUidsService::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ContainerInterface::class)
        );

        $this->expectException(RuntimeException::class);
        $service->createWithContact('client', ['name' => 'Acme BV']);
    }//end testCreateWithContactThrowsWhenProvisionFails()
}//end class
