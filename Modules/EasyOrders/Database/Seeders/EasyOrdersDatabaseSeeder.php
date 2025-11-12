<?php

namespace Modules\EasyOrders\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class EasyOrdersDatabaseSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
		Model::unguard();

		$this->call([
			EasyOrdersPermissionSeeder::class,
		]);
	}
}
