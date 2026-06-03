<?php

/**
 * Pipelinq ScreenPopResult.
 *
 * Value object describing how the agent UI should react to an inbound caller
 * lookup: navigate to a single match, offer a chooser, or open an intake form.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * Outcome of a screen-pop caller lookup.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */
class ScreenPopResult
{
    /**
     * Navigate the agent's browser directly to a single matched contact.
     *
     * @var string
     */
    public const ACTION_NAVIGATE = 'navigate';

    /**
     * Offer the agent a chooser of multiple matched contacts.
     *
     * @var string
     */
    public const ACTION_CHOOSER = 'chooser';

    /**
     * Open a pre-filled new-contact intake form (no match).
     *
     * @var string
     */
    public const ACTION_INTAKE = 'intake';

    /**
     * Constructor.
     *
     * @param string                           $action     One of the ACTION_* constants.
     * @param string                           $e164Number The normalised caller number (may be empty when unparseable).
     * @param string                           $rawNumber  The raw caller number.
     * @param array<int, array<string, mixed>> $matches    The matched contacts (0..3).
     * @param int|null                         $delayMs    Screen-pop navigation delay in milliseconds.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $e164Number,
        public readonly string $rawNumber,
        public readonly array $matches=[],
        public readonly ?int $delayMs=0,
    ) {
    }//end __construct()

    /**
     * Serialise to a JSON-friendly array for the API response.
     *
     * @return array<string, mixed> The serialised result.
     */
    public function toArray(): array
    {
        return [
            'action'     => $this->action,
            'e164Number' => $this->e164Number,
            'rawNumber'  => $this->rawNumber,
            'matches'    => $this->matches,
            'delayMs'    => $this->delayMs,
        ];
    }//end toArray()
}//end class
