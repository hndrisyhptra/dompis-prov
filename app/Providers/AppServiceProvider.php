<?php

namespace App\Providers;

use App\Console\LocalServeCommand;
use App\Contracts\GoogleSheetGateway;
use App\Contracts\TelegramGateway;
use App\Enums\Role;
use App\Models\User;
use App\Services\Sheets\FakeGoogleSheetGateway;
use App\Services\Sheets\GoogleSheetsGateway;
use App\Services\Sheets\SheetException;
use App\Services\Telegram\HttpTelegramGateway;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TelegramGateway::class, HttpTelegramGateway::class);
        $this->app->extend(ServeCommand::class, fn (ServeCommand $command) => new LocalServeCommand);
        $this->app->bind(GoogleSheetGateway::class, function () {
            if (config('olo.sheets.driver') === 'fake') {
                if (app()->isProduction()) {
                    throw new SheetException('Driver fake dilarang di production.');
                }

                return new FakeGoogleSheetGateway;
            }
            if (config('olo.sheets.driver') !== 'google') {
                throw new SheetException('Driver Sheets tidak dikenal.');
            }

            return new GoogleSheetsGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('area-dashboard', fn (User $user) => $user->active && $user->role->areaScope());
        Gate::define('revenue-overview', fn (User $user) => $user->active && $user->role !== Role::Bot);
        Gate::define('material-reports', fn (User $user) => $user->active && $user->role !== Role::Bot);
        Gate::define('sync', fn (User $user) => $user->canSync());
        Gate::define('administer', fn (User $user) => $user->active && $user->role === Role::SuperAdmin);
        Gate::define('manage-master-data', fn (User $user) => $user->active && $user->role->managesMasterData());
        Gate::define('priority-orders', fn (User $user) => $user->active && $user->role->priorityOrders());
    }
}
