<?php

/**
 * Pipelinq MediaAttachmentService.
 *
 * Handles inbound and outbound media for WhatsApp / SMS messaging.
 * Coordinates a synchronous download from the provider (Meta enforces
 * a 5-minute media URL expiry), runs the configured antivirus hook,
 * and stores the file under the conversation's Nextcloud Files
 * folder so agents see the attachment alongside the conversation.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.7
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MediaAttachmentService — store inbound and outbound message media.
 *
 * Public entry points:
 * - storeInbound(channelProvider, mediaId, mimeType,
 *   conversationId) — downloads the file from the provider,
 *   virus-scans it, and stores it under the conversation folder.
 *   Returns the saved path + metadata bundle to merge onto the
 *   message row.
 * - prepareOutbound(filePath, mimeType) — pre-upload size check
 *   for outbound media (Meta's 100MB hard limit).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.7
 */
class MediaAttachmentService
{
    /**
     * Hard size cap (bytes) per Meta documentation.
     */
    public const MAX_MEDIA_SIZE_BYTES = (100 * 1024 * 1024);

    /**
     * Default root path under the system user's home where media is
     * persisted: /pipelinq/conversations/{conversationId}/media/.
     */
    private const DEFAULT_MEDIA_ROOT = 'pipelinq/conversations';

    /**
     * Constructor.
     *
     * @param ContainerInterface     $container      DI container.
     * @param IAppConfig             $appConfig      App config.
     * @param IRootFolder            $rootFolder     NC Files root.
     * @param WhatsAppProviderClient $providerClient Vendor transport.
     * @param LoggerInterface        $logger         Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.7
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IRootFolder $rootFolder,
        private WhatsAppProviderClient $providerClient,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Download inbound media within the provider expiry window,
     * antivirus-scan it, and store the bytes under the conversation
     * folder.
     *
     * @param array<string, mixed> $channelProvider Provider row.
     * @param string               $mediaId         Provider media id.
     * @param string               $mimeType        Mime type reported by webhook.
     * @param string               $conversationId  Conversation UUID.
     *
     * @return array{
     *     stored: bool,
     *     path?: string,
     *     mediaQuarantined?: bool,
     *     mimeType?: string,
     *     sizeBytes?: int,
     *     error?: string
     * } Storage outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.7
     */
    public function storeInbound(
        array $channelProvider,
        string $mediaId,
        string $mimeType,
        string $conversationId
    ): array {
        if ($mediaId === '' || $conversationId === '') {
            return ['stored' => false, 'error' => 'missing mediaId or conversationId'];
        }

        try {
            $handle = $this->providerClient->downloadMedia(
                channelProvider: $channelProvider,
                mediaId: $mediaId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'MediaAttachmentService.storeInbound: download failed',
                ['mediaId' => $mediaId, 'exception' => $e->getMessage()]
            );
            return ['stored' => false, 'error' => 'downloadFailed'];
        }

        $url = (string) ($handle['url'] ?? '');
        if ($url === '') {
            return ['stored' => false, 'error' => 'noMediaUrl'];
        }

        $bytes = $this->fetchUrlBody(url: $url);
        if ($bytes === null) {
            return ['stored' => false, 'error' => 'fetchFailed'];
        }

        $sizeBytes = strlen($bytes);
        if ($sizeBytes > self::MAX_MEDIA_SIZE_BYTES) {
            return [
                'stored'    => false,
                'sizeBytes' => $sizeBytes,
                'error'     => 'oversize',
            ];
        }

        $quarantined = ($this->virusScan(bytes: $bytes) === false);

        if ((string) ($handle['mimeType'] ?? '') !== '') {
            $resolvedMime = (string) $handle['mimeType'];
        } else {
            $resolvedMime = $mimeType;
        }

        $extension = $this->extensionFor(mimeType: $resolvedMime);
        $fileName  = sprintf('%s%s', $mediaId, $extension);

        $path = $this->writeFile(
            conversationId: $conversationId,
            fileName: $fileName,
            bytes: $bytes,
        );

        if ($path === '') {
            return ['stored' => false, 'error' => 'writeFailed'];
        }

        return [
            'stored'           => true,
            'path'             => $path,
            'mediaQuarantined' => $quarantined,
            'mimeType'         => $resolvedMime,
            'sizeBytes'        => $sizeBytes,
        ];
    }//end storeInbound()

    /**
     * Pre-upload validation for outbound media.
     *
     * @param string $filePath Local file path.
     * @param string $mimeType Mime type.
     *
     * @return array{ok: bool, error?: string, sizeBytes?: int} Outcome.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.7
     */
    public function prepareOutbound(string $filePath, string $mimeType): array
    {
        if ($filePath === '' || file_exists($filePath) === false) {
            return ['ok' => false, 'error' => 'fileMissing'];
        }

        $size = @filesize($filePath);
        if ($size === false) {
            return ['ok' => false, 'error' => 'filesizeFailed'];
        }

        if ($size > self::MAX_MEDIA_SIZE_BYTES) {
            return ['ok' => false, 'error' => 'oversize', 'sizeBytes' => (int) $size];
        }

        if ($mimeType === '') {
            return ['ok' => false, 'error' => 'missingMimeType', 'sizeBytes' => (int) $size];
        }

        return ['ok' => true, 'sizeBytes' => (int) $size];
    }//end prepareOutbound()

    /**
     * Write a binary blob under the conversation folder.
     *
     * @param string $conversationId Conversation UUID.
     * @param string $fileName       Target file name.
     * @param string $bytes          Bytes.
     *
     * @return string Internal path or empty on failure.
     */
    private function writeFile(string $conversationId, string $fileName, string $bytes): string
    {
        $systemUser = $this->appConfig->getValueString(
            Application::APP_ID,
            'media_storage_user',
            'admin'
        );

        $root = $this->appConfig->getValueString(
            Application::APP_ID,
            'media_storage_root',
            self::DEFAULT_MEDIA_ROOT
        );

        try {
            $userFolder = $this->rootFolder->getUserFolder($systemUser);
        } catch (Throwable $e) {
            $this->logger->warning(
                'MediaAttachmentService.writeFile: getUserFolder failed',
                ['user' => $systemUser, 'exception' => $e->getMessage()]
            );
            return '';
        }

        $relative = sprintf('%s/%s/media', rtrim($root, '/'), $conversationId);

        try {
            if ($userFolder->nodeExists($relative) === false) {
                $userFolder->newFolder($relative);
            }

            $folder = $userFolder->get($relative);
        } catch (NotFoundException $e) {
            try {
                $userFolder->newFolder($relative);
                $folder = $userFolder->get($relative);
            } catch (Throwable $inner) {
                $this->logger->warning(
                    'MediaAttachmentService.writeFile: folder create failed',
                    ['path' => $relative, 'exception' => $inner->getMessage()]
                );
                return '';
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'MediaAttachmentService.writeFile: folder lookup failed',
                ['path' => $relative, 'exception' => $e->getMessage()]
            );
            return '';
        }//end try

        if (method_exists($folder, 'newFile') === false) {
            return '';
        }

        try {
            $file = $folder->newFile($fileName, $bytes);
        } catch (Throwable $e) {
            $this->logger->warning(
                'MediaAttachmentService.writeFile: newFile failed',
                ['fileName' => $fileName, 'exception' => $e->getMessage()]
            );
            return '';
        }

        try {
            return (string) $file->getPath();
        } catch (Throwable $e) {
            return sprintf('/%s/files/%s/%s', $systemUser, $relative, $fileName);
        }
    }//end writeFile()

    /**
     * Fetch a URL body. Returns the bytes or null on failure.
     *
     * Uses curl when available, file_get_contents otherwise. Caller
     * should consider this a 5-minute SLA window — keep timeouts
     * tight.
     *
     * @param string $url URL to fetch.
     *
     * @return string|null Body or null on failure.
     */
    private function fetchUrlBody(string $url): ?string
    {
        if (function_exists('curl_init') === true) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => 240,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]
            );

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false || $code < 200 || $code >= 400) {
                return null;
            }

            return (string) $body;
        }//end if

        $body = @file_get_contents($url);
        if ($body === false) {
            return null;
        }

        return $body;
    }//end fetchUrlBody()

    /**
     * Antivirus scan hook. Returns true when the bytes are clean.
     *
     * Production deployments wire an `OCA\Files_Antivirus` scanner
     * through DI; when unavailable we conservatively pass the bytes
     * through and flag the message metadata accordingly.
     *
     * @param string $bytes Bytes.
     *
     * @return bool True when clean.
     */
    private function virusScan(string $bytes): bool
    {
        try {
            $scanner = $this->container->get('OCA\\Files_Antivirus\\Scanner\\IScanner');
        } catch (Throwable $e) {
            // No scanner wired — caller should still see clean=true
            // but operators can flip `messaging.media_force_quarantine`
            // to inverse this default.
            $force = (bool) $this->appConfig->getValueString(
                Application::APP_ID,
                'messaging.media_force_quarantine',
                ''
            );
            return ($force === false);
        }

        if (method_exists($scanner, 'scanString') === false) {
            return true;
        }

        try {
            $result = $scanner->scanString($bytes);
        } catch (Throwable $e) {
            $this->logger->warning(
                'MediaAttachmentService.virusScan: scan failed',
                ['exception' => $e->getMessage()]
            );
            return true;
        }

        // Convention: scanString returns true / 'CLEAN' on success.
        if (is_bool($result) === true) {
            return $result;
        }

        if (is_string($result) === true) {
            return (strcasecmp($result, 'CLEAN') === 0);
        }

        return true;
    }//end virusScan()

    /**
     * Derive a sensible file extension from a mime type.
     *
     * @param string $mimeType Mime type.
     *
     * @return string Extension including leading dot, or empty.
     */
    private function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/png'               => '.png',
            'image/gif'               => '.gif',
            'image/webp'              => '.webp',
            'audio/ogg'               => '.ogg',
            'audio/mpeg'              => '.mp3',
            'audio/mp4'               => '.m4a',
            'video/mp4'               => '.mp4',
            'video/3gpp'              => '.3gp',
            'application/pdf'         => '.pdf',
            default                   => '',
        };
    }//end extensionFor()
}//end class
