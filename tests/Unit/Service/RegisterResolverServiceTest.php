<?php

/**
 * Unit tests for RegisterResolverService.
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
 * @spec openspec/changes/pipelinq-or-register-resolver/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegisterResolverService.
 */
class RegisterResolverServiceTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
    }//end setUp()

    /**
     * Test that resolve returns the configured register id.
     *
     * @return void
     */
    public function testResolveReturnsConfiguredRegisterId(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('reg-42');

        $service = new RegisterResolverService(appConfig: $this->appConfig);

        $this->assertSame('reg-42', $service->resolve('queue'));
    }//end testResolveReturnsConfiguredRegisterId()

    /**
     * Test that resolve returns an empty string fallback when unconfigured.
     *
     * @return void
     */
    public function testResolveReturnsEmptyFallbackWhenUnconfigured(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('');

        $service = new RegisterResolverService(appConfig: $this->appConfig);

        $this->assertSame('', $service->resolve('contact'));
    }//end testResolveReturnsEmptyFallbackWhenUnconfigured()

    /**
     * Test that every logical name resolves to the same instance-scoped register id.
     *
     * Behaviour parity: the prior code read the same `register` app-config key
     * regardless of the consumer, so the resolver must too.
     *
     * @return void
     */
    public function testAllLogicalNamesResolveToSameRegister(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('reg-shared');

        $service = new RegisterResolverService(appConfig: $this->appConfig);

        $this->assertSame('reg-shared', $service->resolve('queue'));
        $this->assertSame('reg-shared', $service->resolve('contact'));
    }//end testAllLogicalNamesResolveToSameRegister()

    /**
     * Test that resolve memoises the result per request (reads app-config once per name).
     *
     * @return void
     */
    public function testResolveMemoisesPerLogicalName(): void
    {
        // The same logical name must hit app-config exactly once, even across
        // repeated resolve() calls within a request.
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('reg-cached');

        $service = new RegisterResolverService(appConfig: $this->appConfig);

        $first  = $service->resolve('queue');
        $second = $service->resolve('queue');

        $this->assertSame('reg-cached', $first);
        $this->assertSame($first, $second);
    }//end testResolveMemoisesPerLogicalName()

    /**
     * Test that flush() clears the cache so the next resolve re-reads app-config.
     *
     * @return void
     */
    public function testFlushClearsCache(): void
    {
        $this->appConfig
            ->expects($this->exactly(2))
            ->method('getValueString')
            ->with(Application::APP_ID, 'register', '')
            ->willReturn('reg-refresh');

        $service = new RegisterResolverService(appConfig: $this->appConfig);

        $service->resolve('queue');
        $service->flush();
        $second = $service->resolve('queue');

        $this->assertSame('reg-refresh', $second);
    }//end testFlushClearsCache()
}//end class
