<?php

/**
 * Unit tests for NrcNotificationHandler (per-kanaal dispatch).
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

use OCA\Pipelinq\Service\NrcNotificationHandler;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCA\Pipelinq\Service\ZrcClient;
use OCA\Pipelinq\Service\ZtcClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for NrcNotificationHandler.
 */
class NrcNotificationHandlerTest extends TestCase
{
    /**
     * App config returning configured register/request schema.
     *
     * @return IAppConfig The mock.
     */
    private function appConfig(): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                return match ($key) {
                    'register'       => 'reg-1',
                    'request_schema' => 'req-schema',
                    default          => $default,
                };
            }
        );
        return $cfg;
    }//end appConfig()

    /**
     * A catalogi notification invalidates the ZTC cache for the resource type.
     *
     * @return void
     */
    public function testCatalogiNotificationInvalidatesZtcCache(): void
    {
        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('findOneByField')->willReturn(['id' => 'ep1', 'componenten' => []]);

        $ztc = $this->createMock(ZtcClient::class);
        $ztc->expects($this->once())->method('invalidateCache')
            ->with($this->anything(), 'zaaktype');

        $handler = new NrcNotificationHandler(
            $repo,
            $this->createMock(ZrcClient::class),
            $ztc,
            $this->appConfig(),
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle(
            ['id' => 'abon1', 'endpointId' => 'ep1'],
            ['kanaal' => 'catalogi', 'resource' => 'zaaktype', 'actie' => 'update']
        );
    }//end testCatalogiNotificationInvalidatesZtcCache()

    /**
     * A zaak.status notification updates the linked Request status via ObjectService.
     *
     * @return void
     */
    public function testStatusNotificationUpdatesRequest(): void
    {
        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('findOneByField')->willReturnCallback(
            static function (string $entity, string $field, string $value): ?array {
                return match ($entity) {
                    'zgwEndpoint'        => ['id' => 'ep1', 'clientId' => 'c1', 'componenten' => ['zrc' => 'https://zrc']],
                    'zgwResourceMapping' => ['pipelinqId' => 'req-9', 'zgwUrl' => $value],
                    'zgwClient'          => ['clientIdentifier' => 'pipelinq'],
                    default              => null,
                };
            }
        );
        $repo->method('toArray')->willReturnCallback(static fn(mixed $o): array => (array) $o);

        $zrc = $this->createMock(ZrcClient::class);
        $zrc->method('getStatus')->willReturn(['statustype' => 'https://ztc/statustypen/ontvangen', 'statustoelichting' => 'Aanvraag ontvangen']);

        $saved = [];

        $objectService = new class($saved) {
            /**
             * @param array<string, mixed> $saved Reference capture (unused init).
             */
            public function __construct(public array $saved)
            {
            }

            /**
             * @param string $id     The object id.
             * @param array  $extend Extend (ignored).
             *
             * @return array<string, mixed> A bare request object.
             */
            public function find(string $id, array $extend): array
            {
                return ['@self' => ['uuid' => $id], 'status' => 'nieuw'];
            }

            /**
             * Capture the saved request.
             *
             * @param array       $object   The object.
             * @param array       $extend   Extend.
             * @param string|null $register The register.
             * @param string|null $schema   The schema.
             * @param string|null $uuid     The uuid.
             *
             * @return array<string, mixed> The saved object.
             */
            public function saveObject(array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null): array
            {
                $this->saved = $object;
                return $object;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $handler = new NrcNotificationHandler(
            $repo,
            $zrc,
            $this->createMock(ZtcClient::class),
            $this->appConfig(),
            $container,
            $this->createMock(LoggerInterface::class)
        );

        $handler->handle(
            ['id' => 'abon1', 'endpointId' => 'ep1'],
            [
                'kanaal'      => 'zaken',
                'resource'    => 'status',
                'actie'       => 'create',
                'hoofdObject' => 'https://zrc/zaken/abc',
                'resourceUrl' => 'https://zrc/statussen/1',
            ]
        );

        $this->assertSame('Aanvraag ontvangen', $objectService->saved['status']);
    }//end testStatusNotificationUpdatesRequest()
}//end class
