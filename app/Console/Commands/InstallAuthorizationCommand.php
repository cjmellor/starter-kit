<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class InstallAuthorizationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure role-based authorization with permissions';

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! confirm(
            label: 'Do you want to install Authorization into your app?',
            default: false
        )) {
            return 1;
        }

        info(message: 'Setting up Authorization');

        try {
            $this->installAuthorizationPackage();
            $this->runMigrations();
            $this->createUserRoleEnum();
            $this->addAuthorizableTraitToUserModel();
            $this->setupRoleModelAndFactory();
            $this->createMigrationForDefaultRoles();
            $this->setupCustomRoleModelInProvider();
            $this->setupUserSeeder();
            $this->runSeedersIfNeeded();
        } catch (ProcessFailedException $e) {
            error(message: "Process failed: {$e->getMessage()} \nOutput:\n{$e->result->output()}\nError Output:\n{$e->result->errorOutput()}");

            return 1;
        } catch (FileNotFoundException $e) {
            error(message: "File not found: {$e->getMessage()}");

            return 1;
        } catch (Exception $e) {
            error(message: "An error occurred: {$e->getMessage()}");

            return 1;
        }

        info(message: 'Authorization setup completed.');

        return 0;
    }

    private function installAuthorizationPackage(): void
    {
        $this->runProcess(
            command: 'composer require directorytree/authorization',
            infoMessage: 'Installing authorization package'
        );
    }

    /**
     * Run a shell command and throw an exception if it fails.
     *
     * @throws ProcessFailedException
     */
    private function runProcess(string|array $command, ?string $infoMessage = null): void
    {
        if ($infoMessage) {
            info($infoMessage);
        }

        $result = Process::run($command);

        if ($result->failed()) {
            throw new ProcessFailedException($result);
        }

        if ($result->output()) {
            $this->line($result->output());
        }
    }

    private function runMigrations(): void
    {
        $this->runProcess(
            command: 'php artisan migrate --force --no-interaction',
            infoMessage: 'Running migrations'
        );
    }

    private function createUserRoleEnum(): void
    {
        $this->runProcess(
            command: 'php artisan make:enum Enums/UserRole --string',
            infoMessage: 'Creating UserRole Enum'
        );

        $this->modifyFile(
            path: app_path(path: 'Enums/UserRole.php'),
            callback: function (string $content): string {
                return str_replace(
                    search: "    //\n",
                    replace: "    case Owner = 'owner';\n    case Member = 'member';\n    case Follower = 'follower';\n",
                    subject: $content
                );
            }
        );
    }

    /**
     * Modify the content of a file using a callback.
     *
     * @throws FileNotFoundException
     */
    private function modifyFile(string $path, callable $callback): void
    {
        if (! $this->files->exists(path: $path)) {
            throw new FileNotFoundException(message: "File does not exist at path {$path}.");
        }

        $content = $this->files->get(path: $path);
        $newContent = $callback($content);

        $this->files->put(path: $path, contents: $newContent);
    }

    private function addAuthorizableTraitToUserModel(): void
    {
        $this->modifyFile(app_path(path: 'Models/User.php'), function (string $content): string {
            $content = Str::replaceFirst(
                search: "namespace App\Models;\n",
                replace: "namespace App\Models;\n\nuse DirectoryTree\Authorization\Traits\Authorizable;",
                subject: $content
            );

            return Str::replaceFirst(
                search: 'use HasFactory, Notifiable',
                replace: 'use Authorizable, HasFactory, Notifiable',
                subject: $content
            );
        });
    }

    private function setupRoleModelAndFactory(): void
    {
        info('Setting up Role model and factory');

        $this->runProcess(command: 'php artisan make:model Role -f');

        $this->updateRoleModel();

        $this->updateRoleFactory();
    }

    private function updateRoleModel(): void
    {
        $this->modifyFile(app_path(path: 'Models/Role.php'), function (string $content): string {
            $content = Str::replaceFirst(
                search: "namespace App\Models;\n",
                replace: "namespace App\Models;\n\nuse DirectoryTree\Authorization\Traits\ManagesPermissions;",
                subject: $content
            );

            return Str::replaceFirst(
                search: 'use HasFactory;',
                replace: 'use HasFactory, ManagesPermissions;',
                subject: $content
            );
        });
    }

    private function updateRoleFactory(): void
    {
        $this->modifyFile(database_path(path: 'factories/RoleFactory.php'), function (string $content): string {
            $content = Str::replaceFirst(
                search: "namespace Database\Factories;\n",
                replace: "namespace Database\Factories;\n\nuse App\Enums\UserRole;",
                subject: $content
            );

            return Str::replace(
                search: "public function definition(): array\n    {\n        return [\n            //\n        ];\n    }",
                replace: "public function definition(): array\n    {\n        return [\n            'name' => \$role = fake()->randomElement(UserRole::cases())->value,\n            'label' => str(\$role)->ucfirst(),\n        ];\n    }\n\n    public function owner(): static\n    {\n        return \$this->state(fn (array \$attributes) => [\n            'name' => UserRole::Owner->value,\n            'label' => str(UserRole::Owner->value)->ucfirst(),\n        ]);\n    }\n\n    public function member(): static\n    {\n        return \$this->state(fn (array \$attributes) => [\n            'name' => UserRole::Member->value,\n            'label' => str(UserRole::Member->value)->ucfirst(),\n        ]);\n    }\n\n    public function follower(): static\n    {\n        return \$this->state(fn (array \$attributes) => [\n            'name' => UserRole::Follower->value,\n            'label' => str(UserRole::Follower->value)->ucfirst(),\n        ]);\n    }\n\n    public function customRole(string \$name): static\n    {\n        return \$this->state(fn (array \$attributes) => [\n            'name' => \$name,\n            'label' => str(\$name)->ucfirst(),\n        ]);\n    }",
                subject: $content
            );
        });
    }

    private function createMigrationForDefaultRoles(): void
    {
        $this->runProcess(
            command: 'php artisan make:migration create_default_roles',
            infoMessage: 'Creating migration for default roles'
        );

        $migrationFiles = $this->files->glob(pattern: database_path(path: 'migrations/*_create_default_roles.php'));

        if (empty($migrationFiles)) {
            throw new FileNotFoundException(message: 'Default roles migration file not found after creation.');
        }

        $this->modifyFile(path: end($migrationFiles), callback: function (string $content): string {
            // Add required imports
            $content = Str::replaceFirst(
                search: "use Illuminate\Support\Facades\Schema;\n",
                replace: "use Illuminate\Support\Facades\Schema;\nuse App\Enums\UserRole;\nuse App\Models\Role;\n",
                subject: $content
            );

            // Replace the up method
            $content = preg_replace(
                pattern: "/public function up\(\): void\n    \{.*?\n    }/s",
                replacement: "public function up(): void\n    {\n        foreach (UserRole::cases() as \$role) {\n            Role::create([\n                'name' => \$role->value,\n                'label' => ucfirst(\$role->value),\n            ]);\n        }\n    }",
                subject: $content
            );

            // Remove the down method
            return preg_replace(
                pattern: "/\s*\/\*\*\s*\*\s*Reverse the migrations\.\s*\*\/\s*public function down\(\): void\s*\{[^}]*}/s",
                replacement: '',
                subject: $content
            );
        });
    }

    private function setupCustomRoleModelInProvider(): void
    {
        $this->modifyFile(app_path(path: 'Providers/AppServiceProvider.php'), function (string $content): string {
            $content = Str::replaceFirst(
                search: "namespace App\Providers;\n",
                replace: "namespace App\Providers;\n\nuse App\Models\Role;\nuse DirectoryTree\Authorization\Authorization;",
                subject: $content
            );

            // Avoid adding the line if it already exists
            if (Str::doesntContain(
                haystack: $content,
                needles: 'Authorization::useRoleModel(roleModel: Role::class);'
            )) {
                $content = Str::replaceFirst(
                    search: "public function boot(): void\n    {",
                    replace: "public function boot(): void\n    {\n        Authorization::useRoleModel(roleModel: Role::class);\n",
                    subject: $content
                );
            }

            return $content;
        });
    }

    private function setupUserSeeder(): void
    {
        $this->runProcess(
            command: 'php artisan make:seeder UserSeeder',
            infoMessage: 'Setting up UserSeeder'
        );

        $this->updateUserSeederFile();

        $this->updateDatabaseSeederFile();
    }

    private function updateUserSeederFile(): void
    {
        $this->modifyFile(database_path(path: 'seeders/UserSeeder.php'), function (string $content): string {
            // Add required imports
            $content = Str::replaceFirst(
                search: "use Illuminate\Database\Seeder;\n",
                replace: "use App\Enums\UserRole;\nuse App\Models\Role;\nuse App\Models\User;\nuse Illuminate\Database\Seeder;\n",
                subject: $content
            );

            // Replace run method content
            return preg_replace(
                pattern: "/public function run\(\): void\n    \{[^}]*}/s",
                replacement: "public function run(): void\n    {\n        \$users = [\n            [\n                'name' => 'Owner User',\n                'email' => 'owner@example.com',\n                'role' => UserRole::Owner,\n            ],\n            [\n                'name' => 'Member User',\n                'email' => 'member@example.com',\n                'role' => UserRole::Member,\n            ],\n            [\n                'name' => 'Follower User',\n                'email' => 'follower@example.com',\n                'role' => UserRole::Follower,\n            ],\n        ];\n\n        collect(\$users)->each(function (\$user) {\n            User::factory()\n                ->hasAttached(Role::firstWhere('name', \$user['role']->value))\n                ->create([\n                    'name' => \$user['name'],\n                    'email' => \$user['email'],\n                    'password' => 'password', // Consider hashing here if not done by factory/model event\n                ]);\n        });\n    }",
                subject: $content
            );
        });
    }

    private function updateDatabaseSeederFile(): void
    {
        $this->modifyFile(database_path(path: 'seeders/DatabaseSeeder.php'), function (string $content): string {
            // Add UserSeeder import if not present
            if (Str::doesntContain(
                haystack: $content,
                needles: 'use Database\Seeders\UserSeeder;'
            )) {
                $content = Str::replaceFirst(
                    search: "namespace Database\Seeders;\n",
                    replace: "namespace Database\Seeders;\n\nuse Database\Seeders\UserSeeder;",
                    subject: $content
                );
            }

            // Replace run method content to call UserSeeder
            // Make this more robust by adding the call if it doesn't exist
            if (Str::doesntContain(
                haystack: $content,
                needles: 'UserSeeder::class,'
            )) {
                $content = preg_replace(
                    pattern: "/public function run\(\): void\n {4}\{[^}]*}/s",
                    replacement: "public function run(): void\n    {\n        \$this->call([\n            UserSeeder::class,\n            // Add other seeders here if needed\n        ]);\n    }",
                    subject: $content
                );
            }

            return $content;
        });
    }

    private function runSeedersIfNeeded(): void
    {
        if (confirm(label: 'Do you want to run the database seeders?')) {
            $this->runProcess(
                command: 'php artisan db:seed',
                infoMessage: 'Running database seeders'
            );
        } else {
            warning(message: 'You can run the seeders later by executing `php artisan db:seed`');
        }
    }
}
