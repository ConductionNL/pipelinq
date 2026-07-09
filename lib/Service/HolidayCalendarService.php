<?php

/**
 * Pipelinq HolidayCalendarService.
 *
 * Loads holiday calendars from bundled JSON resources, evaluates whether
 * a date is a holiday, computes Easter-derived movable holidays, supports
 * lustrum-only holidays (Bevrijdingsdag), and merges tenant overrides.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for evaluating holiday calendars used by SLA deadline math.
 *
 * Calendars ship as JSON resources under `lib/Resources/holidays/`. Each
 * calendar declares fixed-date rules (recurring annually) and computed
 * Easter-offset rules. Tenant administrators can layer overrides — extra
 * closures or year-round Bevrijdingsdag — via the
 * `sla_tenant_holiday_overrides` app-config key (JSON object keyed by
 * calendar name → array of `{date, name}` entries).
 *
 * @spec openspec/changes/sla-engine-and-escalation/tasks.md#3.2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HolidayCalendarService
{

    /**
     * In-process cache of parsed calendars.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Cached year-resolved holiday date sets keyed by `calendar:year`.
     *
     * @var array<string, array<string, bool>>
     */
    private array $yearCache = [];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig App configuration provider.
     * @param LoggerInterface $logger    PSR logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Load a calendar definition by name.
     *
     * Returns an empty array (no holidays) when the calendar file is
     * missing or malformed — caller falls back to "no holidays" rather
     * than failing the whole deadline computation.
     *
     * @param string $calendarName The calendar slug (e.g. nl-feestdagen-rijksoverheid).
     *
     * @return array<string, mixed> The parsed calendar definition.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
     */
    public function loadCalendar(string $calendarName): array
    {
        if ($calendarName === '' || $calendarName === 'none') {
            return ['name' => 'none', 'rules' => [], 'computed' => []];
        }

        if (isset($this->cache[$calendarName]) === true) {
            return $this->cache[$calendarName];
        }

        $path = __DIR__.'/../Resources/holidays/nl/'.$calendarName.'.json';
        if (file_exists($path) === false) {
            $this->logger->warning(
                'HolidayCalendarService: calendar file not found, falling back to empty',
                ['calendar' => $calendarName, 'path' => $path]
            );
            $this->cache[$calendarName] = ['name' => $calendarName, 'rules' => [], 'computed' => []];
            return $this->cache[$calendarName];
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                $raw = '{}';
            }

            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($data) === false) {
                throw new RuntimeException('calendar root is not an object');
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'HolidayCalendarService: failed to parse calendar JSON',
                ['calendar' => $calendarName, 'error' => $e->getMessage()]
            );
            $this->cache[$calendarName] = ['name' => $calendarName, 'rules' => [], 'computed' => []];
            return $this->cache[$calendarName];
        }

        $this->cache[$calendarName] = $data;
        return $data;
    }//end loadCalendar()

    /**
     * Compose multiple calendars into the union of their holidays.
     *
     * @param array<int, string> $calendarNames List of calendar slugs.
     *
     * @return array<string, mixed> The merged calendar definition.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-010
     */
    public function compositeCalendar(array $calendarNames): array
    {
        $rules    = [];
        $computed = [];
        foreach ($calendarNames as $name) {
            $cal      = $this->loadCalendar(calendarName: $name);
            $rules    = array_merge($rules, $cal['rules'] ?? []);
            $computed = array_merge($computed, $cal['computed'] ?? []);
        }

        return ['name' => implode(',', $calendarNames), 'rules' => $rules, 'computed' => $computed];
    }//end compositeCalendar()

    /**
     * Check whether the given date is a holiday in the named calendar.
     *
     * Composite calendar names are supported via comma separation
     * (e.g. `nl-feestdagen-rijksoverheid,be-feestdagen`).
     *
     * @param string            $calendarName The calendar slug (or comma list).
     * @param DateTimeInterface $date         The date to check.
     *
     * @return bool True when $date is a recognised holiday.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function isHoliday(string $calendarName, DateTimeInterface $date): bool
    {
        if ($calendarName === '' || $calendarName === 'none') {
            return false;
        }

        $year  = (int) $date->format('Y');
        $cache = $this->resolveYearSet(calendarName: $calendarName, year: $year);

        $key = $date->format('Y-m-d');
        return isset($cache[$key]);
    }//end isHoliday()

    /**
     * Build (and cache) the set of holiday YYYY-MM-DD strings for a year.
     *
     * @param string $calendarName The calendar slug, or comma-separated composite.
     * @param int    $year         The calendar year.
     *
     * @return array<string, bool> Map of YYYY-MM-DD → true.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over rule/computed/override sets; extraction adds no clarity.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential guard clauses over rule/computed/override sets; extraction adds no clarity.
     */
    private function resolveYearSet(string $calendarName, int $year): array
    {
        $cacheKey = $calendarName.':'.$year;
        if (isset($this->yearCache[$cacheKey]) === true) {
            return $this->yearCache[$cacheKey];
        }

        $names = array_filter(array_map('trim', explode(',', $calendarName)));
        $cal   = $this->loadCalendar(calendarName: ($names[0] ?? ''));
        if (count($names) > 1) {
            $cal = $this->compositeCalendar(calendarNames: $names);
        }

        $set = [];
        foreach (($cal['rules'] ?? []) as $rule) {
            if (isset($rule['date']) === false) {
                continue;
            }

            // Lustrum-only holidays (Bevrijdingsdag): only in years divisible by 5.
            if (($rule['lustrum'] ?? false) === true && ($year % 5) !== 0) {
                if ($this->lustrumOverrideEnabled(calendarName: $calendarName) === false) {
                    continue;
                }
            }

            $key       = $year.'-'.$rule['date'];
            $set[$key] = true;
        }

        foreach (($cal['computed'] ?? []) as $computed) {
            $offset = (int) ($computed['easterOffset'] ?? 0);
            $easter = $this->easterDate(year: $year);
            $sign   = '';
            if ($offset >= 0) {
                $sign = '+';
            }

            $date = $easter->modify($sign.$offset.' days');
            $set[$date->format('Y-m-d')] = true;
        }

        // Tenant overrides: JSON-encoded { "<calendar>": [{ "date": "Y-m-d" or "MM-DD", "name": "..." }] }.
        $overrides = $this->loadTenantOverrides();
        foreach ($names as $singleName) {
            foreach (($overrides[$singleName] ?? []) as $entry) {
                if (isset($entry['date']) === false) {
                    continue;
                }

                $raw = (string) $entry['date'];
                $key = $raw;
                if (strlen($raw) === 5) {
                    $key = $year.'-'.$raw;
                }

                $set[$key] = true;
            }
        }

        $this->yearCache[$cacheKey] = $set;
        return $set;
    }//end resolveYearSet()

    /**
     * Whether the tenant has enabled year-round Bevrijdingsdag.
     *
     * @param string $calendarName The calendar slug.
     *
     * @return bool True when the tenant configured the override.
     */
    private function lustrumOverrideEnabled(string $calendarName): bool
    {
        unset($calendarName);
        $value = $this->appConfig->getValueString(Application::APP_ID, 'sla_bevrijdingsdag_yearly', 'false');
        return $value === 'true';
    }//end lustrumOverrideEnabled()

    /**
     * Load tenant-level holiday overrides from app config.
     *
     * @return array<string, array<int, array<string, mixed>>> Map of calendar → entries.
     */
    private function loadTenantOverrides(): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, 'sla_tenant_holiday_overrides', '');
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded) === false) {
                return [];
            }

            return $decoded;
        } catch (Throwable $e) {
            $this->logger->warning(
                'HolidayCalendarService: tenant overrides JSON malformed, ignoring',
                ['error' => $e->getMessage()]
            );
            return [];
        }
    }//end loadTenantOverrides()

    /**
     * Compute the Gregorian Easter date for the given year using the
     * anonymous Gregorian algorithm (Meeus/Jones/Butcher).
     *
     * Implemented in PHP rather than via the `calendar` extension so the
     * service works on the canonical openregister images, where the
     * `ext-calendar` extension is not guaranteed.
     *
     * @param int $year The Gregorian year.
     *
     * @return DateTimeImmutable Easter Sunday in UTC.
     *
     * @SuppressWarnings(PHPMD.ShortVariable) Single-letter names (a..m) mirror the canonical Meeus/Jones/Butcher formula.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-002
     */
    public function easterDate(int $year): DateTimeImmutable
    {
        $a     = $year % 19;
        $b     = intdiv($year, 100);
        $c     = $year % 100;
        $d     = intdiv($b, 4);
        $e     = $b % 4;
        $f     = intdiv(($b + 8), 25);
        $g     = intdiv(($b - $f + 1), 3);
        $h     = ((19 * $a) + $b - $d - $g + 15) % 30;
        $i     = intdiv($c, 4);
        $k     = $c % 4;
        $l     = (32 + (2 * $e) + (2 * $i) - $h - $k) % 7;
        $m     = intdiv(($a + (11 * $h) + (22 * $l)), 451);
        $month = intdiv(($h + $l - (7 * $m) + 114), 31);
        $day   = (($h + $l - (7 * $m) + 114) % 31) + 1;

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new DateTimeZone('UTC')
        );
    }//end easterDate()
}//end class
