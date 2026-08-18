<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Seeds the Central Godown at Mannarkkad plus the retail/production
 * locations for each company. Pot & Leaf gets the multi-shop retail
 * network described in the SRS; the nursery/agro/rubber companies get a
 * production godown and, where relevant, a sales counter.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locationsByCompany = [
            'POTLEAF' => [
                ['code' => 'GDN-MKD',  'name' => 'Central Godown - Mannarkkad', 'type' => 'godown', 'is_default' => true],
                ['code' => 'SHOP-MKD', 'name' => 'Pot & Leaf - Mannarkkad',     'type' => 'shop',   'is_default' => false],
                ['code' => 'SHOP-KCH', 'name' => 'Pot & Leaf - Kochi',          'type' => 'shop',   'is_default' => false],
                ['code' => 'SHOP-KZD', 'name' => 'Pot & Leaf - Kozhikode',      'type' => 'shop',   'is_default' => false],
                ['code' => 'SHOP-TSR', 'name' => 'Pot & Leaf - Thrissur',       'type' => 'shop',   'is_default' => false],
                ['code' => 'SHOP-PLK', 'name' => 'Pot & Leaf - Palakkad',       'type' => 'shop',   'is_default' => false],
            ],
            'CHK-NSY' => [
                ['code' => 'GDN-MKD',  'name' => 'Main Production Nursery - Mannarkkad', 'type' => 'godown', 'is_default' => true],
                ['code' => 'SHOP-MKD', 'name' => 'Garden Center - Mannarkkad',           'type' => 'shop',   'is_default' => false],
            ],
            'CHK-AGRO' => [
                ['code' => 'GDN-MKD',  'name' => 'Agro Nursery - Mannarkkad', 'type' => 'godown', 'is_default' => true],
                ['code' => 'SHOP-PLK', 'name' => 'Sales Counter - Palakkad',  'type' => 'shop',   'is_default' => false],
            ],
            'CHK-RBR' => [
                ['code' => 'GDN-MKD',  'name' => 'Rubber Nursery - Mannarkkad', 'type' => 'godown', 'is_default' => true],
                ['code' => 'SHOP-MKD', 'name' => 'Rubber Sapling Counter - Mannarkkad', 'type' => 'shop', 'is_default' => false],
            ],
        ];

        Company::all()->each(function (Company $company) use ($locationsByCompany) {
            $locations = $locationsByCompany[$company->code] ?? [
                ['code' => 'GDN',  'name' => 'Main Godown', 'type' => 'godown', 'is_default' => true],
                ['code' => 'SHOP', 'name' => 'Front Shop',  'type' => 'shop',   'is_default' => false],
            ];

            foreach ($locations as $loc) {
                Location::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $loc['code']],
                    ['name' => $loc['name'], 'type' => $loc['type'], 'is_default' => $loc['is_default'], 'is_active' => true],
                );
            }
        });
    }
}
