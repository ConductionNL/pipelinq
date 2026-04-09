<?php

/**
 * Unit tests for IcpConfigService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IcpConfigReader;
use OCA\Pipelinq\Service\IcpConfigService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IcpConfigService.
 */
class IcpConfigServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var IcpConfigService
     */
    private IcpConfigService $service;

    /**
     * Mock reader.
     *
     * @var IcpConfigReader
     */
    private IcpConfigReader $reader;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->reader  = $this->createMock(IcpConfigReader::class);
        $this->service = new IcpConfigService($this->reader);
    }//end setUp()

    /**
     * Test isConfigured returns true when SBI codes exist.
     *
     * @return void
     */
    public function testIsConfiguredTrueWithSbiCodes(): void
    {
        $this->reader->method('getJsonArray')->willReturn(['6201']);

        $this->assertTrue($this->service->isConfigured());
    }//end testIsConfiguredTrueWithSbiCodes()

    /**
     * Test isConfigured returns false when no SBI codes.
     *
     * @return void
     */
    public function testIsConfiguredFalseEmpty(): void
    {
        $this->reader->method('getJsonArray')->willReturn([]);

        $this->assertFalse($this->service->isConfigured());
    }//end testIsConfiguredFalseEmpty()

    /**
     * Test getSettings returns all ICP settings.
     *
     * @return void
     */
    public function testGetSettingsReturnsAll(): void
    {
        $this->reader->method('getJsonArray')->willReturn([]);
        $this->reader->method('getInt')->willReturn(0);
        $this->reader->method('isBoolTrue')->willReturn(true);
        $this->reader->method('getString')->willReturn('');

        $result = $this->service->getSettings();

        $this->assertArrayHasKey('sbiCodes', $result);
        $this->assertArrayHasKey('employeeCountMin', $result);
        $this->assertArrayHasKey('provinces', $result);
        $this->assertArrayHasKey('excludeInactive', $result);
        $this->assertArrayHasKey('kvkApiKey', $result);
        $this->assertArrayHasKey('openCorporatesEnabled', $result);
    }//end testGetSettingsReturnsAll()

    /**
     * Test getSettings masks API key.
     *
     * @return void
     */
    public function testGetSettingsMasksApiKey(): void
    {
        $this->reader->method('getJsonArray')->willReturn([]);
        $this->reader->method('getInt')->willReturn(0);
        $this->reader->method('isBoolTrue')->willReturn(true);
        $this->reader->method('getString')->willReturn('secret-key-123');

        $result = $this->service->getSettings();

        $this->assertSame('***configured***', $result['kvkApiKey']);
    }//end testGetSettingsMasksApiKey()

    /**
     * Test getCriteria returns criteria array.
     *
     * @return void
     */
    public function testGetCriteriaReturnsArray(): void
    {
        $this->reader->method('getJsonArray')->willReturn(['6201']);
        $this->reader->method('getInt')->willReturn(10);
        $this->reader->method('isBoolTrue')->willReturn(true);

        $result = $this->service->getCriteria();

        $this->assertArrayHasKey('sbiCodes', $result);
        $this->assertArrayHasKey('employeeCountMin', $result);
        $this->assertArrayHasKey('excludeInactive', $result);
    }//end testGetCriteriaReturnsArray()

    /**
     * Test getIcpHash returns 8 char hash.
     *
     * @return void
     */
    public function testGetIcpHashReturns8Chars(): void
    {
        $this->reader->method('getString')->willReturn('test');

        $hash = $this->service->getIcpHash();

        $this->assertSame(8, strlen($hash));
    }//end testGetIcpHashReturns8Chars()

    /**
     * Test saveSettings saves fields and returns hash.
     *
     * @return void
     */
    public function testSaveSettingsReturnsHash(): void
    {
        $this->reader->method('getString')->willReturn('');

        $hash = $this->service->saveSettings([
            'sbiCodes'         => ['62'],
            'employeeCountMin' => 10,
            'excludeInactive'  => true,
        ]);

        $this->assertSame(8, strlen($hash));
    }//end testSaveSettingsReturnsHash()

    /**
     * Test saveSettings does not overwrite masked API key.
     *
     * @return void
     */
    public function testSaveSettingsSkipsMaskedApiKey(): void
    {
        $this->reader->expects($this->never())
            ->method('setString')
            ->with('icp_kvk_api_key', $this->anything());

        $this->reader->method('getString')->willReturn('');

        $this->service->saveSettings([
            'kvkApiKey' => '***configured***',
        ]);
    }//end testSaveSettingsSkipsMaskedApiKey()
}//end class
