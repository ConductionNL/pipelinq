<?php

/**
 * Unit tests for ContactVcardService::provisionContactFromForm.
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

use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the contact-FIRST provisioning used by client/contact create.
 */
class ContactVcardServiceTest extends TestCase
{
    /**
     * Build the service with the given collaborators.
     *
     * @param IContactsManager          $contacts The contacts manager mock.
     * @param ContactVcardWriterService $writer   The writer mock.
     *
     * @return ContactVcardService The service under test.
     */
    private function makeService(IContactsManager $contacts, ContactVcardWriterService $writer): ContactVcardService
    {
        return new ContactVcardService(
            $contacts,
            $this->createMock(IAppConfig::class),
            $this->createMock(ContainerInterface::class),
            $writer,
            new ContactVcardPropertyBuilder(
                $this->createMock(IAppConfig::class),
                $this->createMock(ContainerInterface::class)
            ),
            $this->createMock(LoggerInterface::class),
            $this->createMock(RegisterResolverService::class)
        );
    }//end makeService()

    /**
     * When no contact matches, a fresh vCard is written and its UID is returned
     * together with the identity mirror.
     *
     * @return void
     */
    public function testProvisionCreatesNewContactWhenNoMatch(): void
    {
        $contacts = $this->createMock(IContactsManager::class);
        $contacts->method('isEnabled')->willReturn(true);
        // No existing match.
        $contacts->method('search')->willReturn([]);

        $writer = $this->createMock(ContactVcardWriterService::class);
        $writer->expects($this->once())
            ->method('writeVcard')
            ->willReturn('new-uid-123');

        $service = $this->makeService($contacts, $writer);

        $result = $service->provisionContactFromForm(
            ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'acme@example.test', 'phone' => '+31 20 1'],
            'client'
        );

        $this->assertIsArray($result);
        $this->assertSame('new-uid-123', $result['contactsUid']);
        $this->assertSame('Acme BV', $result['name']);
        $this->assertSame('acme@example.test', $result['email']);
        $this->assertSame('+31 20 1', $result['phone']);
    }//end testProvisionCreatesNewContactWhenNoMatch()

    /**
     * When an existing contact matches on email, its UID is reused and NO new
     * vCard is written (no duplicate identity).
     *
     * @return void
     */
    public function testProvisionReusesExistingContactOnEmailMatch(): void
    {
        $contacts = $this->createMock(IContactsManager::class);
        $contacts->method('isEnabled')->willReturn(true);
        $contacts->method('search')->willReturn([['UID' => 'existing-uid-9', 'EMAIL' => 'acme@example.test']]);

        $writer = $this->createMock(ContactVcardWriterService::class);
        $writer->expects($this->never())->method('writeVcard');

        $service = $this->makeService($contacts, $writer);

        $result = $service->provisionContactFromForm(
            ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'acme@example.test'],
            'client'
        );

        $this->assertSame('existing-uid-9', $result['contactsUid']);
    }//end testProvisionReusesExistingContactOnEmailMatch()

    /**
     * When Contacts is disabled, provisioning returns null (caller surfaces a
     * clean error instead of a raw 400).
     *
     * @return void
     */
    public function testProvisionReturnsNullWhenContactsDisabled(): void
    {
        $contacts = $this->createMock(IContactsManager::class);
        $contacts->method('isEnabled')->willReturn(false);

        $writer = $this->createMock(ContactVcardWriterService::class);

        $service = $this->makeService($contacts, $writer);

        $this->assertNull($service->provisionContactFromForm(['name' => 'X'], 'client'));
    }//end testProvisionReturnsNullWhenContactsDisabled()

    /**
     * An invalid objectType is rejected (returns null) so the IAppConfig key is
     * never built from caller-supplied input.
     *
     * @return void
     */
    public function testProvisionRejectsInvalidObjectType(): void
    {
        $contacts = $this->createMock(IContactsManager::class);
        $writer   = $this->createMock(ContactVcardWriterService::class);

        $service = $this->makeService($contacts, $writer);

        $this->assertNull($service->provisionContactFromForm(['name' => 'X'], 'bogus'));
    }//end testProvisionRejectsInvalidObjectType()
}//end class
