<?php

/**
 * Pipelinq MediaAttachmentService.
 *
 * Downloads inbound message media, virus-scans it, and stores it in Nextcloud
 * Files alongside the conversation; validates outbound media size.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Messaging\Provider\MetaWhatsAppClient;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inbound/outbound media handling (REQ-008).
 *
 * Inbound media is downloaded synchronously (Meta's media URLs expire after
 * ~5 minutes), scanned via the optional Nextcloud antivirus hook, and stored
 * under `/{owner}/files/pipelinq/conversations/{conversationId}/media/`. A
 * flagged item is quarantined (kept, marker set) rather than deleted. Outbound
 * media is size-validated against the provider's limit before upload.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates HTTP download, AV scan and Files
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
 */
class MediaAttachmentService
{
    /**
     * Meta's documented outbound media size limit (100 MB) in bytes.
     *
     * @var int
     */
    public const META_MAX_MEDIA_BYTES = (100 * 1024 * 1024);

    /**
     * The system user whose Files tree holds conversation media.
     *
     * @var string
     */
    private const STORAGE_USER = 'pipelinq';

    /**
     * Constructor.
     *
     * @param IRootFolder          $rootFolder The Files root folder.
     * @param AntivirusScanService $antivirus  The antivirus scan wrapper.
     * @param LoggerInterface      $logger     The logger.
     */
    public function __construct(
        private IRootFolder $rootFolder,
        private AntivirusScanService $antivirus,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate that an outbound media payload is within the provider's limit.
     *
     * @param int $sizeBytes The media size in bytes.
     * @param int $maxBytes  The provider's maximum (defaults to Meta's 100 MB).
     *
     * @return bool True when within the limit.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-8.7
     */
    public function isWithinSizeLimit(int $sizeBytes, int $maxBytes=self::META_MAX_MEDIA_BYTES): bool
    {
        return $sizeBytes > 0 && $sizeBytes <= $maxBytes;
    }//end isWithinSizeLimit()

    /**
     * Download, scan and store an inbound media item.
     *
     * @param MetaWhatsAppClient   $client         The configured Meta client (downloads bytes).
     * @param string               $conversationId The conversation folder key.
     * @param array<string, mixed> $media          The media descriptor (id/mimeType/filename).
     *
     * @return array{fileId: string|null, quarantined: bool, stored: bool} The storage outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.7
     */
    public function storeInbound(MetaWhatsAppClient $client, string $conversationId, array $media): array
    {
        $mediaId = (string) ($media['id'] ?? '');
        if ($mediaId === '') {
            return ['fileId' => null, 'quarantined' => false, 'stored' => false];
        }

        $bytes = $client->downloadMedia(mediaId: $mediaId);
        if ($bytes === null || $bytes === '') {
            return ['fileId' => null, 'quarantined' => false, 'stored' => false];
        }

        $quarantined = ($this->antivirus->isClean(content: $bytes) === false);
        $filename    = $this->safeFilename(filename: (string) ($media['filename'] ?? ('media-'.$mediaId)));

        $fileId = $this->writeFile(
            conversationId: $conversationId,
            filename: $filename,
            bytes: $bytes
        );

        return [
            'fileId'      => $fileId,
            'quarantined' => $quarantined,
            'stored'      => ($fileId !== null),
        ];
    }//end storeInbound()

    /**
     * Write media bytes into the conversation's media folder.
     *
     * @param string $conversationId The conversation folder key.
     * @param string $filename       The sanitised filename.
     * @param string $bytes          The media bytes.
     *
     * @return string|null The created file's node id, or null on failure.
     */
    private function writeFile(string $conversationId, string $filename, string $bytes): ?string
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder(self::STORAGE_USER);
            $path       = 'pipelinq/conversations/'.$conversationId.'/media';
            $folder     = $this->ensureFolder(base: $userFolder, path: $path);

            try {
                $existing = $folder->get($filename);
                if ($existing instanceof File) {
                    $existing->putContent($bytes);
                    return (string) $existing->getId();
                }

                $existing->delete();
            } catch (NotFoundException $e) {
                // Fall through to create a new file.
            }

            $file = $folder->newFile($filename, $bytes);
            return (string) $file->getId();
        } catch (Throwable $e) {
            $this->logger->error('Media store failed', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end writeFile()

    /**
     * Ensure a nested folder path exists, creating missing segments.
     *
     * @param \OCP\Files\Folder $base The base user folder.
     * @param string            $path The relative path to ensure.
     *
     * @return \OCP\Files\Folder The deepest folder node.
     *
     * @throws \OCP\Files\NotPermittedException When creation is not permitted.
     */
    private function ensureFolder(\OCP\Files\Folder $base, string $path): \OCP\Files\Folder
    {
        $current = $base;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            try {
                $node = $current->get($segment);
                if ($node instanceof \OCP\Files\Folder) {
                    $current = $node;
                    continue;
                }
            } catch (NotFoundException $e) {
                // Fall through to create.
            }

            $current = $current->newFolder($segment);
        }

        return $current;
    }//end ensureFolder()

    /**
     * Sanitise a provider-supplied filename to a safe basename.
     *
     * @param string $filename The raw filename.
     *
     * @return string The sanitised basename.
     */
    private function safeFilename(string $filename): string
    {
        $base = basename(trim($filename));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        if ($base === '' || $base === null) {
            return 'media-'.bin2hex(random_bytes(4));
        }

        return $base;
    }//end safeFilename()
}//end class
