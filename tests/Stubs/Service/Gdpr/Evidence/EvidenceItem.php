<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem.
 *
 * Mirrors the minimal surface pipelinq's PipelinqEvidenceSourceProvider
 * constructs and returns (sourceId + contentHash + status + payload). Loaded
 * via the autoload-dev PSR-4 map ("OCA\\OpenRegister\\" => "tests/Stubs/") and
 * inert when the real openregister app is present (class_exists guard).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Gdpr\Evidence
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Evidence;

if (class_exists(EvidenceItem::class) === false) {
	/**
	 * Stub of OR's EvidenceItem value object (unit tests only).
	 */
	final class EvidenceItem {
		/**
		 * Collected status.
		 *
		 * @var string
		 */
		public const STATUS_COLLECTED = 'collected';

		/**
		 * Pending status.
		 *
		 * @var string
		 */
		public const STATUS_PENDING = 'pending';

		/**
		 * Failed status.
		 *
		 * @var string
		 */
		public const STATUS_FAILED = 'failed';

		/**
		 * Constructor mirroring OR's EvidenceItem.
		 *
		 * @param string $sourceId The source/provider id.
		 * @param string $contentHash The dedup content hash.
		 * @param string $status The per-item status.
		 * @param array<string, mixed> $payload Optional dossier payload.
		 */
		public function __construct(
			private readonly string $sourceId,
			private readonly string $contentHash,
			private readonly string $status = self::STATUS_COLLECTED,
			private readonly array $payload = [],
		) {
		}//end __construct()

		/**
		 * @return string The source id.
		 */
		public function getSourceId(): string {
			return $this->sourceId;
		}//end getSourceId()

		/**
		 * @return string The content hash.
		 */
		public function getContentHash(): string {
			return $this->contentHash;
		}//end getContentHash()

		/**
		 * @return string The status.
		 */
		public function getStatus(): string {
			return $this->status;
		}//end getStatus()

		/**
		 * @return array<string, mixed> The payload.
		 */
		public function getPayload(): array {
			return $this->payload;
		}//end getPayload()

		/**
		 * @return array{sourceId: string, contentHash: string, status: string} The record.
		 */
		public function toEvidenceRecord(): array {
			return [
				'sourceId' => $this->sourceId,
				'contentHash' => $this->contentHash,
				'status' => $this->status,
			];
		}//end toEvidenceRecord()
	}//end class
}//end if
