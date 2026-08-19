<?php

/**
 * Unit tests for ComplaintSlaService.
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplaintSlaService.
 */
class ComplaintSlaServiceTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var ComplaintSlaService
     */
    private ComplaintSlaService $service;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new ComplaintSlaService(
            $this->appConfig,
            $this->logger,
        );
    }//end setUp()

    /**
     * Test getSlaHoursForCategory returns configured hours.
     *
     * @return void
     */
    public function testGetSlaHoursReturnsConfiguredValue(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'complaint_sla_service', '')
            ->willReturn('48');

        $result = $this->service->getSlaHoursForCategory('service');

        $this->assertSame(48, $result);
    }//end testGetSlaHoursReturnsConfiguredValue()

    /**
     * Test getSlaHoursForCategory returns 0 when not configured.
     *
     * @return void
     */
    public function testGetSlaHoursReturnsZeroWhenNotConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'complaint_sla_billing', '')
            ->willReturn('');

        $result = $this->service->getSlaHoursForCategory('billing');

        $this->assertSame(0, $result);
    }//end testGetSlaHoursReturnsZeroWhenNotConfigured()

    /**
     * Test getSlaHoursForCategory returns 0 for invalid category.
     *
     * @return void
     */
    public function testGetSlaHoursReturnsZeroForInvalidCategory(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('warning');

        $result = $this->service->getSlaHoursForCategory('nonexistent');

        $this->assertSame(0, $result);
    }//end testGetSlaHoursReturnsZeroForInvalidCategory()

    /**
     * Test calculateDeadline returns a deadline with configured SLA.
     *
     * @return void
     */
    public function testCalculateDeadlineReturnsDeadlineWhenConfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'complaint_sla_service', '')
            ->willReturn('24');

        $from     = new \DateTimeImmutable('2026-03-25T10:00:00+00:00');
        $deadline = $this->service->calculateDeadline('service', $from);

        $this->assertNotNull($deadline);
        $this->assertEquals(
            new \DateTimeImmutable('2026-03-26T10:00:00+00:00'),
            $deadline,
        );
    }//end testCalculateDeadlineReturnsDeadlineWhenConfigured()

    /**
     * Test calculateDeadline returns null when no SLA is configured.
     *
     * @return void
     */
    public function testCalculateDeadlineReturnsNullWhenNoSla(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'complaint_sla_other', '')
            ->willReturn('');

        $deadline = $this->service->calculateDeadline('other');

        $this->assertNull($deadline);
    }//end testCalculateDeadlineReturnsNullWhenNoSla()

    /**
     * Test isOverdue returns true for past deadline with open status.
     *
     * @return void
     */
    public function testIsOverdueReturnsTrueForPastDeadline(): void
    {
        $complaint = [
            'slaDeadline' => '2026-03-20T10:00:00+00:00',
            'status'      => 'new',
        ];

        $now    = new \DateTimeImmutable('2026-03-25T10:00:00+00:00');
        $result = $this->service->isOverdue($complaint, $now);

        $this->assertTrue($result);
    }//end testIsOverdueReturnsTrueForPastDeadline()

    /**
     * Test isOverdue returns false for future deadline.
     *
     * @return void
     */
    public function testIsOverdueReturnsFalseForFutureDeadline(): void
    {
        $complaint = [
            'slaDeadline' => '2026-03-30T10:00:00+00:00',
            'status'      => 'in_progress',
        ];

        $now    = new \DateTimeImmutable('2026-03-25T10:00:00+00:00');
        $result = $this->service->isOverdue($complaint, $now);

        $this->assertFalse($result);
    }//end testIsOverdueReturnsFalseForFutureDeadline()

    /**
     * Test isOverdue returns false for resolved complaints.
     *
     * @return void
     */
    public function testIsOverdueReturnsFalseForResolvedComplaints(): void
    {
        $complaint = [
            'slaDeadline' => '2026-03-20T10:00:00+00:00',
            'status'      => 'resolved',
        ];

        $now    = new \DateTimeImmutable('2026-03-25T10:00:00+00:00');
        $result = $this->service->isOverdue($complaint, $now);

        $this->assertFalse($result);
    }//end testIsOverdueReturnsFalseForResolvedComplaints()

    /**
     * Test isOverdue returns false when no deadline is set.
     *
     * @return void
     */
    public function testIsOverdueReturnsFalseWithoutDeadline(): void
    {
        $complaint = [
            'status' => 'new',
        ];

        $result = $this->service->isOverdue($complaint);

        $this->assertFalse($result);
    }//end testIsOverdueReturnsFalseWithoutDeadline()

    /**
     * Test isOpenStatus returns correct values.
     *
     * @return void
     */
    public function testIsOpenStatusReturnsCorrectValues(): void
    {
        $this->assertTrue($this->service->isOpenStatus('new'));
        $this->assertTrue($this->service->isOpenStatus('in_progress'));
        $this->assertFalse($this->service->isOpenStatus('resolved'));
        $this->assertFalse($this->service->isOpenStatus('rejected'));
    }//end testIsOpenStatusReturnsCorrectValues()
}//end class
