@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Security Best Practices

## Control Mass Assignment

Define ___SINGLE_BACKTICK___$fillable___SINGLE_BACKTICK___ when a model is populated from request-derived arrays, or deliberately guard attributes by another consistent model convention. Laravel models guard all attributes by default; ___SINGLE_BACKTICK___$guarded = []___SINGLE_BACKTICK___ opts out of that protection.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Do not pass untrusted request data to a model with ___SINGLE_BACKTICK___$guarded = []___SINGLE_BACKTICK___. Mass-assignment protection controls which attributes ___SINGLE_BACKTICK___create()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___fill()___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___update()___SINGLE_BACKTICK___ may set; it does not validate values or authorize the operation.

## Authorize Protected Actions

Use policies, gates, or form request authorization for actions that depend on the current user's permissions. Authentication alone does not establish permission, and validation is not authorization.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
public function update(UpdatePostRequest $request, Post $post): RedirectResponse
{
    Gate::authorize('update', $post);

    $post->update($request->validated());

    return redirect()->route('posts.show', $post);
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Authorization may instead live in the form request:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
public function authorize(): bool
{
    return $this->user()?->can('update', $this->route('post')) ?? false;
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Public actions intentionally available to everyone do not need a redundant authorization check.

## Bind Query Parameters

Use Eloquent, the query builder, or explicit bindings instead of interpolating untrusted values into Structured Query Language (SQL). Bindings protect values, not identifiers such as column names or sort directions; map user-selected identifiers to an allow-list.

Incorrect:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
DB::select("SELECT * FROM users WHERE name = '{$request->name}'");
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Correct:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
User::where('name', $request->name)->get();
User::whereRaw('LOWER(name) = ?', [$request->string('name')->lower()->toString()])->get();
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Escape Output in Its Context

Blade's ___SINGLE_BACKTICK______BLADE_ESCAPE_2______SINGLE_BACKTICK___ syntax HTML-escapes output. Use ___SINGLE_BACKTICK______BLADE_ESCAPE_0______SINGLE_BACKTICK___ only for content that has been sanitized for the exact HTML context in which it is rendered. Escaping rules differ for HTML, URLs, JavaScript, and Cascading Style Sheets.

Incorrect for untrusted content:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___blade
___BLADE_ESCAPE_1___
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Correct:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___blade
___BLADE_ESCAPE_3___
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Apply Cross-Site Request Forgery Protection

Include ___SINGLE_BACKTICK______BLADE_ESCAPE_4___csrf___SINGLE_BACKTICK___ in state-changing Blade forms handled by Laravel's ___SINGLE_BACKTICK___web___SINGLE_BACKTICK___ middleware. Routes intentionally excluded from cross-site request forgery (CSRF) verification, such as validated third-party webhooks, need their own authenticity check.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___blade
<form method="POST" action="/posts">
    ___BLADE_ESCAPE_5___csrf
    <input type="text" name="title">
</form>
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Inertia applications commonly use Axios, which returns the encrypted ___SINGLE_BACKTICK___XSRF-TOKEN___SINGLE_BACKTICK___ cookie in the ___SINGLE_BACKTICK___X-XSRF-TOKEN___SINGLE_BACKTICK___ header. Confirm equivalent configuration when using another HTTP client. Do not disable CSRF protection merely to fix a token mismatch.

## Rate Limit Sensitive Endpoints

Apply suitable rate limits to login attempts, password recovery, verification messages, and expensive or abuse-prone application programming interface (API) routes. Choose the limiter key deliberately; an Internet Protocol (IP) address alone can unfairly group users behind a shared network, while an account identifier alone can enable targeted denial of service.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by(Str::transliterate(
        Str::lower($request->string('email')).'|'.$request->ip()
    ));
});

Route::post('/login', LoginController::class)->middleware('throttle:login');
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Rate limiting reduces abuse; it does not replace authentication, authorization, or upstream denial-of-service protection.

## Validate and Store Uploads Safely

Validate expected content type, dimensions where relevant, and size. Laravel's ___SINGLE_BACKTICK___mimes___SINGLE_BACKTICK___ rule reads the file contents and guesses a Multipurpose Internet Mail Extensions (MIME) type corresponding to the listed extensions; it does not validate the user-assigned filename extension. The ___SINGLE_BACKTICK___extensions___SINGLE_BACKTICK___ rule checks that extension and should not be used by itself.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
public function rules(): array
{
    return [
        'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ];
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Use Laravel's storage methods to generate a filename, and store untrusted files outside a publicly executable location. Public files can require additional controls, such as image re-encoding, content-disposition headers, and explicit blocking of active formats.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
$path = $request->file('avatar')->store('avatars');
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Keep Secrets Out of Application Code

Do not commit populated environment files or hard-code credentials. Read environment variables in configuration files, then use ___SINGLE_BACKTICK___config()___SINGLE_BACKTICK___ in application code so configuration caching works correctly. See the configuration rules for encrypted environment files and external secret stores.

## Audit Dependencies

Run ___SINGLE_BACKTICK___{{ $assist->composerCommand('audit') }}___SINGLE_BACKTICK___ regularly and in continuous integration. Review findings for exploitability and update or mitigate affected packages promptly.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->composerCommand('audit') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Encrypt Sensitive Attributes When Appropriate

Use an ___SINGLE_BACKTICK___encrypted___SINGLE_BACKTICK___ cast for sensitive values that must be recoverable, and use ___SINGLE_BACKTICK___$hidden___SINGLE_BACKTICK___ to omit them from array and JavaScript Object Notation (JSON) serialization. Hidden attributes remain accessible in PHP, and encryption does not replace access control. Encrypted values cannot be meaningfully queried and should use a ___SINGLE_BACKTICK___TEXT___SINGLE_BACKTICK___ or larger column because ciphertext length is variable.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
class Integration extends Model
{
    protected $hidden = ['api_key', 'api_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
