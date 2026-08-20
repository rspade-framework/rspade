<?php

namespace App\RSpade\Core\Database\DetailTables;

/**
 * Detail_Tables_Resolver - pure helpers that interpret a base model's $detail_tables map.
 *
 * The map is the single source of truth for Class-Table Inheritance (see man detail_tables):
 *
 *   public static $detail_tables = [
 *       'type_id' => [                                    // the discriminator column
 *           self::TYPE_PERSON  => Party_Person_Detail_Model::class,
 *           self::TYPE_COMPANY => Party_Company_Detail_Model::class,
 *           // TYPE_GROUP absent => no detail (all fields universal)
 *       ],
 *   ];
 *
 * The detail accessor name is derived from the detail model basename
 * (Party_Person_Detail_Model -> party_person). Multiple discriminator values may map to
 * the same detail model (and thus the same accessor). These functions are stateless and
 * take the raw map so they are trivially testable without loading models.
 */
class Detail_Tables_Resolver
{
    /**
     * The discriminator column (the single top-level key), or null when no details.
     */
    public static function discriminator_column(?array $detail_tables): ?string
    {
        if (empty($detail_tables)) {
            return null;
        }

        $keys = array_keys($detail_tables);

        return $keys[0];
    }

    /**
     * The [discriminator_value => detail_class] map under the discriminator column.
     */
    public static function value_map(?array $detail_tables): array
    {
        $column = static::discriminator_column($detail_tables);
        if ($column === null) {
            return [];
        }

        return $detail_tables[$column] ?? [];
    }

    /**
     * The detail model class for a discriminator value, or null when that value has no detail.
     */
    public static function class_for_value(?array $detail_tables, $value): ?string
    {
        $map = static::value_map($detail_tables);

        return $map[$value] ?? null;
    }

    /**
     * Derive the accessor name from a detail model class.
     * Party_Person_Detail_Model -> party_person.
     */
    public static function accessor_name(string $detail_class): string
    {
        $base = class_basename($detail_class);

        foreach (['_Detail_Model', '_Model'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $base = substr($base, 0, -strlen($suffix));
                break;
            }
        }

        return strtolower($base);
    }

    /**
     * [accessor_name => detail_class] for every detail this base has (deduped).
     */
    public static function accessors(?array $detail_tables): array
    {
        $out = [];
        foreach (static::value_map($detail_tables) as $class) {
            $out[static::accessor_name($class)] = $class;
        }

        return $out;
    }

    /**
     * [discriminator_value => accessor_name] for every mapped value (used by the JS stub
     * so it can throw wrong-type locally from the base record's discriminator).
     */
    public static function value_to_accessor(?array $detail_tables): array
    {
        $out = [];
        foreach (static::value_map($detail_tables) as $value => $class) {
            $out[$value] = static::accessor_name($class);
        }

        return $out;
    }

    /**
     * The distinct detail model classes referenced by the map.
     */
    public static function detail_classes(?array $detail_tables): array
    {
        return array_values(array_unique(static::value_map($detail_tables)));
    }
}
