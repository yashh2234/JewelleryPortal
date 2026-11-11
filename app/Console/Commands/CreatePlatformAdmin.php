<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:admin {email} {name=Admin} {--password= : Leave out to be prompted}';

    protected $description = 'Create or update a super-admin who can manage all workspaces';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $admin = PlatformAdmin::updateOrCreate(
            ['email' => strtolower($this->argument('email'))],
            ['name' => $this->argument('name'), 'password' => $password],
        );

        $this->info("Super-admin ready: {$admin->email}");

        return self::SUCCESS;
    }
}
