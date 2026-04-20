<?php

/**
 * Unit tests for ProspectDiscoveryService.
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

use OCA\Pipelinq\Service\IcpConfigService;
use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCA\Pipelinq\Service\ProspectScoringService;
use OCA\Pipelinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProspectDiscoveryService.
 */
class ProspectDiscoveryServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ProspectDiscoveryService
     */
    private ProspectDiscoveryService $service;

    /**
     * Mock ICP config service.
     *
     * @var IcpConfigService
     */
    private IcpConfigService $icpConfig;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->icpConfig = $this->createMock(IcpConfigService::class);
        $kvkClient       = $this->createMock(KvkApiClient::class);
        $ocClient        = $this->createMock(OpenCorporatesApiClient::class);
        $scoring         = new ProspectScoringService();
        $settings        = $this->createMock(SettingsService::class);
        $logger          = $this->createMock(LoggerInterface::class);

        $settings->method('getConfigValue')->willReturn('');
        // createLeadFromProspect calls getObjectStoreConfig() which needs
        // register + client_schema + lead_schema set, otherwise the method
        // returns early with ['error' => ...]. Stub a minimal valid config
        // so the happy-path tests can exercise the leadData/clientData
        // construction.
        $settings->method('getSettings')->willReturn([
            'register'      => 'pipelinq',
            'client_schema' => 'client',
            'lead_schema'   => 'lead',
        ]);

        $this->service = new ProspectDiscoveryService(
            $this->icpConfig,
            $kvkClient,
            $ocClient,
            $scoring,
            $settings,
            $logger,
        );
    }//end setUp()

    /**
     * Test discover returns error when ICP not configured.
     *
     * @return void
     */
    public function testDiscoverReturnsErrorWhenNotConfigured(): void
    {
        $this->icpConfig->method('isConfigured')->willReturn(false);

        $result = $this->service->discover();

        $this->assertSame('no_icp_configured', $result['error']);
    }//end testDiscoverReturnsErrorWhenNotConfigured()

    /**
     * Test createLeadFromProspect returns data arrays.
     *
     * @return void
     */
    public function testCreateLeadFromProspectReturnsData(): void
    {
        $prospect = [
            'tradeName'      => 'Test BV',
            'kvkNumber'      => '12345678',
            'sbiDescription' => 'Software',
            'source'         => 'kvk',
            'address'        => 'Street 1',
        ];

        $result = $this->service->createLeadFromProspect($prospect);

        $this->assertSame('Test BV', $result['clientData']['name']);
        $this->assertSame('organization', $result['clientData']['type']);
        $this->assertSame('Test BV', $result['leadData']['title']);
    }//end testCreateLeadFromProspectReturnsData()

    /**
     * Test createLeadFromProspect with minimal data.
     *
     * @return void
     */
    public function testCreateLeadFromProspectMinimalData(): void
    {
        $result = $this->service->createLeadFromProspect([]);

        $this->assertSame('Unknown', $result['clientData']['name']);
        $this->assertSame('New Lead', $result['leadData']['title']);
    }//end testCreateLeadFromProspectMinimalData()
}//end class
