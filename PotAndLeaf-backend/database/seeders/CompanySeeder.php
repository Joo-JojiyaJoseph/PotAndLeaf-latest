<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Seeds the Cheerakuzhy Group entities as separate companies (Tally-style
 * multi-company). Each operates under its own name/legal identity but shares
 * the same centralized database. Pot & Leaf is the flagship retail brand and
 * is protected (cannot be deleted) since it is the default/primary company.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            [
                'code'         => 'POTLEAF',
                'name'         => 'Pot & Leaf _ Super Admin',
                'legal_name'   => 'Pot & Leaf Retail Network',
                'state'        => 'Kerala',
                'state_code'   => '32',
                'is_protected' => true,
            ],

        ];

        foreach ($companies as $data) {
            Company::updateOrCreate(
                ['code' => $data['code']],
                $data + ['is_active' => true],
            );
        }
    }
}
