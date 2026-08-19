<?php

/**
 * Test stub for OC\Security\CSRF\CsrfTokenManager.
 *
 * Provides a mockable concrete class for unit tests so that
 * createMock(CsrfTokenManager::class) works without the real NC server.
 * The fleet-wide decision on whether to expose this via OCP is pending
 * (tracked in the phpstan-baseline.neon CsrfTokenManager note).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OC\Security\CSRF;

/**
 * Stub for OC\Security\CSRF\CsrfTokenManager.
 */
class CsrfTokenManager
{
    /**
     * Return a CSRF token for the current session.
     *
     * @return CsrfToken The CSRF token.
     */
    public function getToken(): CsrfToken
    {
        return new CsrfToken('stub-token');
    }

    /**
     * Check whether the given token is valid.
     *
     * @param CsrfToken $token The token to validate.
     *
     * @return bool Always true in this stub.
     */
    public function isTokenValid(CsrfToken $token): bool
    {
        return true;
    }
}
