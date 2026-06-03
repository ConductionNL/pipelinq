<?php

/**
 * Unit tests for ContactBetrokkeneMapper de-duplication logic.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Stuf\ContactBetrokkeneMapper;
use OCA\Pipelinq\Service\Stuf\StufEnvelopeBuilder;
use OCA\Pipelinq\Service\Stuf\StufMessageParser;
use OCA\Pipelinq\Service\Stuf\StufTransportInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactBetrokkeneMapper.
 */
class ContactBetrokkeneMapperTest extends TestCase
{

    /**
     * The object service mock.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ObjectService $objectService;

    /**
     * The transport fake (no live endpoint required).
     *
     * @var FakeStufTransport
     */
    private FakeStufTransport $transport;

    /**
     * Build a mapper wired to mocks; the container resolves the ObjectService.
     *
     * @return ContactBetrokkeneMapper The mapper under test.
     */
    private function mapper(): ContactBetrokkeneMapper
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->transport     = new FakeStufTransport();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                return $key === 'register' ? 'pipelinq' : 'zaaksysteemMapping';
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        return new ContactBetrokkeneMapper(
            container: $container,
            appConfig: $appConfig,
            envelopeBuilder: new StufEnvelopeBuilder(
                credentials: $this->createMock(\OCA\Pipelinq\Service\Stuf\StufCredentialResolver::class),
                logger: $logger
            ),
            parser: new StufMessageParser(logger: $logger),
            transport: $this->transport,
            logger: $logger
        );
    }//end mapper()

    /**
     * bsnFromContact accepts an 8-9 digit BSN and rejects anything else.
     *
     * @return void
     */
    public function testBsnExtraction(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('123456789', $mapper->bsnFromContact(contact: ['bsn' => '123456789']));
        $this->assertNull($mapper->bsnFromContact(contact: ['bsn' => 'abc']));
        $this->assertNull($mapper->bsnFromContact(contact: []));
    }//end testBsnExtraction()

    /**
     * An existing contact mapping is reused, issuing no SOAP traffic.
     *
     * @return void
     */
    public function testExistingMappingIsReused(): void
    {
        $mapper = $this->mapper();
        $this->objectService->method('findAll')->willReturn(
            [
                [
                    'pipelinqEntiteit'    => 'contact',
                    'pipelinqId'          => 'contact-1',
                    'externEntiteit'      => 'NPS',
                    'externIdentificatie' => 'NPS-existing',
                ],
            ]
        );

        $result = $mapper->findOrCreateBetrokkene(
            contact: ['id' => 'contact-1', 'bsn' => '123456789'],
            endpoint: ['id' => 'ep-1']
        );

        $this->assertFalse($result['isNew']);
        $this->assertSame('NPS-existing', $result['identificatie']);
        $this->assertCount(0, $this->transport->sent, 'reuse must not query the zaaksysteem');
    }//end testExistingMappingIsReused()

    /**
     * A Contact with no BSN cannot be mapped and raises.
     *
     * @return void
     */
    public function testMissingBsnThrows(): void
    {
        $mapper = $this->mapper();

        $this->expectException(\RuntimeException::class);
        $mapper->findOrCreateBetrokkene(contact: ['id' => 'contact-1'], endpoint: ['id' => 'ep-1']);
    }//end testMissingBsnThrows()

    /**
     * With no existing mapping and a not-found lookup, a new betrokkene is created
     * from the BSN and a mapping is persisted via saveObject.
     *
     * @return void
     */
    public function testNewBetrokkeneCreatedWhenNotFound(): void
    {
        $mapper = $this->mapper();
        // No existing mapping; the geefBetrokkene lookup returns an empty La01.
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->expects($this->once())->method('saveObject')->willReturn(['id' => 'map-new']);
        $this->transport->queue[] = [
            'httpStatus'  => 200,
            'responseXml' => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
                .' xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
                .' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"'
                .' xmlns:bg="http://www.egem.nl/StUF/sector/bg/0310">'
                .'<soapenv:Body><zkn:zakLa01/></soapenv:Body></soapenv:Envelope>',
            'durationMs'  => 5,
        ];

        $result = $mapper->findOrCreateBetrokkene(
            contact: ['id' => 'contact-2', 'bsn' => '987654321', 'name' => 'Yasmine'],
            endpoint: ['id' => 'ep-1']
        );

        $this->assertTrue($result['isNew']);
        $this->assertSame('987654321', $result['identificatie']);
        $this->assertCount(1, $this->transport->sent, 'a geefBetrokkene lookup is issued before create');
    }//end testNewBetrokkeneCreatedWhenNotFound()
}//end class
