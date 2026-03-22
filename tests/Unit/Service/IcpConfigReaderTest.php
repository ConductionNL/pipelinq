<?php

/**
 * Unit tests for IcpConfigReader.
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IcpConfigReader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IcpConfigReader.
 */
class IcpConfigReaderTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The service under test.
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
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->reader    = new IcpConfigReader(appConfig: $this->appConfig);
    }//end setUp()

    /**
     * Test getString returns the configured value.
     *
     * @return void
     */
    public function testGetStringReturnsConfiguredValue(): void
    {
        $this->appConfig->method('getValueString')->with(Application::APP_ID, 'k', '')->willReturn('v');

        $this->assertSame('v', $this->reader->getString(key: 'k'));
    }//end testGetStringReturnsConfiguredValue()

    /**
     * Test getJsonArray decodes stored array.
     *
     * @return void
     */
    public function testGetJsonArrayDecodesStoredArray(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["a","b"]');

        $this->assertSame(['a', 'b'], $this->reader->getJsonArray(key: 'k'));
    }//end testGetJsonArrayDecodesStoredArray()

    /**
     * Test getJsonArray returns empty array on invalid JSON.
     *
     * @return void
     */
    public function testGetJsonArrayReturnsEmptyOnInvalidJson(): void
    {
        $this->appConfig->method('getValueString')->willReturn('not-json');

        $this->assertSame([], $this->reader->getJsonArray(key: 'k'));
    }//end testGetJsonArrayReturnsEmptyOnInvalidJson()

    /**
     * Test isBoolTrue returns true for 'true'.
     *
     * @return void
     */
    public function testIsBoolTrueForTrueString(): void
    {
        $this->appConfig->method('getValueString')->willReturn('true');

        $this->assertTrue($this->reader->isBoolTrue(key: 'k'));
    }//end testIsBoolTrueForTrueString()

    /**
     * Test isBoolTrue returns false for 'false'.
     *
     * @return void
     */
    public function testIsBoolFalseForFalseString(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');

        $this->assertFalse($this->reader->isBoolTrue(key: 'k'));
    }//end testIsBoolFalseForFalseString()

    /**
     * Test getInt converts string to integer.
     *
     * @return void
     */
    public function testGetIntConvertsString(): void
    {
        $this->appConfig->method('getValueString')->willReturn('7');

        $this->assertSame(7, $this->reader->getInt(key: 'k'));
    }//end testGetIntConvertsString()

    /**
     * Test setBool stores 'true' string.
     *
     * @return void
     */
    public function testSetBoolStoresTrueString(): void
    {
        $this->appConfig->expects($this->once())->method('setValueString')->with(Application::APP_ID, 'k', 'true');

        $this->reader->setBool(key: 'k', value: true);
    }//end testSetBoolStoresTrueString()

    /**
     * Test setInt stores integer as string.
     *
     * @return void
     */
    public function testSetIntStoresAsString(): void
    {
        $this->appConfig->expects($this->once())->method('setValueString')->with(Application::APP_ID, 'k', '5');

        $this->reader->setInt(key: 'k', value: 5);
    }//end testSetIntStoresAsString()

    /**
     * Test setJsonArray stores array as JSON.
     *
     * @return void
     */
    public function testSetJsonArrayStoresAsJson(): void
    {
        $this->appConfig->expects($this->once())->method('setValueString')->with(Application::APP_ID, 'k', '["x"]');

        $this->reader->setJsonArray(key: 'k', value: ['x']);
    }//end testSetJsonArrayStoresAsJson()
}//end class
