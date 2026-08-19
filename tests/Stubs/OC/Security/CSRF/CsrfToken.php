<?php

/**
 * Test stub for OC\Security\CSRF\CsrfToken.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OC\Security\CSRF;

/**
 * Stub for OC\Security\CSRF\CsrfToken.
 */
class CsrfToken
{
    /**
     * Constructor.
     *
     * @param string $value The token value.
     */
    public function __construct(private string $value)
    {
    }

    /**
     * Return the token value.
     *
     * @return string The token value.
     */
    public function getEncryptedValue(): string
    {
        return $this->value;
    }
}
