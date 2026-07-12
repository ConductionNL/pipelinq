<?php

/**
 * Unit tests for the pipelinq register lifecycle + notification annotations.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-or-lifecycle-notification/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the schema lifecycle and notification annotations are well-formed.
 *
 * The decisive invariant (mirroring the OpenRegister
 * AnnotationNotificationDispatcher, which matches a transition trigger's
 * `action` against `ObjectTransitionedEvent::getAction()`, i.e. the lifecycle
 * transition NAME) is that every `x-openregister-notifications` transition
 * trigger's `action` key resolves to a declared transition NAME on the same
 * schema — never to a destination state name.
 */
class RegisterAnnotationsTest extends TestCase
{

    /**
     * Schemas keyed by name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $schemas;

    /**
     * Absolute path to the register definition under test.
     *
     * @return string The register JSON path.
     */
    private static function registerPath(): string
    {
        return dirname(__DIR__, 3).'/lib/Settings/pipelinq_register.json';
    }//end registerPath()

    /**
     * Decode the register JSON to an array.
     *
     * @return array<string, mixed> The decoded register.
     */
    private static function loadRegister(): array
    {
        $decoded = json_decode((string) file_get_contents(self::registerPath()), true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end loadRegister()

    /**
     * Load and decode the register JSON once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->assertFileExists(filename: self::registerPath(), message: 'Register JSON must exist.');

        $decoded = self::loadRegister();
        $this->assertNotEmpty(actual: $decoded, message: 'Register JSON must decode to a non-empty array.');

        $this->schemas = ($decoded['components']['schemas'] ?? []);
        $this->assertNotEmpty(actual: $this->schemas, message: 'Register must declare schemas.');
    }//end setUp()

    /**
     * Read an `x-openregister-*` annotation from either the schema root or its
     * `configuration` block (OpenRegister folds root-level annotations into
     * `configuration` during hydration; both placements are valid).
     *
     * @param array<string, mixed> $schema The schema definition.
     * @param string               $key    The annotation key.
     *
     * @return array<string, mixed>|null The annotation, or null when absent.
     */
    private static function annotation(array $schema, string $key): ?array
    {
        $value = ($schema['configuration'][$key] ?? $schema[$key] ?? null);
        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end annotation()

    /**
     * Collect every schema that declares a lifecycle annotation.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function lifecycleSchemaProvider(): array
    {
        $schemas = (self::loadRegister()['components']['schemas'] ?? []);

        $cases = [];
        foreach ($schemas as $name => $schema) {
            if (self::annotation(schema: $schema, key: 'x-openregister-lifecycle') !== null) {
                $cases[$name] = [$name, $schema];
            }
        }

        return $cases;
    }//end lifecycleSchemaProvider()

    /**
     * Each lifecycle annotation must be structurally well-formed.
     *
     * @param string               $name   The schema name.
     * @param array<string, mixed> $schema The schema definition.
     *
     * @dataProvider lifecycleSchemaProvider
     *
     * @return void
     */
    public function testLifecycleAnnotationIsWellFormed(string $name, array $schema): void
    {
        $lifecycle = self::annotation(schema: $schema, key: 'x-openregister-lifecycle');
        $this->assertIsArray(actual: $lifecycle, message: "Schema {$name} lifecycle must be an array.");

        $this->assertArrayHasKey(key: 'field', array: $lifecycle, message: "Schema {$name} lifecycle needs a field.");
        $this->assertArrayHasKey(key: 'initial', array: $lifecycle, message: "Schema {$name} lifecycle needs an initial.");
        $this->assertArrayHasKey(key: 'transitions', array: $lifecycle, message: "Schema {$name} lifecycle needs transitions.");

        $field = (string) $lifecycle['field'];
        $this->assertNotSame(expected: '', actual: $field, message: "Schema {$name} lifecycle field must be non-empty.");

        // The lifecycle field must be a declared property with an enum that
        // contains the initial state and every transition's source/target.
        $property = ($schema['properties'][$field] ?? null);
        $this->assertIsArray(actual: $property, message: "Schema {$name} must declare lifecycle field '{$field}'.");

        $enum = ($property['enum'] ?? []);
        $this->assertIsArray(actual: $enum, message: "Schema {$name} field '{$field}' must declare an enum.");

        $this->assertContains(
            needle: (string) $lifecycle['initial'],
            haystack: $enum,
            message: "Schema {$name} initial state must be a valid '{$field}' enum value."
        );

        $transitions = $lifecycle['transitions'];
        $this->assertIsArray(actual: $transitions, message: "Schema {$name} transitions must be an array.");
        $this->assertNotEmpty(actual: $transitions, message: "Schema {$name} must declare at least one transition.");

        foreach ($transitions as $transitionName => $spec) {
            $this->assertIsString(actual: $transitionName, message: "Schema {$name} transition name must be a string.");
            $this->assertIsArray(actual: $spec, message: "Schema {$name} transition '{$transitionName}' must be an array.");
            $this->assertArrayHasKey(key: 'from', array: $spec, message: "Transition {$name}.{$transitionName} needs 'from'.");
            $this->assertArrayHasKey(key: 'to', array: $spec, message: "Transition {$name}.{$transitionName} needs 'to'.");

            $from = $spec['from'];
            $this->assertIsArray(actual: $from, message: "Transition {$name}.{$transitionName} 'from' must be an array.");
            foreach ($from as $state) {
                $this->assertContains(
                    needle: (string) $state,
                    haystack: $enum,
                    message: "Transition {$name}.{$transitionName} 'from' state '{$state}' must be valid."
                );
            }//end foreach

            $this->assertContains(
                needle: (string) $spec['to'],
                haystack: $enum,
                message: "Transition {$name}.{$transitionName} 'to' state must be a valid enum value."
            );

            // Task 2.5: each transition documents its rule + authorization.
            $this->assertArrayHasKey(key: 'description', array: $spec, message: "Transition {$name}.{$transitionName} needs a description.");

            $description = (string) $spec['description'];
            $this->assertNotSame(expected: '', actual: $description, message: "Transition {$name}.{$transitionName} description required.");
        }//end foreach
    }//end testLifecycleAnnotationIsWellFormed()

    /**
     * Every notification transition trigger's `action` must equal a declared
     * lifecycle transition NAME on the same schema — and must NOT be a
     * destination state name. This is the load-bearing dispatcher invariant.
     *
     * @return void
     */
    public function testNotificationActionKeysResolveToTransitionNames(): void
    {
        $checked = 0;

        foreach ($this->schemas as $name => $schema) {
            $notifications = self::annotation(schema: $schema, key: 'x-openregister-notifications');
            if ($notifications === null) {
                continue;
            }

            $lifecycle       = self::annotation(schema: $schema, key: 'x-openregister-lifecycle');
            $transitions     = (array) ($lifecycle['transitions'] ?? []);
            $transitionNames = array_keys($transitions);

            $destinationStates = [];
            foreach ($transitions as $spec) {
                if (isset($spec['to']) === true) {
                    $destinationStates[] = (string) $spec['to'];
                }
            }

            foreach ($notifications as $ruleName => $rule) {
                $this->assertIsArray(actual: $rule, message: "Notification {$name}.{$ruleName} must be an array.");
                $this->assertArrayHasKey(key: 'trigger', array: $rule, message: "Notification {$name}.{$ruleName} needs a trigger.");
                $this->assertArrayHasKey(key: 'channels', array: $rule, message: "Notification {$name}.{$ruleName} needs channels.");
                $this->assertArrayHasKey(key: 'subject', array: $rule, message: "Notification {$name}.{$ruleName} needs a subject.");

                // Subject must carry nl + en (i18n requirement).
                $this->assertArrayHasKey(key: 'nl', array: $rule['subject'], message: "Notification {$name}.{$ruleName} subject needs nl.");
                $this->assertArrayHasKey(key: 'en', array: $rule['subject'], message: "Notification {$name}.{$ruleName} subject needs en.");

                $trigger = $rule['trigger'];
                $this->assertIsArray(actual: $trigger, message: "Notification {$name}.{$ruleName} trigger must be an array.");

                if (($trigger['type'] ?? '') !== 'transition') {
                    continue;
                }

                $this->assertArrayHasKey(key: 'action', array: $trigger, message: "Transition notification {$name}.{$ruleName} needs an action.");
                $action = (string) $trigger['action'];

                $this->assertContains(
                    needle: $action,
                    haystack: $transitionNames,
                    message: "Notification {$name}.{$ruleName} action '{$action}' must be a declared transition NAME."
                );

                $this->assertNotContains(
                    needle: $action,
                    haystack: $destinationStates,
                    message: "Notification {$name}.{$ruleName} action '{$action}' must be a transition NAME, not a state."
                );

                $checked++;
            }//end foreach
        }//end foreach

        $this->assertGreaterThan(expected: 0, actual: $checked, message: 'Expected at least one transition notification.');
    }//end testNotificationActionKeysResolveToTransitionNames()

    /**
     * Operator vocabulary recognised by the OpenRegister v1 calculation
     * evaluator (mirrors CalculationAnnotationValidator::VALID_OPS).
     *
     * @var array<int, string>
     */
    private const VALID_CALC_OPS = [
        'prop',
        'lit',
        'concat',
        'if',
        'not',
        'and',
        'or',
        '+',
        '-',
        '*',
        '/',
        '%',
        'eq',
        'ne',
        'lt',
        'lte',
        'gt',
        'gte',
        'now',
        'diffDays',
        'formatDate',
        'dateDiff',
        'dateAdd',
        'sequence',
        'max',
        'min',
        'coalesce',
        'abs',
        'round',
        'year',
        'monthsElapsed',
        'sha256',
    ];

    /**
     * Allowed `type` values for a calculation declaration.
     *
     * @var array<int, string>
     */
    private const VALID_CALC_TYPES = ['string', 'integer', 'number', 'boolean', 'date'];

    /**
     * `@self.*` system fields the OpenRegister evaluator injects at read time.
     *
     * @var array<int, string>
     */
    private const SELF_FIELDS = ['id', 'uuid', 'register', 'schema', 'owner', 'created', 'updated'];

    /**
     * Every `x-openregister-calculations` block must be well-formed: each
     * calculation declares a valid `type` + `expression`, every operator is in
     * the v1 vocabulary, and every `prop` reference resolves to a declared
     * property, a sibling calculation, or a known `@self.*` system field.
     *
     * Mirrors OpenRegister's CalculationAnnotationValidator so a malformed
     * annotation fails here before it ever reaches a schema save.
     *
     * @return void
     */
    public function testCalculationAnnotationsAreWellFormed(): void
    {
        $checked = 0;

        foreach ($this->schemas as $name => $schema) {
            $calcs = self::annotation(schema: $schema, key: 'x-openregister-calculations');
            if ($calcs === null) {
                continue;
            }

            $this->assertNotEmpty(actual: $calcs, message: "Schema {$name} calculations must not be empty.");

            $propKeys  = array_keys(($schema['properties'] ?? []));
            $calcNames = array_keys($calcs);
            $allRefs   = array_merge($propKeys, $calcNames);

            foreach ($calcs as $calcName => $spec) {
                $this->assertIsString(actual: $calcName, message: "Schema {$name} calculation name must be a string.");
                $this->assertIsArray(actual: $spec, message: "Schema {$name}.{$calcName} must be an object.");

                $type = (string) ($spec['type'] ?? '');
                $this->assertContains(
                    needle: $type,
                    haystack: self::VALID_CALC_TYPES,
                    message: "Schema {$name}.{$calcName} type '{$type}' must be a valid calculation type."
                );

                if (array_key_exists('materialise', $spec) === true) {
                    $this->assertIsBool(actual: $spec['materialise'], message: "Schema {$name}.{$calcName} materialise must be boolean.");
                }

                $this->assertArrayHasKey(key: 'expression', array: $spec, message: "Schema {$name}.{$calcName} needs an expression.");
                $this->assertExpressionWellFormed(
                    expr: $spec['expression'],
                    where: "{$name}.{$calcName}",
                    allRefs: $allRefs
                );

                $checked++;
            }//end foreach
        }//end foreach

        $this->assertGreaterThan(expected: 0, actual: $checked, message: 'Expected at least one calculation annotation.');
    }//end testCalculationAnnotationsAreWellFormed()

    /**
     * Recursively assert a calculation expression uses only known operators and
     * that every `prop` reference resolves.
     *
     * @param mixed              $expr    The expression node.
     * @param string             $where   Schema.calc label for messages.
     * @param array<int, string> $allRefs Valid property + sibling-calc names.
     *
     * @return void
     */
    private function assertExpressionWellFormed(mixed $expr, string $where, array $allRefs): void
    {
        if (is_array($expr) === false) {
            // Bare scalar literal — always valid.
            return;
        }

        $this->assertCount(expectedCount: 1, haystack: $expr, message: "Expression in {$where} must be single-key.");

        $op = (string) array_key_first($expr);
        $this->assertContains(
            needle: $op,
            haystack: self::VALID_CALC_OPS,
            message: "Expression in {$where} uses unknown operator '{$op}'."
        );

        $args = $expr[$op];

        if ($op === 'prop') {
            $ref = (string) $args;
            if (is_array($args) === true) {
                $ref = (string) ($args[0] ?? '');
            }

            $this->assertNotSame(expected: '', actual: $ref, message: "prop in {$where} must name a reference.");

            if (str_starts_with($ref, '@self.') === true) {
                $sysField = substr($ref, 6);
                $this->assertContains(
                    needle: $sysField,
                    haystack: self::SELF_FIELDS,
                    message: "@self.{$sysField} in {$where} is not a known system field."
                );

                return;
            }

            $this->assertContains(
                needle: $ref,
                haystack: $allRefs,
                message: "prop '{$ref}' in {$where} is not a declared property or sibling calculation."
            );

            return;
        }//end if

        if ($op === 'dateDiff') {
            $this->assertIsArray(actual: $args, message: "dateDiff in {$where} needs a keyed argument object.");
            foreach (['from', 'to', 'unit'] as $key) {
                $this->assertArrayHasKey(key: $key, array: $args, message: "dateDiff in {$where} requires '{$key}'.");
            }

            $this->assertExpressionWellFormed(expr: $args['from'], where: $where, allRefs: $allRefs);
            $this->assertExpressionWellFormed(expr: $args['to'], where: $where, allRefs: $allRefs);
            return;
        }

        if (is_array($args) === false) {
            $this->assertExpressionWellFormed(expr: $args, where: $where, allRefs: $allRefs);
            return;
        }

        foreach ($args as $sub) {
            $this->assertExpressionWellFormed(expr: $sub, where: $where, allRefs: $allRefs);
        }
    }//end assertExpressionWellFormed()

    /**
     * Every `x-openregister-archival` block must be well-formed: a `retention`
     * object with a parseable ISO-8601 `default` duration and, where present,
     * an array of rules each carrying a non-empty `condition`, a parseable
     * `retention` duration, and (optionally) a string `reason`.
     *
     * Mirrors OpenRegister's ArchivalAnnotationValidator.
     *
     * @return void
     */
    public function testArchivalAnnotationsAreWellFormed(): void
    {
        $checked = 0;

        foreach ($this->schemas as $name => $schema) {
            $archival = self::annotation(schema: $schema, key: 'x-openregister-archival');
            if ($archival === null) {
                continue;
            }

            $this->assertArrayHasKey(key: 'retention', array: $archival, message: "Schema {$name} archival needs a retention block.");
            $retention = $archival['retention'];
            $this->assertIsArray(actual: $retention, message: "Schema {$name} retention must be an object.");

            $this->assertArrayHasKey(key: 'default', array: $retention, message: "Schema {$name} retention needs a default duration.");
            $this->assertTrue(
                condition: self::isIsoDuration(value: (string) $retention['default']),
                message: "Schema {$name} retention.default '{$retention['default']}' must be a valid duration."
            );

            foreach ((array) ($retention['rules'] ?? []) as $index => $rule) {
                $label = "Schema {$name} archival rule {$index}";
                $this->assertIsArray(actual: $rule, message: "{$label} must be an object.");

                $this->assertArrayHasKey(key: 'condition', array: $rule, message: "{$label} needs a condition.");
                $this->assertIsString(actual: $rule['condition'], message: "{$label} condition must be a string.");
                $this->assertNotSame(expected: '', actual: trim((string) $rule['condition']), message: "{$label} condition must not be empty.");

                $this->assertArrayHasKey(key: 'retention', array: $rule, message: "{$label} needs a retention duration.");
                $this->assertTrue(
                    condition: self::isIsoDuration(value: (string) $rule['retention']),
                    message: "{$label} retention '{$rule['retention']}' must be a valid duration."
                );

                if (array_key_exists('reason', $rule) === true) {
                    $this->assertIsString(actual: $rule['reason'], message: "{$label} reason must be a string.");
                }
            }//end foreach

            $checked++;
        }//end foreach

        $this->assertGreaterThan(expected: 0, actual: $checked, message: 'Expected at least one archival annotation.');
    }//end testArchivalAnnotationsAreWellFormed()

    /**
     * Return true when the value parses as an ISO-8601 duration via DateInterval.
     *
     * @param string $value Candidate duration string.
     *
     * @return bool True when parseable.
     */
    private static function isIsoDuration(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        try {
            new \DateInterval($value);
            return true;
        } catch (\Exception $unused) {
            return false;
        }
    }//end isIsoDuration()
}//end class
