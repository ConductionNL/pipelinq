<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider.
 *
 * Mirrors the interface signature shipped by openregister (dsar-integration-seams).
 * Used only in environments where the openregister runtime is not installed
 * (e.g. bare CI containers). Loaded via Composer's autoload-dev PSR-4 mapping
 * (OCA\OpenRegister\ -> tests/Stubs/) when the real interface is absent. NOT
 * scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Gdpr\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Identity;

/**
 * Stub of OR's registerable identity-verification provider.
 */
interface IdentityVerifyProvider {
	/**
	 * @return string
	 */
	public function getProviderId(): string;

	/**
	 * @param string $caseUuid The case object uuid.
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return IdentityVerifyResult
	 */
	public function verify(string $caseUuid, array $case): IdentityVerifyResult;
}
