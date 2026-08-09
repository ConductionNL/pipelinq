<?php

/**
 * Pipelinq EligibilityService.
 *
 * Skill-based eligibility for the appointment-booking surface (member 03 of 12).
 * Determines which Resources are qualified to perform a given Service by
 * intersecting `Service.requiredSkills` (or per-step `multiStep[*].skillRequired`)
 * with `Resource.skills`, then optionally narrows the result with member-02
 * availability so unqualified, unavailable resources never surface.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolve which Resources are eligible to perform a Service.
 *
 * Skill-routing source of truth (ADR-012): the booking domain MUST NOT
 * reimplement skill logic. The single matching primitive lives in this
 * service's {@see matchesSkillRequirements()} helper — every booking-domain
 * caller funnels through it so eligibility semantics stay aligned with
 * skill-routing. When the upstream `SkillMatchService` ships as a distinct
 * class (member 11 admin UI swaps the seam), this helper becomes a thin
 * delegate.
 *
 * The service is read-only: it loads `Service` + `Resource` entities via
 * OpenRegister and returns the qualified subset. Intersection with
 * availability is opt-in (`getEligibleResourcesForSlot()`).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cohesive eligibility matching logic; splitting would fragment a single primitive.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class EligibilityService
{
    /**
     * App-config key for the Service schema id/slug.
     *
     * @var string
     */
    public const SERVICE_SCHEMA_KEY = 'service_schema';

    /**
     * App-config key for the Resource schema id/slug.
     *
     * @var string
     */
    public const RESOURCE_SCHEMA_KEY = 'resource_schema';

    /**
     * Constructor.
     *
     * @param ContainerInterface  $container           The DI container (OpenRegister lookup).
     * @param IAppConfig          $appConfig           The app configuration.
     * @param AvailabilityService $availabilityService The availability seam (member 02).
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private AvailabilityService $availabilityService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return Resources qualified to perform the given Service.
     *
     * Loads the Service, reads `requiredSkills`, then filters the Resource
     * collection so only resources whose `skills` cover every required skill
     * are returned. Resources with no skills are eligible only when the Service
     * requires no skills (the empty-set rule, REQ-APT-004 scenario 2).
     *
     * @param string $serviceId Service UUID/slug. Empty string returns an empty list.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/appointment-booking/spec.md
     */
    public function getEligibleResources(string $serviceId): array
    {
        if ($serviceId === '') {
            return [];
        }

        $service = $this->loadService(serviceId: $serviceId);
        if ($service === null) {
            return [];
        }

        $required  = $this->normaliseSkillList(value: ($service['requiredSkills'] ?? []));
        $resources = $this->loadResources();

        $eligible = [];
        foreach ($resources as $resource) {
            if ($this->matchesSkillRequirements(resource: $resource, requiredSkills: $required) === true) {
                $eligible[] = $resource;
            }
        }

        return $eligible;
    }//end getEligibleResources()

    /**
     * Return per-step eligibility for a multi-step Service.
     *
     * For each step in `Service.multiStep`, produce an entry containing the
     * step's `durationMinutes`, the `allowGap` flag, and the list of qualified
     * Resources (empty for gap steps — gap steps require no resource). When
     * the Service has no `multiStep`, a single synthetic step is returned with
     * the full Service `requiredSkills`.
     *
     * @param string $serviceId Service UUID/slug. Empty string returns an empty list.
     *
     * @return array<int, array{stepIndex: int, durationMinutes: int,
     *     skillRequired: string, allowGap: bool,
     *     eligibleResources: array<int, array<string, mixed>>}>
     *
     * @spec openspec/specs/appointment-booking/spec.md
     */
    public function getEligibleResourcesPerStep(string $serviceId): array
    {
        if ($serviceId === '') {
            return [];
        }

        $service = $this->loadService(serviceId: $serviceId);
        if ($service === null) {
            return [];
        }

        $resources = $this->loadResources();
        $steps     = $this->normaliseSteps(
            multiStep: ($service['multiStep'] ?? []),
            fallbackSkills: $this->normaliseSkillList(value: ($service['requiredSkills'] ?? [])),
            fallbackDuration: (int) ($service['durationMinutes'] ?? 0)
        );

        $out = [];
        foreach ($steps as $index => $step) {
            $required = [];
            if ($step['skillRequired'] !== '') {
                $required = [$step['skillRequired']];
            }

            $eligibleForStep = [];
            if ($step['allowGap'] === false) {
                foreach ($resources as $resource) {
                    if ($this->matchesSkillRequirements(resource: $resource, requiredSkills: $required) === true) {
                        $eligibleForStep[] = $resource;
                    }
                }
            }

            $out[] = [
                'stepIndex'         => $index,
                'durationMinutes'   => $step['durationMinutes'],
                'skillRequired'     => $step['skillRequired'],
                'allowGap'          => $step['allowGap'],
                'eligibleResources' => $eligibleForStep,
            ];
        }//end foreach

        return $out;
    }//end getEligibleResourcesPerStep()

    /**
     * Return Resources eligible AND available for a Service on a given date/duration.
     *
     * Composes {@see getEligibleResources()} with member-02 availability so the
     * caller (member 04 BookingService, member 06 portal) receives only the
     * subset that can actually be booked. A resource with zero free slots is
     * excluded even when skill-qualified.
     *
     * @param string $serviceId       Service UUID/slug.
     * @param string $date            ISO date `YYYY-MM-DD`.
     * @param int    $durationMinutes Service duration in minutes.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/appointment-booking/spec.md
     */
    public function getEligibleResourcesForSlot(string $serviceId, string $date, int $durationMinutes): array
    {
        if ($serviceId === '' || $date === '' || $durationMinutes <= 0) {
            return [];
        }

        $eligible = $this->getEligibleResources(serviceId: $serviceId);
        if ($eligible === []) {
            return [];
        }

        $withSlots = [];
        foreach ($eligible as $resource) {
            $resourceId = $this->idOf(object: $resource);
            if ($resourceId === '') {
                continue;
            }

            $slots = $this->availabilityService->computeAvailability(
                resourceId: $resourceId,
                date: $date,
                serviceDurationMinutes: $durationMinutes
            );
            if ($slots === []) {
                continue;
            }

            $withSlots[] = $resource;
        }

        return $withSlots;
    }//end getEligibleResourcesForSlot()

    /**
     * Skill-match primitive — the booking domain's single ADR-012 seam.
     *
     * Empty `$requiredSkills` is satisfied by every Resource, INCLUDING a
     * resource with no `skills` array. Non-empty `$requiredSkills` is
     * satisfied only when the resource's `skills` covers EVERY required skill
     * (subset-of semantics).
     *
     * Public so member 04 (BookingService) can reuse the primitive without
     * re-entering the OR lookup path.
     *
     * @param array<string, mixed> $resource       The resource entity.
     * @param array<int, string>   $requiredSkills Required skill slugs.
     *
     * @return bool
     *
     * @spec openspec/specs/appointment-booking/spec.md
     */
    public function matchesSkillRequirements(array $resource, array $requiredSkills): bool
    {
        if ($requiredSkills === []) {
            return true;
        }

        $resourceSkills = $this->normaliseSkillList(value: ($resource['skills'] ?? []));
        if ($resourceSkills === []) {
            return false;
        }

        foreach ($requiredSkills as $skill) {
            if (in_array($skill, $resourceSkills, true) === false) {
                return false;
            }
        }

        return true;
    }//end matchesSkillRequirements()

    /**
     * Load a Service entity by id/slug.
     *
     * @param string $serviceId Service UUID/slug.
     *
     * @return array<string, mixed>|null
     */
    private function loadService(string $serviceId): ?array
    {
        $register = $this->registerId();
        $schema   = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $result = $this->getObjectService()->find(
                id: $serviceId,
                register: $register,
                schema: $schema
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: service load failed for eligibility',
                ['service' => $serviceId]
            );
            return null;
        }

        return $this->toArray(object: $result);
    }//end loadService()

    /**
     * Load every Resource entity for the configured register/schema.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadResources(): array
    {
        $register = $this->registerId();
        $schema   = $this->schemaId(key: self::RESOURCE_SCHEMA_KEY);
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: resource list failed for eligibility');
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $resource = $this->toArray(object: $row);
            if ($resource !== null) {
                $out[] = $resource;
            }
        }

        return $out;
    }//end loadResources()

    /**
     * Normalise a multiStep payload into a uniform list.
     *
     * Empty `$multiStep` produces a single synthetic step using the Service's
     * top-level `requiredSkills` (joined into one synthetic step that requires
     * the full skill set; we collapse to one skill key for the step view by
     * stringifying the first required skill — callers that need the full set
     * should call {@see getEligibleResources()} instead).
     *
     * @param mixed              $multiStep        The raw `multiStep` value.
     * @param array<int, string> $fallbackSkills   Service-level required skills (for the synthetic step).
     * @param int                $fallbackDuration Service duration to use for the synthetic step.
     *
     * @return array<int, array{durationMinutes: int, skillRequired: string, resourceType: string, allowGap: bool}>
     */
    private function normaliseSteps(mixed $multiStep, array $fallbackSkills, int $fallbackDuration): array
    {
        if (is_array($multiStep) === true && $multiStep !== []) {
            $out = [];
            foreach ($multiStep as $step) {
                if (is_array($step) === false) {
                    continue;
                }

                $out[] = [
                    'durationMinutes' => max(0, (int) ($step['durationMinutes'] ?? 0)),
                    'skillRequired'   => (string) ($step['skillRequired'] ?? ''),
                    'resourceType'    => (string) ($step['resourceType'] ?? ''),
                    'allowGap'        => (bool) ($step['allowGap'] ?? false),
                ];
            }

            return $out;
        }

        $skill = '';
        if ($fallbackSkills !== []) {
            $skill = (string) $fallbackSkills[0];
        }

        return [
            [
                'durationMinutes' => max(0, $fallbackDuration),
                'skillRequired'   => $skill,
                'resourceType'    => '',
                'allowGap'        => false,
            ],
        ];
    }//end normaliseSteps()

    /**
     * Coerce a raw `skills` / `requiredSkills` value into a list of strings.
     *
     * Non-array values and non-scalar entries are silently dropped.
     *
     * @param mixed $value Raw value (expected: list<string>).
     *
     * @return array<int, string>
     */
    private function normaliseSkillList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) === false) {
                continue;
            }

            $trimmed = trim($entry);
            if ($trimmed === '') {
                continue;
            }

            $out[] = $trimmed;
        }

        return $out;
    }//end normaliseSkillList()

    /**
     * Pull the canonical id out of a normalised object.
     *
     * @param array<string, mixed> $object Object data.
     *
     * @return string
     */
    private function idOf(array $object): string
    {
        if (isset($object['@self']) === true && is_array($object['@self']) === true) {
            $self = $object['@self'];
            if (isset($self['id']) === true) {
                return (string) $self['id'];
            }

            if (isset($self['uuid']) === true) {
                return (string) $self['uuid'];
            }
        }

        if (isset($object['id']) === true) {
            return (string) $object['id'];
        }

        if (isset($object['uuid']) === true) {
            return (string) $object['uuid'];
        }

        return '';
    }//end idOf()

    /**
     * Normalise an OpenRegister entity (or array) to a plain array.
     *
     * @param mixed $object Entity, array, or null.
     *
     * @return array<string, mixed>|null
     */
    private function toArray(mixed $object): ?array
    {
        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $serialised = $object->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($object, 'toArray') === true) {
                $arr = $object->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $object;
        }

        return null;
    }//end toArray()

    /**
     * Resolve the pipelinq register id from app config.
     *
     * Fails closed: '' means "unconfigured", and every caller refuses the
     * OpenRegister call on it. An empty register must never be handed to
     * OpenRegister — ObjectService skips setRegister() for an empty value, so
     * the query silently inherits whatever register context an earlier call in
     * the same request left on the shared service instance. The empty case is
     * logged so an unprovisioned instance is visible rather than silent.
     *
     * @return string The configured register id, or '' when unconfigured.
     */
    private function registerId(): string
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($registerId === '') {
            $this->logger->warning(
                'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
            );
        }

        return $registerId;
    }//end registerId()

    /**
     * Resolve a schema id by app-config key.
     *
     * @param string $key App-config key, e.g. `service_schema`.
     *
     * @return string
     */
    private function schemaId(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end schemaId()

    /**
     * Resolve the OpenRegister ObjectService via the DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
