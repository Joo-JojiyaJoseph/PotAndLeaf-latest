<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

/**
 * Sample catalog spanning every product family from the SRS: live plants,
 * artificial plants, pots/planters, potting mix & fertilizers, decorative
 * pebbles, garden accessories, raw materials used in production, and
 * ready-to-sell value-added arrangements (e.g. Ceramic Pot + Artificial
 * Plant + Pebbles, and a rooted sapling packed in a grow bag).
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            // Live plants
            ['sku' => 'PLT-ROSE',    'name' => 'Rose Plant (Hybrid Tea)',            'cat' => 'PLANT',    'unit' => 'NOS', 'gst' => 5,  'cost' => 45,  'mrp' => 120,  'reorder' => 20,  'dim' => [15, 15, 40]],
            ['sku' => 'PLT-MANGO',   'name' => 'Mango Sapling (Alphonso)',           'cat' => 'PLANT',    'unit' => 'NOS', 'gst' => 5,  'cost' => 90,  'mrp' => 220,  'reorder' => 15,  'dim' => [20, 20, 60]],
            ['sku' => 'PLT-RUBBER',  'name' => 'Rubber Plant Sapling (RRII 105)',    'cat' => 'PLANT',    'unit' => 'NOS', 'gst' => 5,  'cost' => 35,  'mrp' => 95,   'reorder' => 100, 'dim' => [15, 15, 45]],
            ['sku' => 'PLT-MONEY',   'name' => 'Money Plant (Indoor)',               'cat' => 'PLANT',    'unit' => 'NOS', 'gst' => 5,  'cost' => 25,  'mrp' => 70,   'reorder' => 30,  'dim' => [12, 12, 30]],
            ['sku' => 'PLT-ARECA',   'name' => 'Areca Palm (Indoor)',                'cat' => 'PLANT',    'unit' => 'NOS', 'gst' => 5,  'cost' => 150, 'mrp' => 380,  'reorder' => 15,  'dim' => [30, 30, 90]],

            // Artificial plants
            ['sku' => 'ART-FICUS',   'name' => 'Artificial Ficus Tree 4ft',          'cat' => 'ARTPLANT', 'unit' => 'NOS', 'gst' => 12, 'cost' => 650, 'mrp' => 1450, 'reorder' => 8,   'dim' => [40, 40, 120]],
            ['sku' => 'ART-BAMBOO',  'name' => 'Artificial Bamboo Plant',            'cat' => 'ARTPLANT', 'unit' => 'NOS', 'gst' => 12, 'cost' => 280, 'mrp' => 650,  'reorder' => 12,  'dim' => [20, 20, 80]],

            // Pots & planters
            ['sku' => 'POT-CLAY8',   'name' => 'Clay Pot 8 inch',                    'cat' => 'POT',      'unit' => 'NOS', 'gst' => 12, 'cost' => 35,  'mrp' => 80,   'reorder' => 50,  'dim' => [20, 20, 18]],
            ['sku' => 'POT-FIBER',   'name' => 'Fiber Planter (Large)',              'cat' => 'POT',      'unit' => 'NOS', 'gst' => 18, 'cost' => 260, 'mrp' => 560,  'reorder' => 10,  'dim' => [45, 45, 40]],
            ['sku' => 'POT-CERAM',   'name' => 'Ceramic Designer Pot 10 inch',       'cat' => 'POT',      'unit' => 'NOS', 'gst' => 12, 'cost' => 180, 'mrp' => 420,  'reorder' => 20,  'dim' => [25, 25, 25]],

            // Potting mix & fertilizers
            ['sku' => 'FRT-VERMI',   'name' => 'Vermicompost 5kg',                   'cat' => 'FERT',     'unit' => 'BAG', 'gst' => 5,  'cost' => 120, 'mrp' => 200,  'reorder' => 25,  'dim' => [40, 25, 12]],
            ['sku' => 'FRT-COCO',    'name' => 'Cocopeat Block 5kg',                 'cat' => 'FERT',     'unit' => 'BAG', 'gst' => 5,  'cost' => 90,  'mrp' => 160,  'reorder' => 30,  'dim' => [30, 20, 10]],
            ['sku' => 'FRT-POTMIX',  'name' => 'Organic Potting Mix 10kg',           'cat' => 'FERT',     'unit' => 'BAG', 'gst' => 5,  'cost' => 150, 'mrp' => 260,  'reorder' => 20,  'dim' => [45, 30, 12]],

            // Pebbles & decorative materials
            ['sku' => 'DEC-PEBBLE',  'name' => 'Decorative Pebbles 1kg',             'cat' => 'DECOR',    'unit' => 'PKT', 'gst' => 12, 'cost' => 25,  'mrp' => 60,   'reorder' => 40,  'dim' => [15, 10, 8]],

            // Garden accessories
            ['sku' => 'ACC-TROWEL',  'name' => 'Garden Tool Set (3pc)',              'cat' => 'ACC',      'unit' => 'SET', 'gst' => 18, 'cost' => 90,  'mrp' => 220,  'reorder' => 20,  'dim' => [30, 15, 5]],
            ['sku' => 'ACC-CAN5L',   'name' => 'Watering Can 5L',                    'cat' => 'ACC',      'unit' => 'NOS', 'gst' => 18, 'cost' => 140, 'mrp' => 320,  'reorder' => 15,  'dim' => [30, 20, 25]],

            // Raw materials (consumed in production/value addition)
            ['sku' => 'RAW-GROWBAG', 'name' => 'Grow Bag 12x15 inch (Poly)',         'cat' => 'RAW',      'unit' => 'NOS', 'gst' => 18, 'cost' => 8,   'mrp' => 18,   'reorder' => 200, 'dim' => [30, 38, 1]],

            // Ready-to-sell value-added arrangements (production output)
            ['sku' => 'ARR-DECOSET', 'name' => 'Decorative Plant Arrangement (Ceramic Pot + Artificial Plant + Pebbles)', 'cat' => 'ARR', 'unit' => 'NOS', 'gst' => 12, 'cost' => 420, 'mrp' => 950, 'reorder' => 10, 'dim' => [30, 30, 60]],
            ['sku' => 'ARR-GROWPLT', 'name' => 'Rooted Sapling in Grow Bag (Ready Sale)', 'cat' => 'ARR', 'unit' => 'NOS', 'gst' => 5,  'cost' => 60,  'mrp' => 140,  'reorder' => 25,  'dim' => [15, 15, 45]],
        ];

        Company::all()->each(function (Company $company) use ($catalog) {
            $cats = ProductCategory::where('company_id', $company->id)->pluck('id', 'code');
            $units = ProductUnit::where('company_id', $company->id)->pluck('id', 'code');
            $brand = ProductBrand::where('company_id', $company->id)->where('code', 'GEN')->value('id');

            foreach ($catalog as $p) {
                Product::firstOrCreate(
                    ['company_id' => $company->id, 'sku' => $p['sku']],
                    [
                        'name'          => $p['name'],
                        'category_id'   => $cats[$p['cat']] ?? null,
                        'brand_id'      => $brand,
                        'unit_id'       => $units[$p['unit']] ?? null,
                        'gst_rate'      => $p['gst'],
                        'cost_price'    => $p['cost'],
                        'mrp'           => $p['mrp'],
                        'retail_price'  => $p['mrp'],
                        'reorder_level' => $p['reorder'],
                        'length_cm'     => $p['dim'][0] ?? null,
                        'width_cm'      => $p['dim'][1] ?? null,
                        'height_cm'     => $p['dim'][2] ?? null,
                        'opening_stock' => 0,
                        'current_stock' => 0, // starts empty so purchases drive stock (and low-stock alerts show)
                        'status'        => 'active',
                    ],
                );
            }
        });
    }
}
