<?php

/**
 * Unit tests for MediaAttachmentService.
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

use OCA\Pipelinq\Service\AntivirusScanService;
use OCA\Pipelinq\Service\MediaAttachmentService;
use OCA\Pipelinq\Service\Messaging\Provider\MetaWhatsAppClient;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for inbound/outbound media handling.
 */
class MediaAttachmentServiceTest extends TestCase
{
    /**
     * Mock root folder.
     *
     * @var IRootFolder
     */
    private IRootFolder $rootFolder;

    /**
     * Mock antivirus.
     *
     * @var AntivirusScanService
     */
    private AntivirusScanService $antivirus;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->antivirus  = $this->createMock(AntivirusScanService::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return MediaAttachmentService The service.
     */
    private function service(): MediaAttachmentService
    {
        return new MediaAttachmentService(
            $this->rootFolder,
            $this->antivirus,
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    /**
     * Size validation accepts within-limit and rejects oversize / zero.
     *
     * @return void
     */
    public function testSizeValidation(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isWithinSizeLimit(1024));
        $this->assertTrue($service->isWithinSizeLimit(MediaAttachmentService::META_MAX_MEDIA_BYTES));
        $this->assertFalse($service->isWithinSizeLimit(MediaAttachmentService::META_MAX_MEDIA_BYTES + 1));
        $this->assertFalse($service->isWithinSizeLimit(0));
    }//end testSizeValidation()

    /**
     * A clean inbound image is downloaded, scanned and stored.
     *
     * @return void
     */
    public function testCleanInboundStored(): void
    {
        $this->antivirus->method('isClean')->willReturn(true);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(4242);

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException());
        $folder->method('newFolder')->willReturnSelf();
        $folder->method('newFile')->willReturn($file);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $client = $this->createMock(MetaWhatsAppClient::class);
        $client->method('downloadMedia')->willReturn('JPEGBYTES');

        $result = $this->service()->storeInbound($client, 'conv-1', ['id' => 'media-1', 'filename' => 'foto.jpg']);

        $this->assertTrue($result['stored']);
        $this->assertFalse($result['quarantined']);
        $this->assertSame('4242', $result['fileId']);
    }//end testCleanInboundStored()

    /**
     * A flagged inbound image is still stored but marked quarantined.
     *
     * @return void
     */
    public function testInfectedInboundQuarantined(): void
    {
        $this->antivirus->method('isClean')->willReturn(false);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException());
        $folder->method('newFolder')->willReturnSelf();
        $folder->method('newFile')->willReturn($file);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $client = $this->createMock(MetaWhatsAppClient::class);
        $client->method('downloadMedia')->willReturn('EICAR');

        $result = $this->service()->storeInbound($client, 'conv-1', ['id' => 'media-2', 'filename' => 'virus.exe']);

        $this->assertTrue($result['stored']);
        $this->assertTrue($result['quarantined']);
    }//end testInfectedInboundQuarantined()

    /**
     * A media id that cannot be downloaded yields a not-stored result.
     *
     * @return void
     */
    public function testFailedDownloadNotStored(): void
    {
        $client = $this->createMock(MetaWhatsAppClient::class);
        $client->method('downloadMedia')->willReturn(null);

        $result = $this->service()->storeInbound($client, 'conv-1', ['id' => 'media-3']);

        $this->assertFalse($result['stored']);
        $this->assertNull($result['fileId']);
    }//end testFailedDownloadNotStored()
}//end class
