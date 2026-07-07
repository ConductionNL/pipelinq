<?php

/**
 * Test stub for OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry.
 *
 * Mirrors the `addProvider` seam pipelinq registers into at boot (first-wins).
 * Loaded via the autoload-dev PSR-4 map ("OCA\\OpenRegister\\" =>
 * "tests/Stubs/") and inert when the real openregister app is present
 * (class_exists guard).
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

if (class_exists(EvidenceSourceRegistry::class) === false) {
    /**
     * Stub of OR's EvidenceSourceRegistry (unit tests only).
     */
    class EvidenceSourceRegistry
    {
        /**
         * Registered providers keyed by source id.
         *
         * @var array<string, EvidenceSourceProvider>
         */
        private array $providers = [];

        /**
         * Register a provider (first-wins).
         *
         * @param EvidenceSourceProvider $provider The provider.
         *
         * @return bool True when added, false when a provider with the same id existed.
         */
        public function addProvider(EvidenceSourceProvider $provider): bool
        {
            $id = $provider->getSourceId();
            if (isset($this->providers[$id]) === true) {
                return false;
            }

            $this->providers[$id] = $provider;
            return true;
        }//end addProvider()

        /**
         * @param string $id The provider id.
         *
         * @return EvidenceSourceProvider|null The provider, or null.
         */
        public function get(string $id): ?EvidenceSourceProvider
        {
            return ($this->providers[$id] ?? null);
        }//end get()

        /**
         * @return array<int, string> The registered provider ids.
         */
        public function listIds(): array
        {
            return array_keys($this->providers);
        }//end listIds()
    }//end class
}//end if
