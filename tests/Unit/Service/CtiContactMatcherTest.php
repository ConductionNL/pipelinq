<?php

/**
 * Unit tests for CtiContactMatcher.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CtiContactMatcher caller resolution (REQ-CTI-001).
 */
class CtiContactMatcherTest extends TestCase
{
    /**
     * The container mock.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The matcher under test.
     *
     * @var CtiContactMatcher
     */
    private CtiContactMatcher $matcher;

    /**
     * Set up a matcher wired to a mocked OpenRegister and NL normaliser.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default): string {
                    return match ($key) {
                        'register'             => 'reg-1',
                        'contact_schema'       => 'schema-contact',
                        'default_country_code' => 'NL',
                        default                => $default,
                    };
                }
            );

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $normaliser    = new PhoneNormaliser($this->appConfig);
        $this->matcher = new CtiContactMatcher(
            $this->container,
            $this->appConfig,
            $normaliser,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A single stored contact whose phone normalises to the caller is matched.
     *
     * @return void
     */
    public function testSingleMatchByNormalisedNumber(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                ['@self' => ['id' => 'c-1', 'updated' => '2026-05-01T00:00:00Z'], 'name' => 'Anna', 'phone' => '0612345678'],
                ['@self' => ['id' => 'c-2', 'updated' => '2026-05-02T00:00:00Z'], 'name' => 'Bert', 'phone' => '0698765432'],
            ]
        );

        $matches = $this->matcher->findByPhoneNumber('+31612345678');

        $this->assertCount(1, $matches);
        $this->assertSame('Anna', $matches[0]['name']);
    }//end testSingleMatchByNormalisedNumber()

    /**
     * Multiple matches are returned most-recently-updated first, capped at 3.
     *
     * @return void
     */
    public function testMultipleMatchesOrderedByRecencyAndCapped(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                ['@self' => ['id' => 'c-1', 'updated' => '2026-05-01T00:00:00Z'], 'name' => 'Old', 'phone' => '+31612345678'],
                ['@self' => ['id' => 'c-2', 'updated' => '2026-05-09T00:00:00Z'], 'name' => 'New', 'phone' => '0612345678'],
                ['@self' => ['id' => 'c-3', 'updated' => '2026-05-05T00:00:00Z'], 'name' => 'Mid', 'phone' => '+31 6 1234 5678'],
                ['@self' => ['id' => 'c-4', 'updated' => '2026-05-04T00:00:00Z'], 'name' => 'Four', 'phone' => '0612345678'],
            ]
        );

        $matches = $this->matcher->findByPhoneNumber('+31612345678');

        $this->assertCount(3, $matches);
        $this->assertSame('New', $matches[0]['name']);
        $this->assertSame('Mid', $matches[1]['name']);
    }//end testMultipleMatchesOrderedByRecencyAndCapped()

    /**
     * No stored contact matching the caller returns an empty array.
     *
     * @return void
     */
    public function testNoMatchReturnsEmpty(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'c-1'], 'name' => 'Anna', 'phone' => '0698765432']]
        );

        $this->assertSame([], $this->matcher->findByPhoneNumber('+31612345678'));
    }//end testNoMatchReturnsEmpty()

    /**
     * An empty caller number short-circuits to no matches.
     *
     * @return void
     */
    public function testEmptyNumberReturnsEmpty(): void
    {
        $this->assertSame([], $this->matcher->findByPhoneNumber(''));
    }//end testEmptyNumberReturnsEmpty()
}//end class
