<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class InstallAuthorizationCommand extends Command
{
    private const string USER_ROLE_ENUM_STUB = 'stubs/enums.user_role.stub';

    private const string ROLE_MODEL_STUB = 'stubs/models.role.stub';

    private const string ROLE_FACTORY_STUB = 'stubs/database.factories.role_factory.stub';

    private const string USER_SEEDER_STUB = 'stubs/database.seeders.user.stub';

    private const string DEFAULT_ROLES_MIGRATION_STUB = 'stubs/database.migrations.create_default_roles.stub';

    protected $signature = 'auth:install';

    protected $description = 'Install and configure role-based authorization with permissions';

    public function __construct(
        protected Filesystem $files
    ) {
        parent::__construct();
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
            warning('Authorization installation skipped.');

            return self::SUCCESS;
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

            return self::FAILURE;
        } catch (FileNotFoundException $e) {
            error(message: "File not found: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Exception $e) {
            error(message: "An error occurred: {$e->getMessage()}");

            return self::FAILURE;
        }

        info(message: 'Authorization setup completed.');

        return self::SUCCESS;
    }

    private function installAuthorizationPackage(): void
    {
        $this->runProcess(
            command: 'composer require directorytree/authorization'
        );
    }

    /**
     * Run a shell command and throw an exception if it fails.
     *
     * @throws ProcessFailedException
     */
    private function runProcess(string|array $command): void
    {
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
        info('Running migrations');

        $this->runProcess(
            command: 'php artisan migrate --force --no-interaction'
        );
    }

    /**
     * Create the UserRole enum from a stub.
     */
    private function createUserRoleEnum(): void
    {
        info('Creating UserRole Enum');

        $this->copyStub(
            stubRelativePath: self::USER_ROLE_ENUM_STUB,
            targetPath: app_path(path: 'Enums/UserRole.php'),
            existsMessage: 'UserRole Enum already exists, skipping creation.'
        );
    }

    /**
     * Copy a stub file to a target location if it doesn't already exist.
     */
    private function copyStub(string $stubRelativePath, string $targetPath, ?string $existsMessage = null): void
    {
        $stubPath = base_path($stubRelativePath);

        throw_if(
            condition: $this->files->missing($stubPath),
            exception: new FileNotFoundException("Target file not found at {$stubPath}")
        );

        if ($this->files->exists($targetPath)) {
            if ($existsMessage) {
                error($existsMessage);
            }

            return;
        }

        $this->files->ensureDirectoryExists(dirname($targetPath));

        $this->files->copy($stubPath, $targetPath);
    }

    private function addAuthorizableTraitToUserModel(): void
    {
        $this->modifyFile(app_path('Models/User.php'), function (string $content): string {
            $useStatement = 'use DirectoryTree\Authorization\Traits\Authorizable;';
            
            if (Str::doesntContain($content, $useStatement)) {
                // Attempt to add the use statement after the namespace
                $content = Str::replaceFirst(
                    search: "namespace App\Models;\n",
                    replace: "namespace App\Models;\n\n" . $useStatement,
                    subject: $content
                );
            }

            $traitToAdd = 'Authorizable, ';
            $traitListSearch = 'use HasFactory, Notifiable'; // Common default
            $traitListSearchWithExisting = 'use Authorizable, HasFactory, Notifiable'; // Check if already added

            if (Str::contains($content, $traitListSearch) && Str::doesntContain($content, $traitListSearchWithExisting)) {
                 $content = Str::replaceFirst(
                    search: $traitListSearch,
                    replace: 'use ' . $traitToAdd . 'HasFactory, Notifiable',
                    subject: $content
                );
            }

            return $content;
        });
    }

    /**
     * Modify the content of a file using a callback.
     *
     * @throws FileNotFoundException
     */
    private function modifyFile(string $path, callable $callback): void
    {
        throw_if(
            condition: $this->files->missing($path),
            exception: new FileNotFoundException("File does not exist at path {$path}.")
        );

        $content = $this->files->get(path: $path);
        $newContent = $callback($content);

        $this->files->put(path: $path, contents: $newContent);
    }

    /**
     * @throws FileNotFoundException
     */
    private function setupRoleModelAndFactory(): void
    {
        info('Setting up Role model and factory');

        $this->copyStub(
            stubRelativePath: self::ROLE_MODEL_STUB,
            targetPath: app_path('Models/Role.php'),
            existsMessage: 'Role model already exists, skipping creation.'
        );

        $this->createRoleFactory();
    }

    private function createRoleFactory(): void
    {
        $stubPath = base_path(path: self::ROLE_FACTORY_STUB);
        $targetPath = database_path(path: 'factories/RoleFactory.php');

        throw_if(
            condition: $this->files->missing($stubPath),
            exception: new FileNotFoundException(message: "Stub file not found at {$stubPath}")
        );

        if ($this->files->exists($targetPath)) {
            return;
        }

        $this->files->copy($stubPath, $targetPath);
    }

    private function createMigrationForDefaultRoles(): void
    {
        info('Creating migration for default roles');

        // Generate a timestamped filename similar to how Laravel does it
        $timestamp = Date::now()->format('Y_m_d_His');
        $migrationName = "{$timestamp}_create_default_roles.php";
        $targetPath = database_path("migrations/{$migrationName}");

        $this->copyStub(
            stubRelativePath: self::DEFAULT_ROLES_MIGRATION_STUB,
            targetPath: $targetPath,
            existsMessage: 'Default roles migration seems to already exist, skipping creation.'
        );
    }

    private function setupCustomRoleModelInProvider(): void
    {
        info('Configuring AppServiceProvider for custom Role model');

        $this->modifyFile(app_path(path: 'Providers/AppServiceProvider.php'), callback: function (string $content): string {
            $namespaceLine = "namespace App\Providers;";
            $useStatementsToAdd = [];

            // Check for required use statements
            if (Str::doesntContain(haystack: $content, needles: 'use App\Models\Role;')) {
                $useStatementsToAdd[] = 'use App\Models\Role;';
            }

            if (Str::doesntContain(haystack: $content, needles: 'use DirectoryTree\Authorization\Authorization;')) {
                $useStatementsToAdd[] = 'use DirectoryTree\Authorization\Authorization;';
            }

            // Add missing use statements after the namespace declaration
            if (! empty($useStatementsToAdd)) {
                // Find the first existing 'use' statement to insert before it, or insert after namespace if none exist
                if (preg_match(pattern: '/^use\s+.+;/m', subject: $content, matches: $matches, flags: PREG_OFFSET_CAPTURE)) {
                    $content = Str::substrReplace(
                        string: $content,
                        replace: implode(separator: "\n", array: $useStatementsToAdd)."\n",
                        offset: $matches[0][1],
                        length: 0,
                    );
                } else {
                    // Insert after namespace if no use statements exist
                    $content = Str::replaceFirst(
                        search: $namespaceLine,
                        replace: $namespaceLine."\n\n".implode(separator: "\n", array: $useStatementsToAdd),
                        subject: $content
                    );
                }
            }

            // Ensure the Authorization configuration exists within the boot method
            $bootMethodSignature = 'public function boot(): void';
            $configLine = 'Authorization::useRoleModel(roleModel: Role::class);';
            $indentedConfigLine = '        '.$configLine; // Assuming 8 spaces (2 tabs) indentation

            // Check if the line already exists (allowing for slight variations in whitespace)
            if (! Str::contains(
                haystack: Str::replaceMatches(pattern: '/\s+/', replace: '', subject: $content),
                needles: Str::replaceMatches(pattern: '/\s+/', replace: '', subject: $configLine))
            ) {
                // Find the boot method's opening brace
                $bootMethodPosition = Str::position($content, $bootMethodSignature);
                if ($bootMethodPosition !== false) {
                    $openingBracePosition = Str::position(haystack: $content, needle: '{', offset: $bootMethodPosition);

                    if ($openingBracePosition !== false) {
                        // Insert the config line after the opening brace with a newline and indentation
                        $content = Str::substrReplace(
                            string: $content,
                            replace: "\n".$indentedConfigLine,
                            offset: $openingBracePosition + 1,
                            length: 0,
                        );
                    } else {
                        warning('Could not find opening brace for boot() method in AppServiceProvider.');
                    }
                } else {
                    warning('Could not find boot() method signature in AppServiceProvider.');
                }
            }

            return $content;
        });
    }

    private function setupUserSeeder(): void
    {
        info('Setting up UserSeeder');

        $this->copyStub(
            stubRelativePath: self::USER_SEEDER_STUB,
            targetPath: database_path('seeders/UserSeeder.php'),
            existsMessage: 'UserSeeder already exists, skipping creation.'
        );
    }

    private function runSeedersIfNeeded(): void
    {
        if (confirm(label: 'Do you want to run the database seeders?')) {
            $this->runProcess(
                command: 'php artisan db:seed'
            );
        } else {
            warning(message: 'You can run the seeders later by executing `php artisan db:seed`');
        }
    }
}
