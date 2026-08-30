<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider.
 *
 * The interface pipelinq's PipelinqEvidenceSourceProvider implements. Loaded
 * via the autoload-dev PSR-4 map ("OCA\\OpenRegister\\" => "tests/Stubs/") and
 * inert when the real openregister app is present (interface_exists guard).
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

if (interface_exists(EvidenceSourceProvider::class) === false) {
	/**
	 * Stub of OR's EvidenceSourceProvider interface (unit tests only).
	 */
	interface EvidenceSourceProvider {
		/**
		 * @return string The stable provider id.
		 */
		public function getSourceId(): string;

		/**
		 * @return bool Whether the provider can harvest now.
		 */
		public function isEnabled(): bool;

		/**
		 * @param string $caseUuid The case uuid.
		 * @param array<string, mixed> $case The case payload.
		 *
		 * @return EvidenceItem[] The harvested items.
		 */
		public function harvest(string $caseUuid, array $case): array;
	}//end interface
}//end if
