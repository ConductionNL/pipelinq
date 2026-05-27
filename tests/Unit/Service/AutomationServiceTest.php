<?php

/**
 * Unit tests for AutomationService — SSRF guard and condition matching.
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

use OCA\Pipelinq\Service\AutomationService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationService.
 */
class AutomationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AutomationService
     */
    private AutomationService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig   = $this->createMock(IAppConfig::class);
        $userSession = $this->createMock(IUserSession::class);
        $logger      = $this->createMock(LoggerInterface::class);

        $this->service = new AutomationService($appConfig, $userSession, $logger);
    }//end setUp()

    /**
     * Test that loopback IPv4 is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksLoopback(): void
    {
        $result = $this->service->validateWebhookUrl('http://127.0.0.1/metadata');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Loopback', $result);
    }//end testValidateWebhookUrlBlocksLoopback()

    /**
     * Test that link-local IP is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksLinkLocal(): void
    {
        $result = $this->service->validateWebhookUrl('http://169.254.169.254/latest/meta-data/');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Link-local', $result);
    }//end testValidateWebhookUrlBlocksLinkLocal()

    /**
     * Test that RFC-1918 10.x range is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksRfc1918TenRange(): void
    {
        $result = $this->service->validateWebhookUrl('http://10.0.0.1/internal');
        $this->assertNotNull($result);
        $this->assertStringContainsString('RFC-1918', $result);
    }//end testValidateWebhookUrlBlocksRfc1918TenRange()

    /**
     * Test that RFC-1918 192.168.x range is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksRfc1918NatRange(): void
    {
        $result = $this->service->validateWebhookUrl('http://192.168.1.100/api');
        $this->assertNotNull($result);
        $this->assertStringContainsString('RFC-1918', $result);
    }//end testValidateWebhookUrlBlocksRfc1918NatRange()

    /**
     * Test that RFC-1918 172.16.x range is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksRfc1918DockerRange(): void
    {
        $result = $this->service->validateWebhookUrl('http://172.17.0.1/api');
        $this->assertNotNull($result);
        $this->assertStringContainsString('RFC-1918', $result);
    }//end testValidateWebhookUrlBlocksRfc1918DockerRange()

    /**
     * Test that non-http schemes (file://) are blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksFileScheme(): void
    {
        // file:/// has no host, so it is caught by the malformed-URL check.
        $result = $this->service->validateWebhookUrl('file:///etc/passwd');
        $this->assertNotNull($result);
    }//end testValidateWebhookUrlBlocksFileScheme()

    /**
     * Test that gopher scheme is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksGopherScheme(): void
    {
        $result = $this->service->validateWebhookUrl('gopher://internal.host/');
        $this->assertNotNull($result);
    }//end testValidateWebhookUrlBlocksGopherScheme()

    /**
     * Test that malformed URL is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksMalformedUrl(): void
    {
        $result = $this->service->validateWebhookUrl('not-a-url');
        $this->assertNotNull($result);
    }//end testValidateWebhookUrlBlocksMalformedUrl()

    /**
     * Test that IPv6 loopback is blocked.
     *
     * @return void
     */
    public function testValidateWebhookUrlBlocksIpv6Loopback(): void
    {
        $result = $this->service->validateWebhookUrl('http://[::1]/api');
        $this->assertNotNull($result);
    }//end testValidateWebhookUrlBlocksIpv6Loopback()

    /**
     * Test that fireWebhook skips empty URL.
     *
     * @return void
     */
    public function testFireWebhookSkipsEmptyUrl(): void
    {
        $result = $this->service->fireWebhook('', ['event' => 'test']);
        $this->assertSame('skipped', $result['status']);
    }//end testFireWebhookSkipsEmptyUrl()

    /**
     * Test that fireWebhook blocks SSRF URLs.
     *
     * @return void
     */
    public function testFireWebhookBlocksSsrfUrl(): void
    {
        $result = $this->service->fireWebhook('http://127.0.0.1/steal', ['event' => 'test']);
        $this->assertSame('blocked', $result['status']);
    }//end testFireWebhookBlocksSsrfUrl()

    /**
     * Test that matchesConditions returns false for inactive automation.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsFalseForInactive(): void
    {
        $automation = ['isActive' => false, 'trigger' => 'lead_created'];
        $this->assertFalse($this->service->matchesConditions($automation, 'lead_created', []));
    }//end testMatchesConditionsReturnsFalseForInactive()

    /**
     * Test that matchesConditions returns false for wrong trigger.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsFalseForWrongTrigger(): void
    {
        $automation = ['isActive' => true, 'trigger' => 'contact_created'];
        $this->assertFalse($this->service->matchesConditions($automation, 'lead_created', []));
    }//end testMatchesConditionsReturnsFalseForWrongTrigger()

    /**
     * Test that matchesConditions returns true when conditions match.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsTrueForMatch(): void
    {
        $automation = [
            'isActive'          => true,
            'trigger'           => 'lead_created',
            'triggerConditions' => ['stage' => 'new'],
        ];
        $this->assertTrue($this->service->matchesConditions($automation, 'lead_created', ['stage' => 'new']));
    }//end testMatchesConditionsReturnsTrueForMatch()
}//end class
