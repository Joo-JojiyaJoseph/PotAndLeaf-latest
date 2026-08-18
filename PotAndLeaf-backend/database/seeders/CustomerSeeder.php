<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['customer_code' => 'CUST-00001', 'name' => 'Walk-in Retail',              'type' => 'retail',    'city' => 'Mannarkkad', 'state' => 'Kerala'],
            ['customer_code' => 'CUST-00002', 'name' => 'GreenScape Landscapers',      'type' => 'wholesale', 'city' => 'Palakkad',   'state' => 'Kerala',     'gst_number' => '32AAACG1234M1Z9', 'credit_days' => 30],
            ['customer_code' => 'CUST-00003', 'name' => 'City Garden Dealers',         'type' => 'dealer',    'city' => 'Thrissur',   'state' => 'Kerala',     'gst_number' => '32BBBCG5678N1Z1', 'credit_days' => 45],
            ['customer_code' => 'CUST-00004', 'name' => 'Kochi Landscaping Solutions', 'type' => 'wholesale', 'city' => 'Kochi',      'state' => 'Kerala',     'gst_number' => '32CCCLS4321P1Z3', 'credit_days' => 30],
            ['customer_code' => 'CUST-00005', 'name' => 'Malabar Garden Dealers',      'type' => 'dealer',    'city' => 'Kozhikode',  'state' => 'Kerala',     'gst_number' => '32DDDMG8765Q1Z7', 'credit_days' => 30],
            ['customer_code' => 'CUST-00006', 'name' => 'Coimbatore Plant Traders',    'type' => 'dealer',    'city' => 'Coimbatore', 'state' => 'Tamil Nadu', 'gst_number' => '33EEEPT2468R1Z5', 'credit_days' => 30],
        ];

        Company::all()->each(function (Company $company) use ($customers) {
            foreach ($customers as $c) {
                Customer::firstOrCreate(
                    ['company_id' => $company->id, 'customer_code' => $c['customer_code']],
                    $c + ['status' => 'active'],
                );
            }
        });
    }
}
