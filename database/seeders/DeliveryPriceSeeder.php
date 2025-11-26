<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\DeliveryPrice;
use Illuminate\Database\Seeder;

class DeliveryPriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        // Set a default delivery price of 19 for every area
        Area::query()->chunkById(500, function ($areas) {
            /** @var \App\Models\Area $area */
            foreach ($areas as $area) {
                DeliveryPrice::updateOrCreate(
                    [
                        'area_id'    => $area->id,
                        'region_id'  => $area->region_id,
                        'country_id' => $area->country_id,
                        'city_id'    => $area->city_id,
                        'shop_id'    => 501,
                    ],
                    [
                        'price' => 19,
                    ]
                );
            }
        });
    }
}


