@if (config('services.authentik.enabled'))
    <div class="mt-6">
        <div class="relative">
            <div class="absolute inset-0 flex items-center" aria-hidden="true">
                <div class="w-full border-t border-gray-200 dark:border-gray-700"></div>
            </div>
            <div class="relative flex justify-center">
                <span class="bg-white px-3 text-sm text-gray-500 dark:bg-gray-900 dark:text-gray-400">ou</span>
            </div>
        </div>

        <a
            href="{{ route('auth.sso.redirect') }}"
            class="mt-6 flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-950 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800"
        >
            Connexion SSO
        </a>
    </div>
@endif
