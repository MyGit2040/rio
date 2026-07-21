<?php

namespace App\Providers;

use App\Support\LocalTime;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

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
        // @lt($datetime, 'format') — render a stored UTC timestamp in the
        // workspace's own timezone (Settings → General → Timezone).
        Blade::directive('lt', fn ($expr) => "<?php echo \\App\\Support\\LocalTime::format($expr); ?>");
    }
}
