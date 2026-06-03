<?php

/**
 * Unit tests for EmailMatchService.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the CRM email-to-entity matching rule and per-user settings.
 */
class EmailMatchServiceTest extends TestCase
{

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The OR ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The per-user config mock.
     *
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * Set up the mocks and resolve register/schema slugs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willReturn($this->objectService);

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') {
                return match ($key) {
                    'register'        => 'pipelinq',
                    'contact_schema'  => 'contact',
                    'client_schema'   => 'client',
                    default           => $default,
                };
            }
        );

        $this->config = $this->createMock(IConfig::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return EmailMatchService The service.
     */
    private function service(): EmailMatchService
    {
        return new EmailMatchService(
            $this->container,
            $this->appConfig,
            $this->config,
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    /**
     * Exact address match returns the matched contact entity reference.
     *
     * @return void
     */
    public function testExactAddressMatchReturnsEntity(): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $params): array {
                if (($params['filters']['schema'] ?? '') === 'contact') {
                    return [['@self' => ['uuid' => 'uuid-contact-1'], 'email' => 'j.devries@gemeente-utrecht.nl']];
                }
                return [];
            }
        );

        $matches = $this->service()->matchEmailToEntities('j.devries@gemeente-utrecht.nl');

        $this->assertSame([['type' => 'contact', 'uuid' => 'uuid-contact-1']], $matches);
    }//end testExactAddressMatchReturnsEntity()

    /**
     * Unknown address returns an empty match set (and thus no link).
     *
     * @return void
     */
    public function testUnknownAddressReturnsEmpty(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $this->assertSame([], $this->service()->matchEmailToEntities('unknown@example.nl'));
        $this->assertSame([], $this->service()->resolveAddress('unknown@example.nl'));
    }//end testUnknownAddressReturnsEmpty()

    /**
     * Public-provider domains are recognised and never domain-matched.
     *
     * @return void
     */
    public function testIsPublicDomain(): void
    {
        $service = $this->service();
        $this->assertTrue($service->isPublicDomain('gmail.com'));
        $this->assertTrue($service->isPublicDomain('OUTLOOK.COM'));
        $this->assertFalse($service->isPublicDomain('bakker-installaties.nl'));
    }//end testIsPublicDomain()

    /**
     * A corporate domain matches an organization-type client.
     *
     * @return void
     */
    public function testCorporateDomainMatchesOrganization(): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $params): array {
                // No exact email match; organization lookup returns the client.
                if (($params['filters']['type'] ?? '') === 'organization') {
                    return [['@self' => ['uuid' => 'uuid-org-1'], 'email' => 'info@bakker-installaties.nl']];
                }
                return [];
            }
        );

        $result = $this->service()->matchDomainToOrganization('bakker-installaties.nl');

        $this->assertSame(['type' => 'client', 'uuid' => 'uuid-org-1'], $result);
    }//end testCorporateDomainMatchesOrganization()

    /**
     * Public domains are skipped by the domain matcher.
     *
     * @return void
     */
    public function testPublicDomainIsNotMatched(): void
    {
        $this->objectService->expects($this->never())->method('findAll');

        $this->assertNull($this->service()->matchDomainToOrganization('gmail.com'));
    }//end testPublicDomainIsNotMatched()

    /**
     * resolveAddress falls back to domain matching when no exact match exists.
     *
     * @return void
     */
    public function testResolveAddressFallsBackToDomain(): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $params): array {
                if (($params['filters']['type'] ?? '') === 'organization') {
                    return [['@self' => ['uuid' => 'uuid-org-2'], 'email' => 'info@acme-bv.nl']];
                }
                return [];
            }
        );

        $result = $this->service()->resolveAddress('p.bakker@acme-bv.nl');

        $this->assertSame([['type' => 'client', 'uuid' => 'uuid-org-2']], $result);
    }//end testResolveAddressFallsBackToDomain()

    /**
     * Per-user settings (enabled, account, excluded) round-trip independently.
     *
     * @return void
     */
    public function testPerUserSettingsAreScopedToUser(): void
    {
        $store = [];
        $this->config->method('setUserValue')->willReturnCallback(
            static function (string $uid, string $app, string $key, string $value) use (&$store): void {
                $store[$uid.':'.$key] = $value;
            }
        );
        $this->config->method('getUserValue')->willReturnCallback(
            static function (string $uid, string $app, string $key, string $default='') use (&$store) {
                return $store[$uid.':'.$key] ?? $default;
            }
        );

        $service = $this->service();
        $service->setSyncEnabled('alice', true);
        $service->setSyncAccount('alice', 7);
        $service->setExcludedAddresses('alice', ['noreply@example.com']);

        $this->assertTrue($service->isSyncEnabled('alice'));
        $this->assertSame(7, $service->getSyncAccount('alice'));
        $this->assertTrue($service->isExcluded('alice', 'NOREPLY@example.com'));

        // Bob has no settings — fully isolated from alice.
        $this->assertFalse($service->isSyncEnabled('bob'));
        $this->assertNull($service->getSyncAccount('bob'));
        $this->assertFalse($service->isExcluded('bob', 'noreply@example.com'));
    }//end testPerUserSettingsAreScopedToUser()

    /**
     * extractDomain lower-cases and rejects malformed input.
     *
     * @return void
     */
    public function testExtractDomain(): void
    {
        $service = $this->service();
        $this->assertSame('example.nl', $service->extractDomain('User@Example.NL'));
        $this->assertNull($service->extractDomain('not-an-email'));
        $this->assertNull($service->extractDomain('a@b@c'));
    }//end testExtractDomain()
}//end class
