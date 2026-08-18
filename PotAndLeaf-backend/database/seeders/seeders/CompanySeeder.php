<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['code' => 'CHK-HO',  'name' => 'Cheerakuzhy Nurseries (HO)', 'legal_name' => 'Cheerakuzhy Group', 'state' => 'Kerala', 'state_code' => '32', 'is_protected' => true],

        ];

        foreach ($companies as $data) {
            Company::updateOrCreate(
                ['code' => $data['code']],
                $data + ['is_active' => true],
            );
        }
    }
}
