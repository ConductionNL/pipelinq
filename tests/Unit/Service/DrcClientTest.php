<?php

/**
 * Unit tests for DrcClient (inline vs multipart EIO upload, zaak linking).
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

use OCA\Pipelinq\Service\AcClient;
use OCA\Pipelinq\Service\DrcClient;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DrcClient.
 */
class DrcClientTest extends TestCase
{
    /**
     * Endpoint fixture.
     *
     * @return array<string, mixed> The endpoint.
     */
    private function endpoint(): array
    {
        return ['id' => 'ep1', 'componenten' => ['drc' => 'https://drc/api/v1', 'zrc' => 'https://zrc/api/v1']];
    }//end endpoint()

    /**
     * App config returning the default 4 MiB inline threshold.
     *
     * @return IAppConfig The mock.
     */
    private function appConfig(): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueString')->willReturnArgument(2);
        return $cfg;
    }//end appConfig()

    /**
     * A small file is uploaded inline with base64 inhoud, no bestandsdelen.
     *
     * @return void
     */
    public function testSmallFileUploadedInline(): void
    {
        $captured = [];
        $api      = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturnCallback(
            function (string $url, string $method, string $path, array $client, ?array $body=null) use (&$captured): array {
                $captured = $body;
                return ['status' => 201, 'body' => ['url' => 'https://drc/api/v1/eio/1'], 'headers' => [], 'etag' => 'W/"d1"'];
            }
        );

        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('save')->willReturnCallback(static fn(string $e, array $d): array => $d);

        $ac = $this->createMock(AcClient::class);
        $ac->expects($this->once())->method('requireScope')
            ->with($this->anything(), $this->anything(), '*', AcClient::SCOPE_DOCUMENTEN_AANMAKEN);

        $drc     = new DrcClient($api, $repo, $ac, $this->appConfig());
        $mapping = $drc->createEnkelvoudigInformatieobject(
            $this->endpoint(),
            ['clientIdentifier' => 'c'],
            ['id' => 'doc1', 'bytes' => 'hello-pdf-bytes'],
            ['bronorganisatie' => '002564440', 'titel' => 'aanvraag.pdf']
        );

        $this->assertSame('https://drc/api/v1/eio/1', $mapping['zgwUrl']);
        $this->assertSame(base64_encode('hello-pdf-bytes'), $captured['inhoud']);
        $this->assertSame(strlen('hello-pdf-bytes'), $captured['bestandsomvang']);
    }//end testSmallFileUploadedInline()

    /**
     * A large file omits inhoud and runs the bestandsdelen+unlock protocol.
     *
     * @return void
     */
    public function testLargeFileUsesMultipartProtocol(): void
    {
        $bytes  = str_repeat('A', (5 * 1024 * 1024));
        $calls  = [];
        $bodies = [];

        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturnCallback(
            function (string $url, string $method, string $path, array $client, ?array $body=null) use (&$calls, &$bodies): array {
                $calls[]  = $method.' '.$path;
                $bodies[] = $body;
                if (str_contains($path, 'enkelvoudiginformatieobjecten') === true && $method === 'POST' && str_contains($path, 'unlock') === false) {
                    return [
                        'status'  => 201,
                        'body'    => [
                            'url'           => 'https://drc/api/v1/eio/2',
                            'lock'          => 'lock-xyz',
                            'bestandsdelen' => [['url' => 'https://drc/api/v1/eio/2/deel/1', 'omvang' => (5 * 1024 * 1024)]],
                        ],
                        'headers' => [],
                        'etag'    => '',
                    ];
                }

                return ['status' => 200, 'body' => [], 'headers' => [], 'etag' => ''];
            }
        );

        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('save')->willReturnCallback(static fn(string $e, array $d): array => array_merge(['@self' => ['uuid' => 'm']], $d));

        $drc = new DrcClient($api, $repo, $this->createMock(AcClient::class), $this->appConfig());
        $drc->createEnkelvoudigInformatieobject(
            $this->endpoint(),
            ['clientIdentifier' => 'c'],
            ['id' => 'doc2', 'bytes' => $bytes, 'bestandsomvang' => (5 * 1024 * 1024)],
            ['bronorganisatie' => '002564440']
        );

        // The create body must NOT carry inhoud for a large file.
        $this->assertArrayNotHasKey('inhoud', $bodies[0]);
        // The part PUT and the unlock POST must both have happened.
        $this->assertContains('PUT https://drc/api/v1/eio/2/deel/1', $calls);
        $this->assertContains('POST https://drc/api/v1/eio/2/unlock', $calls);
    }//end testLargeFileUsesMultipartProtocol()
}//end class
