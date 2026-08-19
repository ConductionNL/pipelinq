<?php

/**
 * Test stub for OpenRegister's Lifecycle GuardResult value object.
 *
 * Mirrors the real OCA\OpenRegister\Lifecycle\GuardResult so pipelinq's
 * lifecycle guards can be unit-tested without the openregister app installed.
 * Guarded with class_exists() in tests/bootstrap.php so the real class wins
 * when OpenRegister is present.
 *
 * @category Test
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Allow / deny verdict from a lifecycle guard.
 */
final class GuardResult
{
    /**
     * Constructor.
     *
     * @param bool        $allowed Whether the transition should be allowed.
     * @param string|null $message Optional deny message.
     */
    private function __construct(private bool $allowed, private ?string $message)
    {
    }//end __construct()

    /**
     * Allow the transition.
     *
     * @return self Allow verdict instance.
     */
    public static function allow(): self
    {
        return new self(allowed: true, message: null);
    }//end allow()

    /**
     * Deny the transition with a user-visible message.
     *
     * @param string $message Human-readable reason.
     *
     * @return self Deny verdict instance.
     */
    public static function deny(string $message): self
    {
        return new self(allowed: false, message: $message);
    }//end deny()

    /**
     * Read whether the verdict allows the transition.
     *
     * @return bool True when allowed.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }//end isAllowed()

    /**
     * Read the deny message, if any.
     *
     * @return string|null Deny message, or null when allowed.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }//end getMessage()
}//end class
