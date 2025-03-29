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

    /**
     * @return void
     */
    private function applySensiblePasswordDefaults(): void
    {
        Password::defaults(
            fn() => $this->app->isProduction()
                ? $this->passwordDefaults()
                : null
        );
    }

    /**
     * @return Password
     */
    private function passwordDefaults(): Password
    {
        return Password::min(size: 8)
            // ->letters()
            // ->mixedCase()
            // ->numbers()
            // ->symbols()
            ->uncompromised();
    }

    /**
     * @return void
     */
    private function configureExceptions(): void
    {
        RequestException::dontTruncate();
    }

    /**
     * @return void
     */
    protected function configureModels(): void
    {
        Model::shouldBeStrict(!app()->isProduction());

        Model::unguard();
    }

    /**
     * @return void
     */
    protected function ensureDatesAreImmutable(): void
    {
        Date::use(handler: CarbonImmutable::class);
    }

    /**
     * @return void
     */
    private function prohibitDestructiveCommandsFromRunning(): void
    {
        DB::prohibitDestructiveCommands(app()->isProduction());
    }
}
