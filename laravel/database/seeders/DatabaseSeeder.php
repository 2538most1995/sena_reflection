<?php

namespace Database\Seeders;

use App\Models\Tambon;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Tambon::query()->updateOrCreate(['id' => '1214120001'], ['name' => 'เสนา', 'icon' => '🏙️']);
        User::query()->updateOrCreate(['id' => 'u0'], [
            'name' => 'ผู้ดูแลอำเภอ', 'username' => 'admin',
            'password' => Hash::make('change-me-now'), 'role' => 'district', 'tambon' => null,
        ]);
    }
}
