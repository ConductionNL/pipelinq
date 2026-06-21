<?php

/**
 * Pipelinq SchemaLifecycleGraph.
 *
 * Reads a schema's declarative `x-openregister-lifecycle` transition map from the
 * bundled register JSON (the schema source of truth shipped with the app) and
 * returns it as a normalised `from => [to, ...]` adjacency map. This makes the
 * schema declaration the single source of truth for the transition graph (ADR-031),
 * so services no longer hardcode a divergence-prone PHP copy.
 *
 * The grammar handled here matches OpenRegister's `LifecycleAnnotationValidator`:
 * `transitions` may be a keyed map (`action => {from, to}`) or an array of
 * `{from, to}` objects; `from` may be a single state string or a list of states.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-lifecycle-batch-a/specs/openregister-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Lifecycle;

/**
 * Resolve declarative lifecycle transition graphs from the bundled register JSON.
 *
 * Pure file-read + json_decode — no OpenRegister runtime dependency, so it works
 * in unit tests without a container. On any read/parse failure it returns an empty
 * map and the caller falls back to its prior hardcoded graph (never regresses).
 *
 * @spec openspec/changes/pipelinq-lifecycle-batch-a/specs/openregister-integration/spec.md
 */
final class SchemaLifecycleGraph
{

    /**
     * Absolute path to the bundled main register JSON.
     *
     * @var string
     */
    private string $mainRegisterPath;

    /**
     * Absolute path to the bundled register.d fragment directory.
     *
     * @var string
     */
    private string $fragmentDir;

    /**
     * Constructor.
     *
     * @param string|null $settingsDir Absolute path to `lib/Settings` (defaults to the
     *                                 directory shipped two levels up from this file).
     */
    public function __construct(?string $settingsDir=null)
    {
        $dir = $settingsDir;
        if ($dir === null) {
            $dir = dirname(__DIR__, 2).'/Settings';
        }

        $this->mainRegisterPath = $dir.'/pipelinq_register.json';
        $this->fragmentDir      = $dir.'/register.d';
    }//end __construct()

    /**
     * Resolve the `from => [to, ...]` adjacency map for a schema by slug.
     *
     * Scans the main register first, then the register.d fragments, for a schema
     * whose slug matches and that declares `configuration.x-openregister-lifecycle`.
     *
     * @param string $schemaSlug The schema slug (e.g. 'walkInTicket').
     *
     * @return array<string, array<int, string>> Adjacency map; empty when undeclared/unreadable.
     */
    public function adjacencyFor(string $schemaSlug): array
    {
        $lifecycle = $this->lifecycleFor(schemaSlug: $schemaSlug);
        if ($lifecycle === null) {
            return [];
        }

        return $this->normaliseTransitions(lifecycle: $lifecycle);
    }//end adjacencyFor()

    /**
     * Resolve the adjacency map seeded with EVERY declared state as a key.
     *
     * Identical to {@see adjacencyFor()} but guarantees one key per state in the
     * lifecycle field's `enum` (terminal states map to an empty `[]`). This lets a
     * guard distinguish an *unknown* status (absent key) from a *terminal* status
     * (present key, no outgoing transitions) — the contract the walk-in queue
     * service relies on for its two distinct error messages.
     *
     * @param string $schemaSlug The schema slug.
     *
     * @return array<string, array<int, string>> Adjacency map keyed by every declared state.
     */
    public function fullAdjacencyFor(string $schemaSlug): array
    {
        $lifecycle = $this->lifecycleFor(schemaSlug: $schemaSlug);
        if ($lifecycle === null) {
            return [];
        }

        $graph = $this->normaliseTransitions(lifecycle: $lifecycle);
        $field = (string) ($lifecycle['field'] ?? ($lifecycle['property'] ?? ''));

        $states = $this->statesFor(schemaSlug: $schemaSlug, field: $field);
        foreach ($states as $state) {
            if (isset($graph[$state]) === false) {
                $graph[$state] = [];
            }
        }

        return $graph;
    }//end fullAdjacencyFor()

    /**
     * Resolve the declared enum of states for a schema's lifecycle field.
     *
     * @param string $schemaSlug The schema slug.
     * @param string $field      The lifecycle field name.
     *
     * @return array<int, string> The enum values (empty when unresolvable).
     */
    private function statesFor(string $schemaSlug, string $field): array
    {
        if ($field === '') {
            return [];
        }

        $files = [$this->mainRegisterPath];
        if (is_dir($this->fragmentDir) === true) {
            $glob = glob($this->fragmentDir.'/*.json');
            if (is_array($glob) === true) {
                sort($glob);
                $files = array_merge($files, $glob);
            }
        }

        foreach ($files as $file) {
            if (is_file($file) === false || is_readable($file) === false) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded) === false) {
                continue;
            }

            $schemas = ($decoded['components']['schemas'] ?? []);
            if (is_array($schemas) === false) {
                continue;
            }

            foreach ($schemas as $key => $schema) {
                if (is_array($schema) === false) {
                    continue;
                }

                $slug = (string) ($schema['slug'] ?? $key);
                if ($slug !== $schemaSlug) {
                    continue;
                }

                $enum = ($schema['properties'][$field]['enum'] ?? []);
                if (is_array($enum) === false) {
                    return [];
                }

                return array_map(static fn ($value): string => (string) $value, $enum);
            }
        }//end foreach

        return [];
    }//end statesFor()

    /**
     * Resolve an arbitrary `configuration.<key>` annotation for a schema slug.
     *
     * Used for non-OR-enforced, app-namespaced lifecycle annotations (e.g. the
     * `x-pipelinq-forecast-lifecycle` second state machine on the lead schema,
     * which OpenRegister cannot enforce because it already owns the `status`
     * lifecycle field). Same safe file-scan + json_decode contract as
     * {@see lifecycleFor()}: returns null when undeclared/unreadable so callers
     * fall back to their prior hardcoded constants.
     *
     * @param string $schemaSlug The schema slug.
     * @param string $key        The configuration key (e.g. 'x-pipelinq-forecast-lifecycle').
     *
     * @return array<string, mixed>|null The annotation, or null when not found.
     */
    public function configurationFor(string $schemaSlug, string $key): ?array
    {
        $files = [$this->mainRegisterPath];
        if (is_dir($this->fragmentDir) === true) {
            $glob = glob($this->fragmentDir.'/*.json');
            if (is_array($glob) === true) {
                sort($glob);
                $files = array_merge($files, $glob);
            }
        }

        foreach ($files as $file) {
            if (is_file($file) === false || is_readable($file) === false) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded) === false) {
                continue;
            }

            $schemas = ($decoded['components']['schemas'] ?? []);
            if (is_array($schemas) === false) {
                continue;
            }

            foreach ($schemas as $schemaKey => $schema) {
                if (is_array($schema) === false) {
                    continue;
                }

                $slug = (string) ($schema['slug'] ?? $schemaKey);
                if ($slug !== $schemaSlug) {
                    continue;
                }

                $config = ($schema['configuration'] ?? []);
                if (is_array($config) === false) {
                    continue;
                }

                $annotation = ($config[$key] ?? null);
                if (is_array($annotation) === true) {
                    return $annotation;
                }
            }
        }//end foreach

        return null;
    }//end configurationFor()

    /**
     * Resolve the raw `x-openregister-lifecycle` annotation for a schema slug.
     *
     * @param string $schemaSlug The schema slug.
     *
     * @return array<string, mixed>|null The annotation, or null when not found.
     */
    public function lifecycleFor(string $schemaSlug): ?array
    {
        $files = [$this->mainRegisterPath];
        if (is_dir($this->fragmentDir) === true) {
            $glob = glob($this->fragmentDir.'/*.json');
            if (is_array($glob) === true) {
                sort($glob);
                $files = array_merge($files, $glob);
            }
        }

        foreach ($files as $file) {
            $lifecycle = $this->lifecycleFromFile(file: $file, schemaSlug: $schemaSlug);
            if ($lifecycle !== null) {
                return $lifecycle;
            }
        }

        return null;
    }//end lifecycleFor()

    /**
     * Extract the lifecycle annotation for a schema slug from one register file.
     *
     * @param string $file       Absolute path to a register JSON file.
     * @param string $schemaSlug The schema slug to look up.
     *
     * @return array<string, mixed>|null The annotation, or null when absent.
     */
    private function lifecycleFromFile(string $file, string $schemaSlug): ?array
    {
        if (is_file($file) === false || is_readable($file) === false) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        $schemas = ($decoded['components']['schemas'] ?? []);
        if (is_array($schemas) === false) {
            return null;
        }

        foreach ($schemas as $key => $schema) {
            if (is_array($schema) === false) {
                continue;
            }

            $slug = (string) ($schema['slug'] ?? $key);
            if ($slug !== $schemaSlug) {
                continue;
            }

            $config = ($schema['configuration'] ?? []);
            if (is_array($config) === false) {
                return null;
            }

            $lifecycle = ($config['x-openregister-lifecycle'] ?? null);
            if (is_array($lifecycle) === true) {
                return $lifecycle;
            }

            return null;
        }//end foreach

        return null;
    }//end lifecycleFromFile()

    /**
     * Normalise a lifecycle annotation's `transitions` into a `from => [to,...]` map.
     *
     * Handles both the keyed-map (`action => {from, to}`) and array-of-objects shapes,
     * and a `from` that is either a single state string or a list of states.
     *
     * @param array<string, mixed> $lifecycle The `x-openregister-lifecycle` annotation.
     *
     * @return array<string, array<int, string>> Adjacency map.
     */
    private function normaliseTransitions(array $lifecycle): array
    {
        $transitions = ($lifecycle['transitions'] ?? []);
        if (is_array($transitions) === false) {
            return [];
        }

        $graph = [];
        foreach ($transitions as $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $to = ($spec['to'] ?? null);
            if (is_string($to) === false || $to === '') {
                continue;
            }

            $from = ($spec['from'] ?? []);
            if (is_string($from) === true) {
                $from = [$from];
            }

            if (is_array($from) === false) {
                continue;
            }

            foreach ($from as $fromState) {
                $state = (string) $fromState;
                if (isset($graph[$state]) === false) {
                    $graph[$state] = [];
                }

                if (in_array($to, $graph[$state], true) === false) {
                    $graph[$state][] = $to;
                }
            }
        }//end foreach

        return $graph;
    }//end normaliseTransitions()
}//end class
