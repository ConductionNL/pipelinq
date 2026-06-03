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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-3
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-4
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-5
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-6
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-7
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-8
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-9
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EmailSyncService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmailSyncService.
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
     * Mock IConfig.
     *
     * @var IConfig
     */
    private IConfig $config;

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
        $this->config  = $this->createMock(IConfig::class);
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new EmailSyncService($this->config, $this->logger);
    }//end setUp()

    /**
     * Test extractDomain returns the domain portion of a valid email.
     *
     * @return void
     */
    public function testExtractDomainReturnsLowercaseDomain(): void
    {
        $result = $this->service->extractDomain('User@Example.COM');
        $this->assertSame('example.com', $result);
    }//end testExtractDomainReturnsLowercaseDomain()

    /**
     * Test extractDomain returns null for an invalid email.
     *
     * @return void
     */
    public function testExtractDomainReturnsNullForInvalidEmail(): void
    {
        $result = $this->service->extractDomain('not-an-email');
        $this->assertNull($result);
    }//end testExtractDomainReturnsNullForInvalidEmail()

    /**
     * Test getSyncAccounts returns stored account IDs.
     *
     * @return void
     */
    public function testGetSyncAccountsReturnsStoredIds(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_accounts', '[]')
            ->willReturn('[1,2,3]');

        $result = $this->service->getSyncAccounts(userId: 'user1');
        $this->assertSame([1, 2, 3], $result);
    }//end testGetSyncAccountsReturnsStoredIds()

    /**
     * Test getSyncAccounts returns empty array when no accounts stored.
     *
     * @return void
     */
    public function testGetSyncAccountsReturnsEmptyWhenNotSet(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_accounts', '[]')
            ->willReturn('[]');

        $result = $this->service->getSyncAccounts(userId: 'user1');
        $this->assertSame([], $result);
    }//end testGetSyncAccountsReturnsEmptyWhenNotSet()

    /**
     * Test isSyncEnabled returns true when stored value is 'true'.
     *
     * @return void
     */
    public function testIsSyncEnabledReturnsTrueWhenEnabled(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_enabled', 'false')
            ->willReturn('true');

        $this->assertTrue($this->service->isSyncEnabled(userId: 'user1'));
    }//end testIsSyncEnabledReturnsTrueWhenEnabled()

    /**
     * Test isSyncEnabled returns false when stored value is 'false'.
     *
     * @return void
     */
    public function testIsSyncEnabledReturnsFalseWhenDisabled(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_enabled', 'false')
            ->willReturn('false');

        $this->assertFalse($this->service->isSyncEnabled(userId: 'user1'));
    }//end testIsSyncEnabledReturnsFalseWhenDisabled()

    /**
     * Test isSyncEnabled defaults to false when not set.
     *
     * @return void
     */
    public function testIsSyncEnabledDefaultsToFalse(): void
    {
        $this->config->method('getUserValue')
            ->with('user2', 'pipelinq', 'email_sync_enabled', 'false')
            ->willReturn('false');

        $this->assertFalse($this->service->isSyncEnabled(userId: 'user2'));
    }//end testIsSyncEnabledDefaultsToFalse()

    /**
     * Test setSyncEnabled stores 'true' when enabled.
     *
     * @return void
     */
    public function testSetSyncEnabledStoresTrueString(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('user1', 'pipelinq', 'email_sync_enabled', 'true');

        $this->service->setSyncEnabled(userId: 'user1', enabled: true);
    }//end testSetSyncEnabledStoresTrueString()

    /**
     * Test setSyncEnabled stores 'false' when disabled.
     *
     * @return void
     */
    public function testSetSyncEnabledStoresFalseString(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('user1', 'pipelinq', 'email_sync_enabled', 'false');

        $this->service->setSyncEnabled(userId: 'user1', enabled: false);
    }//end testSetSyncEnabledStoresFalseString()

    /**
     * Test setSyncAccounts persists the provided account IDs as JSON.
     *
     * @return void
     */
    public function testSetSyncAccountsPersistsJson(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('user1', 'pipelinq', 'email_sync_accounts', json_encode([1, 2]));

        $this->service->setSyncAccounts(userId: 'user1', accounts: [1, 2]);
    }//end testSetSyncAccountsPersistsJson()

    /**
     * Test getLastSyncTime returns stored timestamp.
     *
     * @return void
     */
    public function testGetLastSyncTimeReturnsStoredValue(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_last', '')
            ->willReturn('2026-01-01T12:00:00+00:00');

        $result = $this->service->getLastSyncTime(userId: 'user1');
        $this->assertSame('2026-01-01T12:00:00+00:00', $result);
    }//end testGetLastSyncTimeReturnsStoredValue()

    /**
     * Test getLastSyncTime returns null when not set.
     *
     * @return void
     */
    public function testGetLastSyncTimeReturnsNullWhenEmpty(): void
    {
        $this->config->method('getUserValue')
            ->with('user1', 'pipelinq', 'email_sync_last', '')
            ->willReturn('');

        $result = $this->service->getLastSyncTime(userId: 'user1');
        $this->assertNull($result);
    }//end testGetLastSyncTimeReturnsNullWhenEmpty()

    /**
     * Test updateLastSyncTime stores an ISO 8601 timestamp.
     *
     * @return void
     */
    public function testUpdateLastSyncTimeStoresTimestamp(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(
                'user1',
                'pipelinq',
                'email_sync_last',
                $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/')
            );

        $this->service->updateLastSyncTime(userId: 'user1');
    }//end testUpdateLastSyncTimeStoresTimestamp()

    /**
     * Test buildEmailLinkData returns the expected array structure.
     *
     * @return void
     */
    public function testBuildEmailLinkDataReturnsCorrectShape(): void
    {
        $result = $this->service->buildEmailLinkData(
            messageId: 'msg-1',
            subject: 'Hello',
            sender: 'sender@example.com',
            recipients: ['r@example.com'],
            date: '2026-01-01',
            linkedEntityType: 'contact',
            linkedEntityId: 'uuid-1',
            direction: 'inbound',
            threadId: 'thread-1',
            syncSource: 'account-1',
        );

        $this->assertSame('msg-1', $result['messageId']);
        $this->assertSame('Hello', $result['subject']);
        $this->assertSame('sender@example.com', $result['sender']);
        $this->assertSame(['r@example.com'], $result['recipients']);
        $this->assertSame('2026-01-01', $result['date']);
        $this->assertSame('contact', $result['linkedEntityType']);
        $this->assertSame('uuid-1', $result['linkedEntityId']);
        $this->assertSame('inbound', $result['direction']);
        $this->assertSame('thread-1', $result['threadId']);
        $this->assertSame('account-1', $result['syncSource']);
        $this->assertFalse($result['excluded']);
        $this->assertFalse($result['deleted']);
    }//end testBuildEmailLinkDataReturnsCorrectShape()

    /**
     * Test buildEmailLinkData handles null optional parameters.
     *
     * @return void
     */
    public function testBuildEmailLinkDataHandlesNullOptionals(): void
    {
        $result = $this->service->buildEmailLinkData(
            messageId: 'msg-2',
            subject: 'Test',
            sender: 'a@b.com',
            recipients: [],
            date: '2026-01-02',
            linkedEntityType: 'client',
            linkedEntityId: 'uuid-2',
            direction: 'outbound',
        );

        $this->assertNull($result['threadId']);
        $this->assertNull($result['syncSource']);
    }//end testBuildEmailLinkDataHandlesNullOptionals()
}//end class
