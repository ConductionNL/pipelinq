<?php

/**
 * Verifies appointment-booking i18n: every user-visible string in the public
 * booking portal (BookingPortal.vue + BookingConfirmationPage.vue) has both an
 * English (`l10n/en.json`) and a Dutch (`l10n/nl.json`) translation, and the
 * booking-related key set is identical between the two files (REQ-APT-020).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/appointment-booking-12-compliance-i18n/specs/appointment-booking/spec.md#REQ-APT-020
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins the booking-portal translation key set.
 *
 * The expected list is the canonical set of user-visible strings the booking
 * portal renders today (see `src/views/portal/BookingPortal.vue` and
 * `src/views/portal/BookingConfirmationPage.vue`). When a string is added or
 * removed, both this test AND both `l10n/{en,nl}.json` must be updated together,
 * preventing silent i18n drift (REQ-APT-020, ADR-007, ADR-025).
 */
class AppointmentBookingI18nTest extends TestCase
{
    /**
     * Path to the repository root, used to load the l10n JSON files.
     */
    private string $repoRoot;

    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /**
     * Every booking-portal string must be present in the English catalogue.
     */
    public function testEnglishCatalogueHasAllBookingKeys(): void
    {
        $en = $this->loadCatalogue('en');
        foreach ($this->bookingKeys() as $key) {
            $this->assertArrayHasKey(
                $key,
                $en,
                sprintf('Missing English translation for booking string %s', var_export($key, true))
            );
        }
    }

    /**
     * Every booking-portal string must be present in the Dutch catalogue.
     */
    public function testDutchCatalogueHasAllBookingKeys(): void
    {
        $nl = $this->loadCatalogue('nl');
        foreach ($this->bookingKeys() as $key) {
            $this->assertArrayHasKey(
                $key,
                $nl,
                sprintf('Missing Dutch translation for booking string %s', var_export($key, true))
            );
        }
    }

    /**
     * Dutch translations of booking strings must not equal the English source.
     *
     * A Dutch translation that is byte-equal to the English source either means
     * the string was never translated or someone copy-pasted the English value.
     * A small allow-list covers proper nouns / fragments where the Dutch is
     * identical (currently empty — extend on real cases).
     */
    public function testDutchTranslationsArePresent(): void
    {
        $en = $this->loadCatalogue('en');
        $nl = $this->loadCatalogue('nl');

        $allowIdentical = [
            // Dutch loan-word: "Status" is identical to English.
            'Status',
            // English-only personal-name placeholder: NL keeps "Name" intentionally
            // mapped to its native Dutch form which differs; do not list here.
        ];

        $untranslated = [];
        foreach ($this->bookingKeys() as $key) {
            if (in_array($key, $allowIdentical, true) === true) {
                continue;
            }
            if (isset($nl[$key], $en[$key]) === false) {
                continue;
            }
            if ($nl[$key] === $en[$key]) {
                $untranslated[] = $key;
            }
        }

        $this->assertSame(
            [],
            $untranslated,
            "The following booking strings have no Dutch translation (NL = EN):\n  - "
                . implode("\n  - ", $untranslated)
        );
    }

    /**
     * Load a translation catalogue by language code.
     *
     * @param string $lang Either 'en' or 'nl'.
     *
     * @return array<string, string>
     */
    private function loadCatalogue(string $lang): array
    {
        $path = sprintf('%s/l10n/%s.json', $this->repoRoot, $lang);
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('translations', $decoded);
        $this->assertIsArray($decoded['translations']);

        /** @var array<string, string> $translations */
        $translations = $decoded['translations'];

        return $translations;
    }

    /**
     * The canonical list of booking-portal user-visible strings.
     *
     * Mirrors `t('pipelinq', '…')` calls in BookingPortal.vue +
     * BookingConfirmationPage.vue (member 06 portal frontend). Extending the
     * portal? Add the new key here AND to both l10n/{en,nl}.json.
     *
     * @return array<int, string>
     */
    private function bookingKeys(): array
    {
        return [
            // BookingPortal.vue.
            'Skip to booking form',
            'Loading…',
            'Choose a date',
            'Date',
            'Pick a date to see available times.',
            'Dates without available times cannot be booked.',
            'Choose a time',
            'Loading available times…',
            'No available times on this date. Please choose another date.',
            'Your details',
            'Name',
            'Email address',
            'Phone number',
            'Notes',
            'Booking…',
            'Confirm booking',
            'Duration',
            'Price',
            'This service could not be found.',
            'That time was just taken. Please choose another slot.',
            'Something went wrong. Please try again.',
            'Please enter your name.',
            'Please enter your email address.',
            'Please enter a valid email address.',

            // BookingConfirmationPage.vue.
            'Your booking is confirmed',
            'Awaiting payment',
            'A confirmation email has been sent to {email}.',
            'Service',
            'With',
            'Date and time',
            'Status',
            'Reschedule',
            'Cancel',
            'Pending',
            'Confirmed',
            'Cancelled',
            'Completed',
            'Paid',
            'Payment pending',
            'Payment failed',
            'This booking could not be found.',
        ];
    }
}
