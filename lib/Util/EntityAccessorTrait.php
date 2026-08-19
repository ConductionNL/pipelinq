<?php

/**
 * Pipelinq EntityAccessorTrait.
 *
 * Reads a scalar value off an OpenRegister entity through an accessor that the
 * class does not declare, because Nextcloud's `OCP\AppFramework\Db\Entity`
 * serves it through `__call()`.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use Throwable;

/**
 * Safe reader for OpenRegister entity accessors served by `Entity::__call()`.
 *
 * Measured against a live Nextcloud 34 with the real
 * `OCA\OpenRegister\Db\ObjectEntity` (`extends OCP\AppFramework\Db\Entity`):
 *
 * | accessor          | method_exists | is_callable | property_exists | direct call              |
 * |-------------------|---------------|-------------|-----------------|--------------------------|
 * | getObject()       | true          | true        | true            | ok (concrete control)    |
 * | jsonSerialize()   | true          | true        | true            | ok (concrete control)    |
 * | getSchema()       | **false**     | true        | true            | ok                       |
 * | getUuid()         | **false**     | true        | true            | ok                       |
 * | getId()           | **false**     | true        | true            | ok                       |
 * | getNoSuchThing()  | false         | **true**    | false           | BadFunctionCallException |
 *
 * Two conclusions the table forces, and both are why this trait exists:
 *
 * 1. `method_exists()` is FALSE for every accessor declared only as an
 *    `@method` docblock tag on `ObjectEntity` (getUuid, getSchema, getId, …).
 *    A guard written as `if (method_exists($entity, 'getSchema') === false)
 *    { return false; }` is therefore permanently taken, and the feature behind
 *    it is silently off. The concrete controls in the table prove the probe
 *    itself works — it is the accessor that is magic, not the function.
 * 2. `is_callable()` is NOT the remedy: it is TRUE for a name the entity has
 *    never heard of, so swapping the probe converts an always-false guard into
 *    an always-true one and the call then raises `BadFunctionCallException`.
 *    `Entity::getter()` itself decides with `property_exists()`, and that is
 *    the only probe in the table that separates a real accessor from a
 *    fabricated one.
 *
 * Every caller here wants a VALUE, not a membership decision, so this trait
 * uses the value-read shape: call the accessor and treat a throw (or a
 * non-scalar result) as "absent". `property_exists()` is the right instrument
 * only for the decision form ("does this entity carry this column at all?").
 *
 * ⚠️ The value is whatever OpenRegister STAMPED, which is the schema's numeric
 * id — so a caller must compare it against an id, not a slug. In pipelinq that
 * holds by construction: `SettingsMapBuilder::addSchemaToMap()` stores
 * `$schema['id']` keyed by slug, so every `<slug>_schema` app-config value and
 * the whole `SchemaMapService` map are numeric. An app whose loader stores the
 * SLUG instead needs `SchemaMapper::find($raw)->getSlug()` on top of this read
 * — and `getSlug()` is magic too. Pinned by
 * `PosTransactionCompletedListenerTest::testTheGuardMatchesOnTheNumericSchemaIdOpenRegisterStamps`.
 *
 * @see shillinq/lib/Service/ListenerSchemaResolver.php readAccessor() and
 *      openbuild/lib/Service/ObjectSchemaSlugResolver.php:163 — the two in-tree
 *      VALUE-read exemplars this shape is copied from. (For the DECISION form
 *      the exemplar is openregister/lib/Db/MultiTenancyTrait.php:647 and :684,
 *      the only in-tree `property_exists` use.)
 *
 * @spec exclude Infrastructure helper for OpenRegister's magic accessors; it carries
 *       no product requirement of its own and only restores the behaviour the specs
 *       behind its callers already mandate. Pinned by
 *       tests/Unit/Util/EntityAccessorTraitTest.php.
 */
trait EntityAccessorTrait
{
    /**
     * Read a scalar value off an entity through a possibly-magic accessor.
     *
     * @param object|null $entity The OpenRegister entity (or any object).
     * @param string      $getter The accessor name, e.g. 'getSchema'.
     *
     * @return string The value as a string, or '' when unavailable.
     *
     * @spec exclude See the trait docblock — infrastructure helper, no product
     *       requirement of its own.
     */
    private function readEntityValue(?object $entity, string $getter): string
    {
        if ($entity === null) {
            return '';
        }

        try {
            $value = $entity->{$getter}();
        } catch (Throwable $e) {
            // Entity::getter() throws BadFunctionCallException for a property the
            // entity does not declare; treat that exactly as "no value".
            return '';
        }

        if (is_scalar($value) === false) {
            return '';
        }

        return (string) $value;
    }//end readEntityValue()
}//end trait
