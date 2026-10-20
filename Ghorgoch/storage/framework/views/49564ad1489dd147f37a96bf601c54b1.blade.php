@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Configuration Best Practices

## Read Environment Variables in Configuration Files

Call ___SINGLE_BACKTICK___env()___SINGLE_BACKTICK___ only from configuration files. After configuration is cached, Laravel does not load the application's ___SINGLE_BACKTICK___.env___SINGLE_BACKTICK___ file, so application code should read configuration values through ___SINGLE_BACKTICK___config()___SINGLE_BACKTICK___.

Incorrect:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
$key = env('API_KEY');
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Correct:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// config/services.php
return [
    'key' => env('API_KEY'),
];

// Application code
$key = config('services.key');
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Protect Production Secrets

Do not commit plaintext production secrets. Laravel can encrypt an environment file so its encrypted form can be stored safely, while deployment platforms can supply secrets through their native secret stores.

Incorrect:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
# A plaintext .env file committed to the repository
STRIPE_SECRET=<your-stripe-secret>
AWS_SECRET_ACCESS_KEY=<your-aws-secret>
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Encrypted environment file:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('env:encrypt --env=production --readable') }}
{{ $assist->artisanCommand('env:decrypt --env=production') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

For hosted deployments, consider the platform's native secret store, such as AWS Secrets Manager or Vault, and inject secrets at runtime.

## Use ___SINGLE_BACKTICK___App::environment()___SINGLE_BACKTICK___ for Environment Checks

Incorrect:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
if (env('APP_ENV') === 'production') {
    // ...
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Correct:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
if (app()->isProduction()) {
    // ...
}

if (App::environment('production')) {
    // ...
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Name Repeated Domain Values

Use an enum or class constant when a domain value is repeated or represents a constrained set. A one-off string literal does not always need a named constant.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// Repeated literal
return $this->type === 'normal';

// Named domain value
return $this->type === self::TYPE_NORMAL;
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

If the application supports localization, put user-facing strings in language files and retrieve them with ___SINGLE_BACKTICK_____()___SINGLE_BACKTICK___. Simple literals are reasonable for applications that intentionally do not support multiple languages.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// In a localized application
return back()->with('message', __('app.article_added'));
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
