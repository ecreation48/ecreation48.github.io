<?php
namespace App\Providers; use Illuminate\Support\Facades\URL; use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider { public function register(): void {} public function boot(): void { $url = (string) config('app.url'); if ($url !== '') { URL::forceRootUrl($url); if (str_starts_with($url, 'https://')) { URL::forceScheme('https'); } } } }
