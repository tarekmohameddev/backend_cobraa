<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class EasyOrdersPermissionSeeder extends Seeder
{
	public function run(): void
	{
		$permissions = [
			'manage easyorders',
			'approve easyorders orders',
		];

		foreach ($permissions as $name) {
			Permission::findOrCreate($name, 'web');
		}
	}
}


