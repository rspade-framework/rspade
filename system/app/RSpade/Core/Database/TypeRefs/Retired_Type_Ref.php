<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\TypeRefs;

use RuntimeException;

/**
 * Retired_Type_Ref - the POISON ALIAS for a _type_refs row whose model class is gone.
 *
 * A model that is deleted or renamed leaves its _type_refs row behind. Before this class
 * existed, register_morph_map() simply skipped such a row: the integer alias was absent
 * from the morph map, so Laravel's getActualClassNameForMorph() returned the raw integer
 * unchanged and newRelatedInstance() executed `new 3` - "Class name must be a valid object
 * or a string", naming neither the table, the column, the row, nor the class.
 *
 * WHY A POISON ALIAS AND NOT A BOOT THROW
 * ---------------------------------------
 * Throwing at registration would brick an entire app for a retired model that nothing
 * references - a latent data problem converted into a total outage. So the failure lands
 * at the POINT OF USE instead: the retired id IS registered in the morph map, pointing at
 * a class whose constructor throws the full story. `morphTo()` does `new $class`
 * immediately, so the message appears at the exact frame that used to be cryptic, and
 * `whereHasMorph($relation, '*')` - which instantiates every plucked type - fails the same
 * named way.
 *
 * THE MECHANISM
 * -------------
 * Laravel's morph map maps alias => class-name STRING and instantiates that string with
 * `new $class`, which gives the constructed class no way to learn WHICH alias produced it.
 * Naming the id and the class in the message therefore requires one distinct class per
 * retired type ref. There is no file to autoload (the ids are data, not code), so the
 * class is declared with a single eval() over a fully controlled template: the id is an
 * int and the body is a constant string. This runs ONLY when a retired type ref exists -
 * a healthy registry never evaluates a line of it.
 */
class Retired_Type_Ref
{
    /** Namespace the generated poison classes are declared in (no files, no autoload). */
    private const POISON_NAMESPACE = 'App\\RSpade\\Core\\Database\\TypeRefs\\Poison';

    /**
     * Poison FQCN => the retired pair it stands for.
     * @var array<string, array{id: int, class_name: string}>
     */
    protected static array $poison = [];

    /**
     * Poison FQCNs already declared in this process. Kept separate from $poison because a
     * class cannot be un-declared: re-registering a type ref must never re-eval the class.
     * @var array<string, bool>
     */
    protected static array $declared = [];

    /**
     * The poison class name to register in the morph map for a retired (id, class_name).
     *
     * @param int $id The _type_refs row id.
     * @param string $class_name The simple class name the row names, which no longer exists.
     * @return string FQCN of a class whose constructor throws.
     */
    public static function poison_class_for(int $id, string $class_name): string
    {
        $fqcn = self::POISON_NAMESPACE . '\\Retired_Type_Ref_' . $id;

        static::$poison[$fqcn] = ['id' => $id, 'class_name' => $class_name];

        if (!isset(static::$declared[$fqcn])) {
            static::$declared[$fqcn] = true;
            eval(
                'namespace ' . self::POISON_NAMESPACE . ';'
                . ' class Retired_Type_Ref_' . $id . ' {'
                . ' public function __construct() {'
                . ' \\' . static::class . '::throw_for(static::class);'
                . ' } }'
            );
        }

        return $fqcn;
    }

    /**
     * Throw the full story for a poison class. Called from the generated constructor.
     *
     * @param string $poison_fqcn
     * @throws RuntimeException Always.
     */
    public static function throw_for(string $poison_fqcn): void
    {
        $entry = static::$poison[$poison_fqcn] ?? null;

        if ($entry === null) {
            shouldnt_happen("Poison type-ref class {$poison_fqcn} was instantiated but is not registered");
        }

        throw new RuntimeException(static::message($entry['id'], $entry['class_name']));
    }

    /**
     * The one message every retired-type-ref failure carries - the registry read paths use
     * it too, so the cast, the relation and the write refusal all read the same.
     */
    public static function message(int $id, string $class_name): string
    {
        return "Type ref {$id} (\"{$class_name}\") names a model class that no longer exists in the"
            . " codebase. Run 'php artisan rsx:health' to see which tables and columns still hold"
            . " this id, repoint or delete those rows, then drop the registry row with"
            . " 'php artisan rsx:type_refs:prune' (or restore the class).";
    }

    /**
     * Every retired pair registered in this process: poison FQCN => ['id', 'class_name'].
     *
     * @return array<string, array{id: int, class_name: string}>
     */
    public static function registered(): array
    {
        return static::$poison;
    }
}
