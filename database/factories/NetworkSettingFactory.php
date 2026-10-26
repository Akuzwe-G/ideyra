<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\NetworkSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NetworkSetting> */
class NetworkSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'allow_network' => false, 'allow_public' => false, 'require_collaboration_approval' => true];
    }
}
