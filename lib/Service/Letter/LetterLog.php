<?php

/**
 * LetterLog: a letter made from a filinq template, logged on the client.
 *
 * The letter is logged as an outgoing contact moment in the contact moment
 * vocabulary (channel `brief`), under the ticket when it was made from one. A
 * failure to log does not undo the letter: it is already in the user's Files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Letter
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Letter;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs letters as contact moments.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 */
class LetterLog
{

    /**
     * The channel a letter is logged with, in the contact moment vocabulary.
     *
     * @var string
     */
    public const LETTER_CHANNEL = 'brief';


    /**
     * Constructor.
     *
     * @param TicketService   $tickets Saves the contact moment.
     * @param LoggerInterface $logger  Logger.
     */
    public function __construct(
        private readonly TicketService $tickets,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Log the letter as an outgoing contact moment on the client.
     *
     * A failure here does not undo the letter: it is already in the user's
     * Files. The answer then carries no contact moment id, and the dialog says
     * so.
     *
     * @param string      $clientId     The client id.
     * @param string|null $contactId    The contact person, if one was chosen.
     * @param string|null $ticketId     The ticket the letter was made from, if any.
     * @param string      $templateName The template's name.
     * @param string|null $path         Where filinq filed the copy.
     *
     * @return string|null The contact moment id, or null when it could not be saved.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     */
    public function log(string $clientId, ?string $contactId, ?string $ticketId, string $templateName, ?string $path): ?string
    {
        $letter  = new LoggedLetter(
            clientId: $clientId,
            contactId: $contactId,
            ticketId: $ticketId,
            templateName: $templateName,
            path: $path,
        );
        $payload = self::contactMomentPayload(letter: $letter, occurredAt: new DateTimeImmutable());

        try {
            $saved = $this->tickets->save(ticketType: TicketService::TYPE_CONTACTMOMENT, payload: $payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'Pipelinq: the letter is made but its contact moment was not saved',
                ['app' => Application::APP_ID, 'clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return null;
        }

        $data = (array) $saved;
        if (method_exists($saved, 'jsonSerialize') === true) {
            $data = (array) $saved->jsonSerialize();
        }

        $self = (array) ($data['@self'] ?? []);
        $id   = (string) ($data['id'] ?? ($self['id'] ?? ($data['uuid'] ?? '')));
        if ($id === '') {
            return null;
        }

        return $id;

    }//end log()


    /**
     * The contact moment a letter is logged as, before `ticketType` is forced.
     *
     * @param LoggedLetter      $letter     The letter.
     * @param DateTimeImmutable $occurredAt When the letter was made.
     *
     * @return array<string, string> The payload.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     */
    public static function contactMomentPayload(LoggedLetter $letter, DateTimeImmutable $occurredAt): array
    {
        $payload = [
            'client'      => $letter->clientId,
            'title'       => mb_substr('Letter: '.$letter->templateName, 0, 255),
            'direction'   => 'outbound',
            'channel'     => self::LETTER_CHANNEL,
            'outcome'     => 'handled',
            'description' => self::description(letter: $letter),
            'occurredAt'  => $occurredAt->format('c'),
        ];

        return self::withLinks(payload: $payload, letter: $letter);

    }//end contactMomentPayload()


    /**
     * The contact moment description: the template, and where the copy is filed.
     *
     * @param LoggedLetter $letter The letter.
     *
     * @return string The description.
     */
    private static function description(LoggedLetter $letter): string
    {
        $description = 'Letter made from the filinq template "'.$letter->templateName.'".';
        if ($letter->path !== null && $letter->path !== '') {
            $description .= ' A copy is filed at '.$letter->path.'.';
        }

        return $description;

    }//end description()


    /**
     * Add the contact person and the parent ticket, when the letter has them.
     *
     * @param array<string, string> $payload The contact moment payload.
     * @param LoggedLetter          $letter  The letter.
     *
     * @return array<string, string> The payload with its links.
     */
    private static function withLinks(array $payload, LoggedLetter $letter): array
    {
        if ($letter->contactId !== null) {
            $payload['contact'] = $letter->contactId;
        }

        if ($letter->ticketId !== null) {
            $payload['parentTicket'] = $letter->ticketId;
        }

        return $payload;

    }//end withLinks()


}//end class
