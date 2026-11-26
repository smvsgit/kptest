<?php

namespace App\Providers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\LocaleResolver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One answer per request to "what language is this?", so the branding
        // row is read once however many callers ask.
        $this->app->singleton(LocaleResolver::class);

        // The same idea for the whole of the administrator's configuration. A
        // scoped binding lives for one request, one command or one queued job,
        // so a worker that runs for days still sees a setting changed in
        // Settings on its next job. (Review F10.)
        $this->app->scoped(SystemSetting::MAP, fn () => SystemSetting::freshMap());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This portal has one canonical production URL. In addition to trusted
        // proxy handling, pin absolute URL generation to APP_URL so guest
        // redirects such as route('login') can never fall back to http.
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($this->app->environment('production') && str_starts_with($appUrl, 'https://')) {
            URL::useOrigin($appUrl);
            URL::forceHttps();
        }

        Event::listen(Login::class, function (Login $event) {
            app(AuditService::class)->logFromRequest(request(), 'auth.login', $event->user, 'User logged in.');
        });
        Event::listen(Logout::class, function (Logout $event) {
            app(AuditService::class)->logFromRequest(request(), 'auth.logout', $event->user, 'User logged out.');
        });
        Event::listen(Failed::class, function (Failed $event) {
            app(AuditService::class)->logFromRequest(request(), 'auth.failed', $event->user, 'Failed login attempt.', [
                'email' => $event->credentials['email'] ?? null,
            ]);
        });
    }
}
