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

use OCA\Pipelinq\Service\IcpConfigReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IcpConfigReader.
 */
class IcpConfigReaderTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var IcpConfigReader
     */
    private IcpConfigReader $reader;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->reader    = new IcpConfigReader($this->appConfig);
    }//end setUp()

    /**
     * Test getString delegates to appConfig.
     *
     * @return void
     */
    public function testGetStringDelegates(): void
    {
        $this->appConfig->method('getValueString')->willReturn('test-value');

        $this->assertSame('test-value', $this->reader->getString('some_key'));
    }//end testGetStringDelegates()

    /**
     * Test getJsonArray decodes JSON array.
     *
     * @return void
     */
    public function testGetJsonArrayDecodesArray(): void
    {
        $this->appConfig->method('getValueString')->willReturn('["a","b"]');

        $this->assertSame(['a', 'b'], $this->reader->getJsonArray('some_key'));
    }//end testGetJsonArrayDecodesArray()

    /**
     * Test getJsonArray returns empty array for invalid JSON.
     *
     * @return void
     */
    public function testGetJsonArrayInvalidReturnsEmpty(): void
    {
        $this->appConfig->method('getValueString')->willReturn('not-json');

        $this->assertSame([], $this->reader->getJsonArray('some_key'));
    }//end testGetJsonArrayInvalidReturnsEmpty()

    /**
     * Test isBoolTrue returns true for 'true'.
     *
     * @return void
     */
    public function testIsBoolTrueReturnsTrue(): void
    {
        $this->appConfig->method('getValueString')->willReturn('true');

        $this->assertTrue($this->reader->isBoolTrue('some_key'));
    }//end testIsBoolTrueReturnsTrue()

    /**
     * Test isBoolTrue returns false for 'false'.
     *
     * @return void
     */
    public function testIsBoolTrueReturnsFalse(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');

        $this->assertFalse($this->reader->isBoolTrue('some_key'));
    }//end testIsBoolTrueReturnsFalse()

    /**
     * Test getInt returns integer value.
     *
     * @return void
     */
    public function testGetIntReturnsInteger(): void
    {
        $this->appConfig->method('getValueString')->willReturn('42');

        $this->assertSame(42, $this->reader->getInt('some_key'));
    }//end testGetIntReturnsInteger()

    /**
     * Test setString delegates to appConfig.
     *
     * @return void
     */
    public function testSetStringDelegates(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('pipelinq', 'key1', 'val1');

        $this->reader->setString('key1', 'val1');
    }//end testSetStringDelegates()

    /**
     * Test setBool stores true as string.
     *
     * @return void
     */
    public function testSetBoolStoresTrue(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('pipelinq', 'key1', 'true');

        $this->reader->setBool('key1', true);
    }//end testSetBoolStoresTrue()

    /**
     * Test setInt stores integer as string.
     *
     * @return void
     */
    public function testSetIntStoresString(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('pipelinq', 'key1', '42');

        $this->reader->setInt('key1', 42);
    }//end testSetIntStoresString()
}//end class
