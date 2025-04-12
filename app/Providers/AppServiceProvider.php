<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
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
        $this->configurePasswords();
        $this->configureExceptions();
        $this->configureModels();
        $this->configureDates();
        $this->configureCommands();
        $this->configureUrls();
    }

    private function configurePasswords(): void
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
        Model::automaticallyEagerLoadRelationships();

        // Might not need this as the above function will always load relationships.
        Model::shouldBeStrict(app()->isLocal());

        Model::unguard();
    }

    protected function configureDates(): void
    {
        Date::use(handler: CarbonImmutable::class);
    }

    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands(app()->isProduction());
    }

    private function configureUrls(): void
    {
        URL::forceScheme(scheme: 'https');

        URL::useOrigin(root: config(key: 'app.url'));
    }
}
