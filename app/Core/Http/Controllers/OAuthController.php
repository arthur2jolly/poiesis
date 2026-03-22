<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\OAuthAccessToken;
use App\Core\Models\OAuthAuthorizationCode;
use App\Core\Models\OAuthClient;
use App\Core\Models\OAuthRefreshToken;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/** @phpstan-type ValidatedData array<string, mixed> */
class OAuthController extends Controller
{
    // POIESIS-38: RFC 8414 metadata
    public function metadata(Request $request): JsonResponse
    {
        $baseUrl = config('app.url');

        return response()->json([
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl.'/oauth/authorize',
            'token_endpoint' => $baseUrl.'/oauth/token',
            'registration_endpoint' => $baseUrl.'/oauth/register',
            'revocation_endpoint' => $baseUrl.'/oauth/revoke',
            'scopes_supported' => config('core.oauth_scopes'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    // POIESIS-39: Dynamic client registration (RFC 7591)
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'redirect_uris' => 'required|array|min:1',
            'redirect_uris.*' => 'required|url|max:2048',
            'grant_types' => 'sometimes|array',
            'grant_types.*' => 'string',
            'scope' => 'sometimes|nullable|string',
            'tenant_slug' => 'required|string|exists:tenants,slug',
        ]);

        $tenant = Tenant::where('slug', $validated['tenant_slug'])->firstOrFail();

        $clientId = (string) Str::uuid7();
        $grantTypes = $validated['grant_types'] ?? ['authorization_code'];
        $scopes = isset($validated['scope'])
            ? explode(' ', $validated['scope'])
            : null;

        $client = OAuthClient::create([
            'name' => $validated['client_name'],
            'client_id' => $clientId,
            'client_secret' => null,
            'redirect_uris' => $validated['redirect_uris'],
            'grant_types' => $grantTypes,
            'scopes' => $scopes,
            'tenant_id' => $tenant->id,
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
        ], 201);
    }

    // POIESIS-40: Authorization endpoint (consent screen)
    public function authorize(Request $request): Response|JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string',
            'redirect_uri' => 'required|url|max:2048',
            'response_type' => 'required|in:code',
            'code_challenge' => 'required|string',
            'code_challenge_method' => 'required|in:S256',
            'scope' => 'sometimes|nullable|string',
            'state' => 'sometimes|nullable|string',
            'user_id' => 'sometimes|nullable|string',
        ]);

        $client = OAuthClient::where('client_id', $validated['client_id'])->first();

        if ($client === null) {
            return response()->json(['error' => 'invalid_client', 'message' => 'Unknown client.'], 400);
        }

        if (! in_array($validated['redirect_uri'], $client->redirect_uris, true)) {
            return response()->json(['error' => 'invalid_redirect_uri', 'message' => 'Redirect URI mismatch.'], 400);
        }

        $scopes = isset($validated['scope']) ? explode(' ', $validated['scope']) : [];
        $state = $validated['state'] ?? '';

        // POST = user decision
        if ($request->isMethod('POST')) {
            return $this->handleAuthorizationDecision($request, $client, $validated, $scopes, $state);
        }

        // GET = show consent screen
        return $this->renderConsentScreen($client, $validated, $scopes, $state);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $scopes
     */
    private function renderConsentScreen(
        OAuthClient $client,
        array $validated,
        array $scopes,
        string $state,
    ): Response {
        $scopeList = ! empty($scopes)
            ? '<ul>'.implode('', array_map(fn ($s) => "<li>{$s}</li>", $scopes)).'</ul>'
            : '<p>No specific scopes requested.</p>';

        $scopeValue = $validated['scope'] ?? '';
        $userIdValue = $validated['user_id'] ?? '';
        $clientName = e($client->name);
        $clientId = $validated['client_id'];
        $redirectUri = $validated['redirect_uri'];
        $codeChallenge = $validated['code_challenge'];
        $csrfToken = csrf_token();

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>Authorize {$clientName}</title>
        <style>body{font-family:system-ui;max-width:480px;margin:40px auto;padding:0 20px}
        h1{font-size:1.4rem}button{padding:10px 20px;margin:10px 5px;cursor:pointer;border:1px solid #ccc;border-radius:4px}
        .approve{background:#2563eb;color:#fff;border-color:#2563eb}.deny{background:#dc2626;color:#fff;border-color:#dc2626}</style>
        </head>
        <body>
        <h1>Authorize Application</h1>
        <p><strong>{$clientName}</strong> is requesting access to your account.</p>
        <p>Redirect URI: <code>{$redirectUri}</code></p>
        <h3>Requested Scopes</h3>
        {$scopeList}
        <form method="POST" action="/oauth/authorize">
        <input type="hidden" name="_token" value="{$csrfToken}">
        <input type="hidden" name="client_id" value="{$clientId}">
        <input type="hidden" name="redirect_uri" value="{$redirectUri}">
        <input type="hidden" name="response_type" value="code">
        <input type="hidden" name="code_challenge" value="{$codeChallenge}">
        <input type="hidden" name="code_challenge_method" value="S256">
        <input type="hidden" name="scope" value="{$scopeValue}">
        <input type="hidden" name="state" value="{$state}">
        <input type="hidden" name="user_id" value="{$userIdValue}">
        <button type="submit" name="decision" value="approve" class="approve">Approve</button>
        <button type="submit" name="decision" value="deny" class="deny">Deny</button>
        </form>
        </body></html>
        HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $scopes
     */
    private function handleAuthorizationDecision(
        Request $request,
        OAuthClient $client,
        array $validated,
        array $scopes,
        string $state,
    ): RedirectResponse {
        $redirectUri = $validated['redirect_uri'];

        if ($request->input('decision') !== 'approve') {
            $query = http_build_query(array_filter([
                'error' => 'access_denied',
                'state' => $state,
            ]));

            return redirect($redirectUri.'?'.$query);
        }

        // Resolve user
        $userId = $request->input('user_id');
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            return redirect($redirectUri.'?'.http_build_query(array_filter([
                'error' => 'invalid_request',
                'error_description' => 'A valid user_id is required.',
                'state' => $state,
            ])));
        }

        // Generate authorization code
        $rawCode = bin2hex(random_bytes(32));
        $hashedCode = hash('sha256', $rawCode);

        OAuthAuthorizationCode::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'tenant_id' => $client->tenant_id,
            'code' => $hashedCode,
            'redirect_uri' => $validated['redirect_uri'],
            'scopes' => ! empty($scopes) ? $scopes : null,
            'code_challenge' => $validated['code_challenge'],
            'code_challenge_method' => $validated['code_challenge_method'],
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $query = http_build_query(array_filter([
            'code' => $rawCode,
            'state' => $state,
        ]));

        return redirect($redirectUri.'?'.$query);
    }

    // POIESIS-41: Token endpoint (code exchange + refresh)
    public function token(Request $request): JsonResponse
    {
        $grantType = $request->input('grant_type');

        return match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode($request),
            'refresh_token' => $this->refreshAccessToken($request),
            default => response()->json([
                'error' => 'unsupported_grant_type',
                'message' => 'Grant type must be authorization_code or refresh_token.',
            ], 400),
        };
    }

    private function exchangeAuthorizationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string',
            'code' => 'required|string',
            'redirect_uri' => 'required|string',
            'code_verifier' => 'required|string',
        ]);

        $client = OAuthClient::where('client_id', $validated['client_id'])->first();

        if ($client === null) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        $hashedCode = hash('sha256', $validated['code']);
        $authCode = OAuthAuthorizationCode::where('code', $hashedCode)
            ->where('oauth_client_id', $client->id)
            ->first();

        if ($authCode === null) {
            return response()->json(['error' => 'invalid_grant', 'message' => 'Authorization code not found.'], 400);
        }

        if ($authCode->isExpired()) {
            $authCode->delete();

            return response()->json(['error' => 'invalid_grant', 'message' => 'Authorization code has expired.'], 400);
        }

        if ($authCode->redirect_uri !== $validated['redirect_uri']) {
            return response()->json(['error' => 'invalid_grant', 'message' => 'Redirect URI mismatch.'], 400);
        }

        // PKCE S256 verification
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $validated['code_verifier'], true)), '+/', '-_'), '=');

        if (! hash_equals($authCode->code_challenge, $expectedChallenge)) {
            return response()->json(['error' => 'invalid_grant', 'message' => 'PKCE verification failed.'], 400);
        }

        // Generate access token
        $rawAccessToken = 'aa-'.bin2hex(random_bytes(20));
        $accessTokenTtl = (int) config('core.oauth_access_token_ttl', 60);

        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $authCode->user_id,
            'tenant_id' => $client->tenant_id,
            'token' => hash('sha256', $rawAccessToken),
            'scopes' => $authCode->scopes,
            'expires_at' => Carbon::now()->addMinutes($accessTokenTtl),
        ]);

        // Generate refresh token
        $rawRefreshToken = 'rt-'.bin2hex(random_bytes(20));
        $refreshTokenTtl = (int) config('core.oauth_refresh_token_ttl', 43200);

        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefreshToken),
            'expires_at' => Carbon::now()->addMinutes($refreshTokenTtl),
        ]);

        // Delete used authorization code
        $authCode->delete();

        return response()->json([
            'access_token' => $rawAccessToken,
            'token_type' => 'bearer',
            'expires_in' => $accessTokenTtl * 60,
            'refresh_token' => $rawRefreshToken,
        ]);
    }

    private function refreshAccessToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string',
            'refresh_token' => 'required|string',
        ]);

        $client = OAuthClient::where('client_id', $validated['client_id'])->first();

        if ($client === null) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        $hashedRefresh = hash('sha256', $validated['refresh_token']);
        $refreshToken = OAuthRefreshToken::where('token', $hashedRefresh)->first();

        if ($refreshToken === null || $refreshToken->isRevoked() || $refreshToken->isExpired()) {
            return response()->json(['error' => 'invalid_grant', 'message' => 'Refresh token is invalid, revoked, or expired.'], 400);
        }

        $oldAccessToken = $refreshToken->accessToken;

        if ($oldAccessToken === null || $oldAccessToken->oauth_client_id !== $client->id) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        // Revoke old refresh token
        $refreshToken->update(['revoked' => true]);

        // Delete old access token
        $oldAccessToken->delete();

        // Issue new access token
        $rawAccessToken = 'aa-'.bin2hex(random_bytes(20));
        $accessTokenTtl = (int) config('core.oauth_access_token_ttl', 60);

        $newAccessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $oldAccessToken->user_id,
            'tenant_id' => $client->tenant_id,
            'token' => hash('sha256', $rawAccessToken),
            'scopes' => $oldAccessToken->scopes,
            'expires_at' => Carbon::now()->addMinutes($accessTokenTtl),
        ]);

        // Issue new refresh token
        $rawRefreshToken = 'rt-'.bin2hex(random_bytes(20));
        $refreshTokenTtl = (int) config('core.oauth_refresh_token_ttl', 43200);

        OAuthRefreshToken::create([
            'access_token_id' => $newAccessToken->id,
            'token' => hash('sha256', $rawRefreshToken),
            'expires_at' => Carbon::now()->addMinutes($refreshTokenTtl),
        ]);

        return response()->json([
            'access_token' => $rawAccessToken,
            'token_type' => 'bearer',
            'expires_in' => $accessTokenTtl * 60,
            'refresh_token' => $rawRefreshToken,
        ]);
    }

    // POIESIS-42: Token revocation (RFC 7009)
    public function revoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'token_type_hint' => 'sometimes|nullable|in:access_token,refresh_token',
        ]);

        $hash = hash('sha256', $validated['token']);

        // Try access token first (or as hinted)
        $accessToken = OAuthAccessToken::where('token', $hash)->first();

        if ($accessToken !== null) {
            $accessToken->delete(); // Cascades to refresh tokens

            return response()->json([], 200);
        }

        // Try refresh token
        $refreshToken = OAuthRefreshToken::where('token', $hash)->first();

        if ($refreshToken !== null) {
            $refreshToken->update(['revoked' => true]);
        }

        // RFC 7009: always return 200
        return response()->json([], 200);
    }
}
