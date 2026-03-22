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
     * Test getString returns configured value.
     *
     * @return void
     */
    public function testGetStringReturnsValue(): void
    {
        $this->appConfig->method('getValueString')->with(Application::APP_ID, 'k', '')->willReturn('v');

        $this->assertSame('v', $this->reader->getString(key: 'k'));
    }//end testGetStringReturnsValue()

    /**
     * Test getJsonArray decodes stored JSON.
     *
     * @return void
     */
    public function testGetJsonArrayDecodesJson(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["a"]');

        $this->assertSame(['a'], $this->reader->getJsonArray(key: 'k'));
    }//end testGetJsonArrayDecodesJson()

    /**
     * Test getJsonArray returns empty array for invalid JSON.
     *
     * @return void
     */
    public function testGetJsonArrayReturnsEmptyForInvalidJson(): void
    {
        $this->appConfig->method('getValueString')->willReturn('bad');

        $this->assertSame([], $this->reader->getJsonArray(key: 'k'));
    }//end testGetJsonArrayReturnsEmptyForInvalidJson()

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
     * Test getInt converts string to int.
     * Test that getString returns the configured value.
     *
     * @return void
     */
    public function testGetStringReturnsConfiguredValue(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'my_key', '')
            ->willReturn('hello');

        $this->assertSame('hello', $this->reader->getString(key: 'my_key'));
    }//end testGetStringReturnsConfiguredValue()

    /**
     * Test that getString returns the default when not configured.
     *
     * @return void
     */
    public function testGetStringReturnsDefault(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'missing_key', 'fallback')
            ->willReturn('fallback');

        $this->assertSame('fallback', $this->reader->getString(key: 'missing_key', default: 'fallback'));
    }//end testGetStringReturnsDefault()

    /**
     * Test that setString delegates to appConfig.
     *
     * @return void
     */
    public function testSetStringDelegatesToAppConfig(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'store_key', 'store_value');

        $this->reader->setString(key: 'store_key', value: 'store_value');
    }//end testSetStringDelegatesToAppConfig()

    /**
     * Test that getJsonArray decodes and returns the stored array.
     *
     * @return void
     */
    public function testGetJsonArrayDecodesStoredArray(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'json_key', '[]')
            ->willReturn('["a","b","c"]');

        $this->assertSame(['a', 'b', 'c'], $this->reader->getJsonArray(key: 'json_key'));
    }//end testGetJsonArrayDecodesStoredArray()

    /**
     * Test that getJsonArray returns empty array for invalid JSON.
        $result = $this->reader->getJsonArray(key: 'json_key');

        $this->assertSame(['a', 'b', 'c'], $result);
    }//end testGetJsonArrayDecodesStoredArray()

    /**
     * Test that getJsonArray returns empty array when value is not valid JSON.
     *
     * @return void
     */
    public function testGetJsonArrayReturnsEmptyArrayOnInvalidJson(): void
    {
        $this->appConfig->method('getValueString')->willReturn('not-valid-json');

        $this->assertSame([], $this->reader->getJsonArray(key: 'bad_key'));
        $this->appConfig
            ->method('getValueString')
            ->willReturn('not-valid-json');

        $result = $this->reader->getJsonArray(key: 'bad_key');

        $this->assertSame([], $result);
    }//end testGetJsonArrayReturnsEmptyArrayOnInvalidJson()

    /**
     * Test that isBoolTrue returns true when value is 'true'.
     *
     * @return void
     */
    public function testIsBoolTrueReturnsTrueForTrueString(): void
    {
        $this->appConfig->method('getValueString')->willReturn('true');
        $this->appConfig
            ->method('getValueString')
            ->willReturn('true');

        $this->assertTrue($this->reader->isBoolTrue(key: 'flag_key'));
    }//end testIsBoolTrueReturnsTrueForTrueString()

    /**
     * Test that isBoolTrue returns false when value is 'false'.
     *
     * @return void
     */
    public function testIsBoolTrueReturnsFalseForFalseString(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');
        $this->appConfig
            ->method('getValueString')
            ->willReturn('false');

        $this->assertFalse($this->reader->isBoolTrue(key: 'flag_key'));
    }//end testIsBoolTrueReturnsFalseForFalseString()

    /**
     * Test that getInt converts stored string to integer.
     *
     * @return void
     */
    public function testGetIntConvertsStringToInt(): void
    {
        $this->appConfig->method('getValueString')->willReturn('7');

        $this->assertSame(7, $this->reader->getInt(key: 'k'));
    }//end testGetIntConvertsStringToInt()

    /**
     * Test setBool stores 'true' string.
        $this->appConfig->method('getValueString')->willReturn('99');
        $this->appConfig
            ->method('getValueString')
            ->willReturn('99');

        $this->assertSame(99, $this->reader->getInt(key: 'count_key'));
    }//end testGetIntConvertsStringToInt()

    /**
     * Test that getInt returns zero for an empty stored value.
     *
     * @return void
     */
    public function testGetIntReturnsZeroForEmpty(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('0');

        $this->assertSame(0, $this->reader->getInt(key: 'count_key'));
    }//end testGetIntReturnsZeroForEmpty()

    /**
     * Test that setBool stores 'true' when passed true.
     *
     * @return void
     */
    public function testSetBoolStoresTrueString(): void
    {
        $this->appConfig->expects($this->once())->method('setValueString')->with(Application::APP_ID, 'k', 'true');

        $this->reader->setBool(key: 'k', value: true);
    }//end testSetBoolStoresTrueString()

    /**
     * Test setInt stores int as string.
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
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'bool_key', 'true');

        $this->reader->setBool(key: 'bool_key', value: true);
    }//end testSetBoolStoresTrueString()

    /**
     * Test that setBool stores 'false' when passed false.
     *
     * @return void
     */
    public function testSetBoolStoresFalseString(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'bool_key', 'false');

        $this->reader->setBool(key: 'bool_key', value: false);
    }//end testSetBoolStoresFalseString()

    /**
     * Test that setInt stores the integer as a string.
     *
     * @return void
     */
    public function testSetIntStoresIntAsString(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'int_key', '42');

        $this->reader->setInt(key: 'int_key', value: 42);
    }//end testSetIntStoresIntAsString()

    /**
     * Test that setJsonArray stores the array as JSON.
     *
     * @return void
     */
    public function testSetJsonArrayStoresAsJson(): void
    {
        $this->appConfig->expects($this->once())->method('setValueString')->with(Application::APP_ID, 'k', '["x"]');

        $this->reader->setJsonArray(key: 'k', value: ['x']);
    }//end testSetJsonArrayStoresAsJson()
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'arr_key', '["x","y"]');

        $this->reader->setJsonArray(key: 'arr_key', value: ['x', 'y']);
    }//end testSetJsonArrayStoresAsJson()

    /**
     * Test that setJsonArray stores an empty array when passed a non-array.
     *
     * @return void
     */
    public function testSetJsonArrayStoresEmptyArrayForNonArray(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'arr_key', '[]');

        $this->reader->setJsonArray(key: 'arr_key', value: 'not-an-array');
    }//end testSetJsonArrayStoresEmptyArrayForNonArray()
}//end class
