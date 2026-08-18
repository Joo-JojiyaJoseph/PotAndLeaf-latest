<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            Location::firstOrCreate(
                ['company_id' => $company->id, 'code' => 'GDN'],
                ['name' => 'Main Godown', 'type' => 'godown', 'is_default' => true, 'is_active' => true],
            );
            Location::firstOrCreate(
                ['company_id' => $company->id, 'code' => 'SHOP'],
                ['name' => 'Front Shop', 'type' => 'shop', 'is_default' => false, 'is_active' => true],
            );
        });
    }
}
