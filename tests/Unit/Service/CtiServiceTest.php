<?php

/**
 * Unit tests for CtiService orchestration.
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
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\ScreenPopResult;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CtiService screen-pop and presence logic.
 */
class CtiServiceTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The contact matcher mock.
     *
     * @var CtiContactMatcher
     */
    private CtiContactMatcher $matcher;

    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->matcher       = $this->createMock(CtiContactMatcher::class);
        $this->objectService = $this->createMock(ObjectService::class);
    }//end setUp()

    /**
     * Build the service with the given app-config string map.
     *
     * @param array<string, string> $configMap Map of config key to value.
     *
     * @return CtiService The service under test.
     */
    private function service(array $configMap=[]): CtiService
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default) use ($configMap): string {
                    return ($configMap[$key] ?? $default);
                }
            );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        return new CtiService(
            $container,
            $this->appConfig,
            $this->createMock(AdapterRegistry::class),
            new PhoneNormaliser($this->appConfig),
            $this->matcher,
            $this->createMock(ICacheFactory::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    /**
     * A single match yields a navigate action.
     *
     * @return void
     */
    public function testScreenPopSingleMatchNavigates(): void
    {
        $this->matcher->method('findByPhoneNumber')->willReturn([['@self' => ['id' => 'c-1'], 'name' => 'Anna']]);

        // No CTI config object → findAll returns empty (delay defaults to 0).
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->service(['register' => 'reg-1', 'contact_schema' => 'sc', 'default_country_code' => 'NL'])
            ->initiateScreenPop('0612345678');

        $this->assertSame(ScreenPopResult::ACTION_NAVIGATE, $result->action);
        $this->assertSame('+31612345678', $result->e164Number);
    }//end testScreenPopSingleMatchNavigates()

    /**
     * Multiple matches yield a chooser action.
     *
     * @return void
     */
    public function testScreenPopMultipleMatchesChooser(): void
    {
        $this->matcher->method('findByPhoneNumber')->willReturn(
            [['@self' => ['id' => 'c-1']], ['@self' => ['id' => 'c-2']]]
        );
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->service(['register' => 'reg-1', 'contact_schema' => 'sc', 'default_country_code' => 'NL'])
            ->initiateScreenPop('0612345678');

        $this->assertSame(ScreenPopResult::ACTION_CHOOSER, $result->action);
    }//end testScreenPopMultipleMatchesChooser()

    /**
     * No match yields an intake action.
     *
     * @return void
     */
    public function testScreenPopNoMatchIntake(): void
    {
        $this->matcher->method('findByPhoneNumber')->willReturn([]);
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->service(['register' => 'reg-1', 'contact_schema' => 'sc', 'default_country_code' => 'NL'])
            ->initiateScreenPop('0612345678');

        $this->assertSame(ScreenPopResult::ACTION_INTAKE, $result->action);
    }//end testScreenPopNoMatchIntake()

    /**
     * An unparseable caller number falls back to intake without a lookup.
     *
     * @return void
     */
    public function testScreenPopUnparseableNumberIntake(): void
    {
        $this->matcher->expects($this->never())->method('findByPhoneNumber');
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->service(['default_country_code' => 'NL'])->initiateScreenPop('abc');

        $this->assertSame(ScreenPopResult::ACTION_INTAKE, $result->action);
        $this->assertSame('', $result->e164Number);
    }//end testScreenPopUnparseableNumberIntake()

    /**
     * Click-to-dial is blocked when the agent is on-call.
     *
     * @return void
     */
    public function testClickToDialBlockedWhenOnCall(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'p-1'], 'userId' => 'agent', 'presenceState' => 'on-call']]
        );

        $blocked = $this->service(['register' => 'reg-1', 'ctiAgentPresence_schema' => 'sp'])
            ->isClickToDialBlocked('agent');

        $this->assertTrue($blocked);
    }//end testClickToDialBlockedWhenOnCall()

    /**
     * Click-to-dial is allowed when the agent is available.
     *
     * @return void
     */
    public function testClickToDialAllowedWhenAvailable(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'p-1'], 'userId' => 'agent', 'presenceState' => 'available']]
        );

        $blocked = $this->service(['register' => 'reg-1', 'ctiAgentPresence_schema' => 'sp'])
            ->isClickToDialBlocked('agent');

        $this->assertFalse($blocked);
    }//end testClickToDialAllowedWhenAvailable()
}//end class
