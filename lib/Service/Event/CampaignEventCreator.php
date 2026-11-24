<?php

/**
 * Pipelinq CampaignEventCreator.
 *
 * Creates the event of a campaign from the campaign page's Create event
 * dialog: it checks and normalises what the marketer typed (a title, a start
 * and an optional end read in the marketer's own time zone, online or a venue,
 * a number of seats) and writes a `campaignEvent` linked to the campaign as the
 * caller, so OpenRegister's access rules decide whether the write may happen.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Event;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use Throwable;

/**
 * Writes a campaign's event from the Create event dialog.
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */
class CampaignEventCreator
{

    /**
     * The most seats one event can offer.
     *
     * @var integer
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private const MAX_CAPACITY = 100000;

    /**
     * A time typed without a zone, as a `datetime-local` input sends it.
     *
     * @var string
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private const LOCAL_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/';

    /**
     * Constructor.
     *
     * @param ObjectServiceInterface      $objectService OpenRegister's object service, written as the caller.
     * @param CallerObjectReaderInterface $reader        Reads the campaign as the caller.
     * @param IAppConfig                  $appConfig     The schema slugs.
     * @param IDateTimeZone               $timeZone      The caller's time zone, for a time typed without one.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    public function __construct(
        private readonly ObjectServiceInterface $objectService,
        private readonly CallerObjectReaderInterface $reader,
        private readonly IAppConfig $appConfig,
        private readonly IDateTimeZone $timeZone,
    ) {

    }//end __construct()

    /**
     * Whether the caller can read the campaign.
     *
     * @param string $campaignId The campaign id.
     *
     * @return bool True when the campaign exists for the caller.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    public function campaignExists(string $campaignId): bool
    {
        return $this->reader->read(id: $campaignId, schemaKey: 'campaign_schema') !== null;

    }//end campaignExists()

    /**
     * The event as it will be stored, from what the marketer typed.
     *
     * @param string               $campaignId The campaign the event belongs to.
     * @param array<string, mixed> $input      The dialog's fields: title, startAt, endAt, online, venue, joinUrl, capacity, openNow.
     *
     * @return array<string, mixed> The `campaignEvent` payload.
     *
     * @throws InvalidArgumentException With a message the marketer can act on.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    public function payload(string $campaignId, array $input): array
    {
        $title = self::text(value: ($input['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Give the event a title.');
        }

        $start = $this->time(value: ($input['startAt'] ?? ''), label: 'start');
        if ($start === null) {
            throw new InvalidArgumentException('Give the event a start.');
        }

        $event = [
            'title'        => $title,
            'campaign'     => $campaignId,
            'startAt'      => $start->format(DateTimeInterface::ATOM),
            'online'       => self::flag(value: ($input['online'] ?? false)),
            'signupStatus' => 'draft',
        ];
        if (self::flag(value: ($input['openNow'] ?? true)) === true) {
            $event['signupStatus'] = 'open';
        }

        $end = $this->time(value: ($input['endAt'] ?? ''), label: 'end');
        if ($end !== null) {
            if ($end <= $start) {
                throw new InvalidArgumentException('The event must end after it starts.');
            }

            $event['endAt'] = $end->format(DateTimeInterface::ATOM);
        }

        $capacity = self::capacity(value: ($input['capacity'] ?? ''));
        if ($capacity !== null) {
            $event['capacity'] = $capacity;
        }

        return $event + $this->place(input: $input, online: $event['online']);

    }//end payload()

    /**
     * Create the campaign's event as the caller.
     *
     * @param string               $campaignId The campaign the event belongs to.
     * @param array<string, mixed> $input      The dialog's fields.
     *
     * @return array<string, mixed> The stored event, with its `id`.
     *
     * @throws InvalidArgumentException When the input is not a valid event.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    public function create(string $campaignId, array $input): array
    {
        $payload = $this->payload(campaignId: $campaignId, input: $input);
        $saved   = $this->objectService->saveObject(
            object: $payload,
            options: new ObjectSaveOptions(
                target: new ObjectFindTarget(
                    register: $this->reader->register(),
                    schema: $this->appConfig->getValueString(AppIdentity::APP_ID, 'campaignEvent_schema', '')
                )
            )
        );

        return ['id' => (string) $saved->getUuid()] + $saved->getObject();

    }//end create()

    /**
     * The venue or the join link, whichever the event has.
     *
     * @param array<string, mixed> $input  The dialog's fields.
     * @param bool                 $online Whether the event is online.
     *
     * @return array<string, string> `joinUrl` for an online event, `venue` otherwise; empty when none was typed.
     *
     * @throws InvalidArgumentException When the join link is not a web address.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private function place(array $input, bool $online): array
    {
        if ($online === true) {
            $joinUrl = self::text(value: ($input['joinUrl'] ?? ''));
            if ($joinUrl === '') {
                return [];
            }

            if (preg_match('#^https?://#i', $joinUrl) !== 1 || filter_var($joinUrl, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('The join link must be a web address starting with https://.');
            }

            return ['joinUrl' => $joinUrl];
        }

        $venue = self::text(value: ($input['venue'] ?? ''));
        if ($venue === '') {
            return [];
        }

        return ['venue' => $venue];

    }//end place()

    /**
     * A moment typed in the dialog: a time with a zone as given, a time
     * without one in the caller's own zone.
     *
     * @param mixed  $value The typed value.
     * @param string $label Which moment, for the refusal.
     *
     * @return DateTimeImmutable|null The moment, or null when nothing was typed.
     *
     * @throws InvalidArgumentException When the value is not a moment.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private function time(mixed $value, string $label): ?DateTimeImmutable
    {
        $text = self::text(value: $value);
        if ($text === '') {
            return null;
        }

        $zone = null;
        if (preg_match(self::LOCAL_TIME, $text) === 1) {
            $zone = $this->zone();
        }

        try {
            return new DateTimeImmutable($text, $zone);
        } catch (Throwable) {
            throw new InvalidArgumentException('The '.$label.' is not a date and time.');
        }

    }//end time()

    /**
     * The caller's time zone, UTC when Nextcloud cannot say.
     *
     * @return DateTimeZone The zone.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private function zone(): DateTimeZone
    {
        try {
            return $this->timeZone->getTimeZone();
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }

    }//end zone()

    /**
     * The number of seats, or null when none was typed.
     *
     * @param mixed $value The typed value.
     *
     * @return int|null The seats, null for none typed or zero (no limit).
     *
     * @throws InvalidArgumentException When it is not a whole number.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private static function capacity(mixed $value): ?int
    {
        if (is_int($value) === true) {
            $text = (string) $value;
        } else {
            $text = self::text(value: $value);
        }

        if ($text === '') {
            return null;
        }

        if (preg_match('/^[0-9]{1,6}$/', $text) !== 1 || (int) $text > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('The number of seats must be a whole number.');
        }

        // Zero seats means no limit, as on the event itself: nothing is stored.
        if ((int) $text === 0) {
            return null;
        }

        return (int) $text;

    }//end capacity()

    /**
     * A trimmed string, empty for anything that is not text.
     *
     * @param mixed $value The value.
     *
     * @return string The text.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private static function text(mixed $value): string
    {
        if (is_string($value) === false) {
            return '';
        }

        return trim($value);

    }//end text()

    /**
     * A yes or no sent as a boolean or as text.
     *
     * @param mixed $value The value.
     *
     * @return bool True for true, 1, "1", "true", "on" or "yes".
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    private static function flag(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_scalar($value) === false) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);

    }//end flag()
}//end class
