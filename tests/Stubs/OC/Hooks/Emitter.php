<?php

/**
 * Test stub for the internal OC\Hooks\Emitter interface.
 *
 * `OCP\Files\IRootFolder` extends this internal interface, which is not part of
 * the public `nextcloud/ocp` package. Declaring a minimal stub lets unit tests
 * create mocks of IRootFolder in a bare environment (no installed Nextcloud).
 * It is a no-op when the real Nextcloud server is present (interface_exists
 * guard).
 *
 * @category Test
 * @package  OC\Hooks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OC\Hooks;

if (interface_exists(Emitter::class) === false) {
    /**
     * Stub interface for OC\Hooks\Emitter — used only in standalone unit tests.
     */
    interface Emitter
    {
        /**
         * Register a listener for a hook.
         *
         * @param string   $scope    The hook scope.
         * @param string   $method   The hook method.
         * @param callable $callback The listener.
         *
         * @return void
         */
        public function listen($scope, $method, callable $callback);

        /**
         * Remove a listener for a hook.
         *
         * @param string|null   $scope    The hook scope.
         * @param string|null   $method   The hook method.
         * @param callable|null $callback The listener.
         *
         * @return void
         */
        public function removeListener($scope = null, $method = null, ?callable $callback = null);
    }//end interface
}//end if
