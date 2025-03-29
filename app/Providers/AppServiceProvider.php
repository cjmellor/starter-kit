<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applySensiblePasswordDefaults();
        $this->configureExceptions();
        $this->configureModels();
        $this->ensureDatesAreImmutable();
        $this->prohibitDestructiveCommandsFromRunning();
    }

    private function applySensiblePasswordDefaults(): void
    {
        Password::defaults(
            fn () => $this->app->isProduction()
                ? $this->passwordDefaults()
                : null
        );
    }

    private function passwordDefaults(): Password
    {
        return Password::min(size: 8)
            // ->letters()
            // ->mixedCase()
            // ->numbers()
            // ->symbols()
            ->uncompromised();
    }

    private function configureExceptions(): void
    {
        RequestException::dontTruncate();
    }

    protected function configureModels(): void
    {
        Model::shouldBeStrict(! app()->isProduction());

        Model::unguard();
    }

    protected function ensureDatesAreImmutable(): void
    {
        Date::use(handler: CarbonImmutable::class);
    }

    private function prohibitDestructiveCommandsFromRunning(): void
    {
        DB::prohibitDestructiveCommands(app()->isProduction());
    }
}
