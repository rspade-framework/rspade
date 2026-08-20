# Migration Guidelines

## Polymorphic Type References

When migrating a column to use polymorphic type references, **never query the `_type_refs` table directly**.

### Wrong Approach

```php
// DON'T DO THIS - IDs are not predictable across environments
$type_id = DB::table('_type_refs')->where('class_name', 'Contact_Model')->value('id');
DB::statement("UPDATE my_table SET entity_type = {$type_id} WHERE entity_type = 'Contact_Model'");
```

### Correct Approach

```php
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

// Use the API - it creates the entry if it doesn't exist
$type_id = Type_Ref_Registry::class_to_id('Contact_Model');
DB::statement("UPDATE my_table SET entity_type = {$type_id} WHERE entity_type = 'Contact_Model'");
```

### Why This Matters

1. **IDs are not predictable** - The `_type_refs` table auto-populates on first use. Different environments (dev, staging, prod) may have different IDs for the same class.

2. **Auto-creation** - `class_to_id()` creates the entry if it doesn't exist, ensuring the migration works on fresh installs.

3. **Validation** - The API validates that the class exists and extends `Rsx_Model_Abstract`.

4. **Consistency** - Using the API ensures all code paths go through the same registration logic.

### Example Migration

```php
public function up()
{
    // Convert VARCHAR to BIGINT
    DB::statement("ALTER TABLE activities ADD COLUMN eventable_type_new BIGINT NULL");

    // Migrate existing data using the API
    $contact_id = Type_Ref_Registry::class_to_id('Contact_Model');
    $project_id = Type_Ref_Registry::class_to_id('Project_Model');

    DB::statement("UPDATE activities SET eventable_type_new = {$contact_id} WHERE eventable_type = 'Contact_Model'");
    DB::statement("UPDATE activities SET eventable_type_new = {$project_id} WHERE eventable_type = 'Project_Model'");

    // Swap columns
    DB::statement("ALTER TABLE activities DROP COLUMN eventable_type");
    DB::statement("ALTER TABLE activities CHANGE eventable_type_new eventable_type BIGINT NULL");
}
```
