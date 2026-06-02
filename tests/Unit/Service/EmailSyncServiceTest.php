<?php

/**
 * Unit tests for EmailSyncService.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmailSyncService.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.1
 */
class EmailSyncServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var EmailSyncService
     */
    private EmailSyncService $service;

    /**
     * Mock user config.
     *
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config  = $this->createMock(IConfig::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $this->service = new EmailSyncService(
            $this->config,
            $this->logger,
        );
    }//end setUp()

    // --- isPublicDomain ---

    /**
     * Test that gmail.com is recognised as a public domain.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainTrueForGmail(): void
    {
        $this->assertTrue($this->service->isPublicDomain('gmail.com'));
    }//end testIsPublicDomainTrueForGmail()

    /**
     * Test that outlook.com is recognised as a public domain.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainTrueForOutlook(): void
    {
        $this->assertTrue($this->service->isPublicDomain('outlook.com'));
    }//end testIsPublicDomainTrueForOutlook()

    /**
     * Test that hotmail.com is recognised as a public domain.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainTrueForHotmail(): void
    {
        $this->assertTrue($this->service->isPublicDomain('hotmail.com'));
    }//end testIsPublicDomainTrueForHotmail()

    /**
     * Test that yahoo.com is recognised as a public domain.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainTrueForYahoo(): void
    {
        $this->assertTrue($this->service->isPublicDomain('yahoo.com'));
    }//end testIsPublicDomainTrueForYahoo()

    /**
     * Test that a corporate domain is NOT flagged as public.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainFalseForCorporate(): void
    {
        $this->assertFalse($this->service->isPublicDomain('gemeente-utrecht.nl'));
    }//end testIsPublicDomainFalseForCorporate()

    /**
     * Test domain check is case-insensitive.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testIsPublicDomainCaseInsensitive(): void
    {
        $this->assertTrue($this->service->isPublicDomain('GMAIL.COM'));
    }//end testIsPublicDomainCaseInsensitive()

    // --- extractDomain ---

    /**
     * Test that a valid email address extracts the domain.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testExtractDomainReturnsLowercase(): void
    {
        $this->assertSame('example.nl', $this->service->extractDomain('User@Example.NL'));
    }//end testExtractDomainReturnsLowercase()

    /**
     * Test that an invalid email address returns null.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testExtractDomainReturnsNullForInvalid(): void
    {
        $this->assertNull($this->service->extractDomain('not-an-email'));
    }//end testExtractDomainReturnsNullForInvalid()

    // --- matchEmailToEntities ---

    /**
     * Test that an exact address match returns the correct entity.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchEmailToEntitiesReturnsMatch(): void
    {
        $objectService = $this->createMockObjectService(
            contacts: [['uuid' => 'contact-uuid-1', 'email' => 'jan@example.nl']],
            clients:  [],
        );

        $result = $this->service->matchEmailToEntities('jan@example.nl', $objectService);

        $this->assertCount(1, $result);
        $this->assertSame('contact', $result[0]['entityType']);
        $this->assertSame('contact-uuid-1', $result[0]['entityId']);
    }//end testMatchEmailToEntitiesReturnsMatch()

    /**
     * Test that an unknown address returns empty and creates no link.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchEmailToEntitiesReturnsEmptyForUnknown(): void
    {
        $objectService = $this->createMockObjectService(contacts: [], clients: []);

        $result = $this->service->matchEmailToEntities('unknown@nowhere.com', $objectService);

        $this->assertEmpty($result);
    }//end testMatchEmailToEntitiesReturnsEmptyForUnknown()

    /**
     * Test that a client match is also returned.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchEmailToEntitiesIncludesClientMatches(): void
    {
        $objectService = $this->createMockObjectService(
            contacts: [],
            clients:  [['uuid' => 'client-uuid-1', 'email' => 'info@company.nl']],
        );

        $result = $this->service->matchEmailToEntities('info@company.nl', $objectService);

        $this->assertCount(1, $result);
        $this->assertSame('client', $result[0]['entityType']);
        $this->assertSame('client-uuid-1', $result[0]['entityId']);
    }//end testMatchEmailToEntitiesIncludesClientMatches()

    // --- matchDomainToOrganization ---

    /**
     * Test that a public domain returns null (no match attempted).
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchDomainToOrganizationReturnsNullForPublicDomain(): void
    {
        $objectService = $this->createMockObjectService(contacts: [], clients: []);

        $result = $this->service->matchDomainToOrganization('gmail.com', $objectService);

        $this->assertNull($result);
    }//end testMatchDomainToOrganizationReturnsNullForPublicDomain()

    /**
     * Test that a corporate domain with a matching client returns a match.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchDomainToOrganizationReturnsCorporateMatch(): void
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $objectService->method('findObjects')
            ->willReturn([['uuid' => 'client-domain-1']]);

        $result = $this->service->matchDomainToOrganization('gemeente-utrecht.nl', $objectService);

        $this->assertNotNull($result);
        $this->assertSame('client', $result['entityType']);
        $this->assertSame('client-domain-1', $result['entityId']);
    }//end testMatchDomainToOrganizationReturnsCorporateMatch()

    /**
     * Test that an unmatched corporate domain returns null.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testMatchDomainToOrganizationReturnsNullWhenNoMatch(): void
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $objectService->method('findObjects')
            ->willReturn([]);

        $result = $this->service->matchDomainToOrganization('unknown-corp.nl', $objectService);

        $this->assertNull($result);
    }//end testMatchDomainToOrganizationReturnsNullWhenNoMatch()

    // --- config helpers ---

    /**
     * Test that sync can be enabled and read back.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testSetAndGetSyncEnabled(): void
    {
        $this->config
            ->method('getUserValue')
            ->willReturn('true');

        $this->service->setSyncEnabled('user1', true);
        $this->assertTrue($this->service->isSyncEnabled('user1'));
    }//end testSetAndGetSyncEnabled()

    /**
     * Test that sync disabled state is read correctly.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.1
     */
    public function testSyncDisabledByDefault(): void
    {
        $this->config
            ->method('getUserValue')
            ->willReturn('false');

        $this->assertFalse($this->service->isSyncEnabled('user1'));
    }//end testSyncDisabledByDefault()

    /**
     * Build a partial mock object service that returns supplied contacts/clients.
     *
     * @param array $contacts Contacts to return for 'contact' schema queries.
     * @param array $clients  Clients to return for 'client' schema queries.
     *
     * @return object
     */
    private function createMockObjectService(array $contacts, array $clients): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $mock->method('findObjects')
            ->willReturnCallback(
                static function (string $register, string $schema) use ($contacts, $clients): array {
                    return match ($schema) {
                        'contact' => $contacts,
                        'client'  => $clients,
                        default   => [],
                    };
                }
            );

        return $mock;
    }//end createMockObjectService()
}//end class
