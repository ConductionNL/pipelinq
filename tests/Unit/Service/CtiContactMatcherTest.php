<?php

/**
 * Unit tests for CtiContactMatcher.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-8.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CtiContactMatcher.
 */
class CtiContactMatcherTest extends TestCase
{
    /**
     * Test that an empty/null phone number short-circuits with no matches.
     *
     * @return void
     */
    public function testNullNumberReturnsEmpty(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $normaliser = $this->createMock(PhoneNormaliser::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $matcher = new CtiContactMatcher($container, $appConfig, $normaliser, $logger);
        $result  = $matcher->findByPhoneNumber(null);

        $this->assertSame(['matches' => [], 'totalMatches' => 0], $result);
    }//end testNullNumberReturnsEmpty()

    /**
     * Test that an empty register config returns empty result.
     *
     * @return void
     */
    public function testMissingRegisterReturnsEmpty(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $normaliser = $this->createMock(PhoneNormaliser::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $appConfig->method('getValueString')->willReturn('');

        $matcher = new CtiContactMatcher($container, $appConfig, $normaliser, $logger);
        $result  = $matcher->findByPhoneNumber('+31612345678');

        $this->assertSame(0, $result['totalMatches']);
    }//end testMissingRegisterReturnsEmpty()
}//end class
