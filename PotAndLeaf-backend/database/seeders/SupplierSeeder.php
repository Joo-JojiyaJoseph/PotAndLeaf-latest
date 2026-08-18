<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['supplier_code' => 'SUP-001', 'name' => 'Green Valley Wholesale Nursery',   'city' => 'Ernakulam',  'state' => 'Kerala',     'gst_number' => '32ABCDE1234F1Z5', 'credit_days' => 30],
            ['supplier_code' => 'SUP-002', 'name' => 'Malabar Pots & Clayware',          'city' => 'Kozhikode',  'state' => 'Kerala',     'gst_number' => '32PQRSX6789L1Z2', 'credit_days' => 15],
            ['supplier_code' => 'SUP-003', 'name' => 'AgriGrow Fertilizers Pvt Ltd',     'city' => 'Coimbatore', 'state' => 'Tamil Nadu', 'gst_number' => '33LMNOP2345Q1Z8', 'credit_days' => 45],
            ['supplier_code' => 'SUP-004', 'name' => 'Silverline Artificial Plants Co.', 'city' => 'Chennai',    'state' => 'Tamil Nadu', 'gst_number' => '33ARTPL9876K1Z4', 'credit_days' => 30],
            ['supplier_code' => 'SUP-005', 'name' => 'Deccan Fiber & Ceramic Planters',  'city' => 'Hyderabad',  'state' => 'Telangana',  'gst_number' => '36FIBER4567P1Z6', 'credit_days' => 30],
            ['supplier_code' => 'SUP-006', 'name' => 'Nilgiri Potting Mix & Soil Works', 'city' => 'Coimbatore', 'state' => 'Tamil Nadu', 'gst_number' => '33SOILW1122R1Z3', 'credit_days' => 20],
            ['supplier_code' => 'SUP-007', 'name' => 'Kerala Rubber Bud Wood Bank',      'city' => 'Kottayam',   'state' => 'Kerala',     'gst_number' => '32RUBBR3344S1Z9', 'credit_days' => 30],
            ['supplier_code' => 'SUP-008', 'name' => 'Sunrise Pebbles & Decor Traders',  'city' => 'Madurai',    'state' => 'Tamil Nadu', 'gst_number' => '33DECOR5566T1Z7', 'credit_days' => 15],
        ];

        Company::all()->each(function (Company $company) use ($suppliers) {
            foreach ($suppliers as $s) {
                Supplier::firstOrCreate(
                    ['company_id' => $company->id, 'supplier_code' => $s['supplier_code']],
                    $s + ['country' => 'India', 'status' => 'active'],
                );
            }
        });
    }
}
