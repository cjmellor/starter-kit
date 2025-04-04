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

    /**
     * @throws FileNotFoundException
     */
    private function createUserRoleEnum(): void
    {
        info('Creating UserRole Enum');

        $stubPath = base_path(path: 'stubs/enums.user_role.stub');
        $targetPath = app_path(path: 'Enums/UserRole.php');

        // Check if stub exists
        if ($this->files->missing($stubPath)) {
            throw new FileNotFoundException(message: "Stub file not found at {$stubPath}");
        }

        // Create Enums directory if it doesn't exist
        $enumsDirectory = app_path(path: 'Enums');

        if ($this->files->missing($enumsDirectory)) {
            $this->files->makeDirectory($enumsDirectory);
        }

        // Check if target file already exists
        if ($this->files->exists($targetPath)) {
            error('UserRole Enum already exists, skipping creation.');

            return;
        }

        // Copy stub to target location
        $this->files->copy($stubPath, $targetPath);
    }

    /**
     * Modify the content of a file using a callback.
     *
     * @throws FileNotFoundException
     */
    private function modifyFile(string $path, callable $callback): void
    {
        if ($this->files->missing($path)) {
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

    /**
     * @throws FileNotFoundException
     */
    private function setupRoleModelAndFactory(): void
    {
        info('Setting up Role model and factory');

        $stubPath = base_path(path: 'stubs/models.role.stub');
        $targetPath = app_path(path: 'Models/Role.php');

        if ($this->files->missing($stubPath)) {
            throw new FileNotFoundException(message: "Stub file not found at {$stubPath}");
        }

        if ($this->files->exists($targetPath)) {
            error('Role model already exists, skipping creation.');

            return;
        }

        $this->files->copy($stubPath, $targetPath);

        $this->createRoleFactory();
    }

    private function createRoleFactory(): void
    {
        $stubPath = base_path('stubs/database.factories.role_factory.stub');
        $targetPath = database_path('factories/RoleFactory.php');

        if ($this->files->missing($stubPath)) {
            throw new FileNotFoundException("Stub file not found at {$stubPath}");
        }

        if ($this->files->exists($targetPath)) {
            return;
        }

        $this->files->copy($stubPath, $targetPath);
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
            // Replace all imports with the ones we want in the correct order
            $content = preg_replace(
                pattern: "/use.*?;\n(use.*?;\n)*/s",
                replacement: "use App\Enums\UserRole;\nuse App\Models\Role;\nuse Illuminate\Database\Migrations\Migration;\n",
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
            // First, remove all the imports we want to reorder
            $content = preg_replace(
                pattern: "/use App\\\\Models\\\\Role;\n|use Carbon\\\\CarbonImmutable;\n|use DirectoryTree\\\\Authorization\\\\Authorization;\n/",
                replacement: '',
                subject: $content
            );

            // Then add them back in the correct order after the namespace, ensuring no extra newline
            $content = Str::replaceFirst(
                search: "namespace App\Providers;\n\n",
                replace: "namespace App\Providers;\n\nuse App\Models\Role;\nuse Carbon\CarbonImmutable;\nuse DirectoryTree\Authorization\Authorization;\n",
                subject: $content
            );

            // Add the Authorization configuration if it doesn't exist
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
        info('Setting up UserSeeder');

        $stubPath = base_path('stubs/database.seeders.user.stub');
        $targetPath = database_path('seeders/UserSeeder.php');

        if ($this->files->missing($stubPath)) {
            throw new FileNotFoundException("Stub file not found at {$stubPath}");
        }

        if ($this->files->exists($targetPath)) {
            error('UserSeeder already exists, skipping creation.');

            return;
        }

        $this->files->copy($stubPath, $targetPath);
    }

    private function updateDatabaseSeederFile(): void
    {
        $this->modifyFile(database_path(path: 'seeders/DatabaseSeeder.php'), function (string $content): string {
            // Remove any existing use statements except WithoutModelEvents and Seeder
            $content = preg_replace(
                pattern: "/use (?!Illuminate\\\\Database\\\\Console\\\\Seeds\\\\WithoutModelEvents|Illuminate\\\\Database\\\\Seeder)[^;]+;\n/",
                replacement: '',
                subject: $content
            );

            // Replace run method content to call UserSeeder
            $content = preg_replace(
                pattern: "/public function run\(\): void\n {4}\{[^}]*}/s",
                replacement: "public function run(): void\n    {\n        \$this->call([\n            UserSeeder::class,\n            // Add other seeders here if needed\n        ]);\n    }",
                subject: $content
            );

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
