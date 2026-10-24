<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One location per company.
 *
 * Every company gets a single main location (created now if missing). Any
 * other locations are switched off, and their per-location stock is added
 * to the main location so nothing is lost. Old documents that point at the
 * old locations keep their history; the rows are not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->get(['id', 'name']) as $company) {
            $locations = DB::table('locations')->where('company_id', $company->id)
                ->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('created_at')->get();

            $main = $locations->first();
            if (! $main) {
                $id = (string) Str::uuid7();
                DB::table('locations')->insert([
                    'id' => $id, 'company_id' => $company->id, 'name' => $company->name, 'code' => 'MAIN',
                    'type' => 'shop', 'is_default' => true, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('locations')->where('id', $main->id)->update(['is_default' => true, 'is_active' => true, 'updated_at' => $now]);

            foreach ($locations->slice(1) as $other) {
                foreach (DB::table('location_stock')->where('location_id', $other->id)->get() as $row) {
                    $existing = DB::table('location_stock')->where('location_id', $main->id)->where('product_id', $row->product_id)->first();
                    if ($existing) {
                        DB::table('location_stock')->where('id', $existing->id)
                            ->update(['qty' => (float) $existing->qty + (float) $row->qty, 'updated_at' => $now]);
                        DB::table('location_stock')->where('id', $row->id)->delete();
                    } else {
                        DB::table('location_stock')->where('id', $row->id)->update(['location_id' => $main->id, 'updated_at' => $now]);
                    }
                }

                DB::table('locations')->where('id', $other->id)->update(['is_default' => false, 'is_active' => false, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Stock merged into the main location is not split back.
    }
};
