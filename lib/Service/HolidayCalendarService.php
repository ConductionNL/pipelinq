<?php

/**
 * Pipelinq HolidayCalendarService.
 *
 * Loads pluggable holiday calendars (bundled JSON per locale + per-tenant
 * overrides) and answers holiday lookups for SLA deadline computation.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Loads and resolves holiday calendars for the SLA engine (REQ-010).
 *
 * Calendars are sourced from `lib/Resources/holidays/{locale}/{name}.json`
 * shipped per locale, and may be extended or overridden per tenant through the
 * `sla_holiday_overrides` app-config key. Multiple calendars can be composited
 * (comma-separated names) with OR semantics — the union of all holidays.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
 */
class HolidayCalendarService
{
    /**
     * App-config key holding the per-tenant holiday override JSON.
     *
     * Shape: { "<calendarName>": { "extraDates": ["YYYY-MM-DD", ...],
     *          "ranges": [{ "from": "YYYY-MM-DD", "to": "YYYY-MM-DD" }],
     *          "forceLustrum": true } }
     *
     * @var string
     */
    public const OVERRIDES_KEY = 'sla_holiday_overrides';

    /**
     * Locale subdirectories searched for a bundled calendar file.
     *
     * @var array<int, string>
     */
    private const LOCALE_DIRS = ['nl', 'be', 'de', 'eu'];

    /**
     * In-memory cache of loaded calendars keyed by calendar name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app configuration (tenant overrides).
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Load a single named calendar (bundled file + tenant overrides).
     *
     * Returns an empty structure (never throws) when the file is missing or
     * malformed, so a bad calendar degrades to "no holidays" rather than losing
     * escalations.
     *
     * @param string $calendarName The calendar name (e.g. nl-feestdagen-rijksoverheid).
     *
     * @return array{recurringFixed: array<int, array<string, mixed>>, lustrum: array<int, array<string, mixed>>, dates: array<string, string>}
     */
    public function loadCalendar(string $calendarName): array
    {
        $calendarName = trim($calendarName);
        if ($calendarName === '' || $calendarName === 'none') {
            return ['recurringFixed' => [], 'lustrum' => [], 'dates' => []];
        }

        if (isset($this->cache[$calendarName]) === true) {
            return $this->cache[$calendarName];
        }

        $raw    = $this->readBundledCalendar(calendarName: $calendarName);
        $parsed = [
            'recurringFixed' => ($raw['recurringFixed'] ?? []),
            'lustrum'        => ($raw['lustrum'] ?? []),
            'dates'          => $this->indexDates(entries: ($raw['dates'] ?? [])),
        ];

        $parsed = $this->applyTenantOverrides(calendarName: $calendarName, parsed: $parsed);

        $this->cache[$calendarName] = $parsed;
        return $parsed;
    }//end loadCalendar()

    /**
     * Build the union of multiple named calendars (REQ-010 composite, OR logic).
     *
     * @param array<int, string> $calendarNames The calendar names to union.
     *
     * @return array{recurringFixed: array<int, array<string, mixed>>, lustrum: array<int, array<string, mixed>>, dates: array<string, string>}
     */
    public function compositeCalendar(array $calendarNames): array
    {
        $result = ['recurringFixed' => [], 'lustrum' => [], 'dates' => []];

        foreach ($calendarNames as $name) {
            $cal = $this->loadCalendar(calendarName: (string) $name);
            $result['recurringFixed'] = array_merge($result['recurringFixed'], $cal['recurringFixed']);
            $result['lustrum']        = array_merge($result['lustrum'], $cal['lustrum']);
            $result['dates']          = ($cal['dates'] + $result['dates']);
        }

        return $result;
    }//end compositeCalendar()

    /**
     * Determine whether a given date is a holiday in the named calendar(s).
     *
     * Accepts a comma-separated calendar specification so a tenant can composite
     * calendars (e.g. "nl-feestdagen-rijksoverheid,be-feestdagen").
     *
     * @param string            $calendarSpec Comma-separated calendar name(s), or "none".
     * @param DateTimeInterface $date         The date to test (time part ignored).
     *
     * @return bool True when the date is a holiday.
     */
    public function isHoliday(string $calendarSpec, DateTimeInterface $date): bool
    {
        $calendar = $this->resolveCalendar(calendarSpec: $calendarSpec);
        if ($calendar === null) {
            return false;
        }

        if (isset($calendar['dates'][$date->format('Y-m-d')]) === true) {
            return true;
        }

        $month = (int) $date->format('n');
        $day   = (int) $date->format('j');
        $year  = (int) $date->format('Y');

        if ($this->matchesFixed(entries: $calendar['recurringFixed'], month: $month, day: $day) === true) {
            return true;
        }

        return ($year % 5) === 0 && $this->matchesFixed(entries: $calendar['lustrum'], month: $month, day: $day);
    }//end isHoliday()

    /**
     * Resolve a (possibly composite) calendar spec to a merged calendar array.
     *
     * @param string $calendarSpec Comma-separated calendar name(s), or "none".
     *
     * @return array<string, mixed>|null The calendar, or null when none applies.
     */
    private function resolveCalendar(string $calendarSpec): ?array
    {
        $calendarSpec = trim($calendarSpec);
        if ($calendarSpec === '' || $calendarSpec === 'none') {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $calendarSpec))));
        if (count($names) === 1) {
            return $this->loadCalendar(calendarName: $names[0]);
        }

        return $this->compositeCalendar(calendarNames: $names);
    }//end resolveCalendar()

    /**
     * Whether any fixed-date entry matches the given month and day.
     *
     * @param array<int, array<string, mixed>> $entries The recurring entries.
     * @param int                              $month   The month (1-12).
     * @param int                              $day     The day of month.
     *
     * @return bool True on a month/day match.
     */
    private function matchesFixed(array $entries, int $month, int $day): bool
    {
        foreach ($entries as $entry) {
            if ((int) ($entry['month'] ?? 0) === $month && (int) ($entry['day'] ?? 0) === $day) {
                return true;
            }
        }

        return false;
    }//end matchesFixed()

    /**
     * Read and decode a bundled calendar file by name.
     *
     * @param string $calendarName The calendar name.
     *
     * @return array<string, mixed> The decoded calendar, or an empty array.
     */
    private function readBundledCalendar(string $calendarName): array
    {
        $base = $this->resourcesPath();

        foreach (self::LOCALE_DIRS as $locale) {
            $path = $base.'/'.$locale.'/'.$calendarName.'.json';
            if (is_file($path) === false) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                $this->logger->warning('HolidayCalendarService: unreadable calendar file', ['path' => $path]);
                return [];
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->logger->warning(
                    'HolidayCalendarService: malformed calendar JSON, treating as empty',
                    ['calendar' => $calendarName, 'exception' => $e->getMessage()]
                );
                return [];
            }

            if (is_array($decoded) === true) {
                return $decoded;
            }

            return [];
        }//end foreach

        $this->logger->info('HolidayCalendarService: calendar not found, treating as no-holidays', ['calendar' => $calendarName]);
        return [];
    }//end readBundledCalendar()

    /**
     * Re-index a list of {date,name} entries to a date=>name map.
     *
     * @param array<int, array<string, mixed>> $entries The raw date entries.
     *
     * @return array<string, string> Map of YYYY-MM-DD to holiday name.
     */
    private function indexDates(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $date = (string) ($entry['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $map[$date] = (string) ($entry['name'] ?? '');
        }

        return $map;
    }//end indexDates()

    /**
     * Merge per-tenant overrides onto a parsed calendar.
     *
     * Tenant overrides can add extra single dates, add closure ranges (expanded
     * to individual dates), and force lustrum holidays to apply every year.
     *
     * @param string               $calendarName The calendar name.
     * @param array<string, mixed> $parsed       The parsed calendar.
     *
     * @return array<string, mixed>
     */
    private function applyTenantOverrides(string $calendarName, array $parsed): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::OVERRIDES_KEY, '');
        if ($raw === '') {
            return $parsed;
        }

        try {
            $all = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('HolidayCalendarService: malformed tenant override JSON, ignoring', ['exception' => $e->getMessage()]);
            return $parsed;
        }

        if (is_array($all) === false || isset($all[$calendarName]) === false || is_array($all[$calendarName]) === false) {
            return $parsed;
        }

        return $this->mergeOverride(parsed: $parsed, override: $all[$calendarName]);
    }//end applyTenantOverrides()

    /**
     * Merge a single tenant override block onto a parsed calendar.
     *
     * @param array<string, mixed> $parsed   The parsed calendar.
     * @param array<string, mixed> $override The tenant override block.
     *
     * @return array<string, mixed> The calendar with the override applied.
     */
    private function mergeOverride(array $parsed, array $override): array
    {
        foreach (($override['extraDates'] ?? []) as $date) {
            $parsed['dates'][(string) $date] = 'tenant-override';
        }

        foreach (($override['ranges'] ?? []) as $range) {
            $parsed['dates'] = ($this->expandRange(range: $range) + $parsed['dates']);
        }

        if (($override['forceLustrum'] ?? false) === true) {
            $parsed['recurringFixed'] = array_merge($parsed['recurringFixed'], $parsed['lustrum']);
            $parsed['lustrum']        = [];
        }

        return $parsed;
    }//end mergeOverride()

    /**
     * Expand a {from,to} closure range to a date=>name map (inclusive).
     *
     * @param array<string, mixed> $range The range definition.
     *
     * @return array<string, string> Map of each date in range to its label.
     */
    private function expandRange(array $range): array
    {
        $from = (string) ($range['from'] ?? '');
        $to   = (string) ($range['to'] ?? '');
        if ($from === '' || $to === '') {
            return [];
        }

        try {
            $cursor = new DateTimeImmutable($from.' 00:00:00');
            $end    = new DateTimeImmutable($to.' 00:00:00');
        } catch (\Exception $e) {
            $this->logger->warning('HolidayCalendarService: invalid override range', ['range' => $range]);
            return [];
        }

        if ($end < $cursor) {
            return [];
        }

        $label = (string) ($range['name'] ?? 'tenant-closure');
        $map   = [];
        // Hard cap of 400 days to avoid runaway expansion from bad config.
        for ($i = 0; $i < 400 && $cursor <= $end; $i++) {
            $map[$cursor->format('Y-m-d')] = $label;
            $cursor = $cursor->modify('+1 day');
        }

        return $map;
    }//end expandRange()

    /**
     * Resolve the absolute path to the bundled holiday resources directory.
     *
     * @return string The resources directory path.
     */
    private function resourcesPath(): string
    {
        return dirname(__DIR__).'/Resources/holidays';
    }//end resourcesPath()
}//end class
