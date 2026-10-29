<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Categories and departments.
 *
 * The category slugs here are exactly the values in the React app's
 * ItemCategory union type (src/types/index.ts). If you add one, add it
 * there too or the dropdown will not offer it.
 *
 * Safe to re-run: everything uses updateOrCreate.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'electronics', 'name' => 'Electronics'],
            ['slug' => 'documents', 'name' => 'Documents & ID'],
            ['slug' => 'clothing', 'name' => 'Clothing'],
            ['slug' => 'bags', 'name' => 'Bags'],
            ['slug' => 'accessories', 'name' => 'Accessories'],
            ['slug' => 'keys', 'name' => 'Keys'],
            ['slug' => 'books', 'name' => 'Books & stationery'],
            ['slug' => 'other', 'name' => 'Other'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['slug' => $category['slug']], $category);
        }

        foreach (['Student Affairs', 'IT Services', 'Security', 'Library Services'] as $name) {
            Department::updateOrCreate(['name' => $name], ['name' => $name]);
        }
    }
}
