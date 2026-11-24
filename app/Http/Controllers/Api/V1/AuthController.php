<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        protected TokenService $tokenService
    ) {}

    /**
     * Authenticate user via credentials and issue token or establish SPA session.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:64',
        ]);

        $throttleKey = 'login:'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            AuditEvent::create([
                'subject_type' => 'auth',
                'subject_id' => $validated['email'],
                'action' => 'auth.login.throttled',
                'purpose' => 'Brute-force protection trigger',
                'metadata' => [
                    'ip' => $request->ip(),
                    'retry_after' => $seconds,
                ],
            ]);

            throw ApiException::rateLimited(
                detail: "Too many login attempts. Please retry in {$seconds} seconds.",
                retryAfter: $seconds
            );
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            AuditEvent::create([
                'subject_type' => 'auth',
                'subject_id' => $validated['email'],
                'action' => 'auth.login.failed',
                'purpose' => 'Failed credential submission',
                'metadata' => [
                    'ip' => $request->ip(),
                ],
            ]);

            throw ApiException::unauthorized('Invalid email or password.');
        }

        RateLimiter::clear($throttleKey);

        // Stateful SPA session authentication (Next.js web)
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            AuditEvent::create([
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'action' => 'auth.login.spa',
                'purpose' => 'SPA cookie session authenticated',
                'metadata' => ['ip' => $request->ip()],
            ]);

            return response()->json([
                'data' => [
                    'user' => [
                        'id' => (string) $user->id,
                        'name' => (string) $user->name,
                        'email' => $user->email,
                        'locale' => $user->locale ?? 'ar',
                    ],
                    'session_authenticated' => true,
                ],
            ]);
        }

        // Mobile token authentication (Flutter)
        $tokens = $this->tokenService->issueTokens($user, $validated['device_name'] ?? 'mobile');

        AuditEvent::create([
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'auth.login.token',
            'purpose' => 'Mobile token issued',
            'metadata' => [
                'device_name' => $validated['device_name'] ?? 'mobile',
                'ip' => $request->ip(),
            ],
        ]);

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * Register a new user and issue authentication tokens.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'locale' => 'nullable|string|in:ar,en',
            'device_name' => 'nullable|string|max:64',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'locale' => $validated['locale'] ?? 'ar',
            'country' => 'EG',
            'timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);

        AuditEvent::create([
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'auth.register.success',
            'purpose' => 'User account registered',
            'metadata' => ['ip' => $request->ip()],
        ]);

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json([
                'data' => [
                    'user' => [
                        'id' => (string) $user->id,
                        'name' => (string) $user->name,
                        'email' => $user->email,
                        'locale' => $user->locale ?? 'ar',
                    ],
                    'session_authenticated' => true,
                ],
            ], 201);
        }

        $tokens = $this->tokenService->issueTokens($user, $validated['device_name'] ?? 'mobile');

        return response()->json([
            'data' => $tokens,
        ], 201);
    }

    /**
     * Rotate refresh token and issue new token pair.
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => 'required|string',
            'device_name' => 'nullable|string|max:64',
        ]);

        $tokens = $this->tokenService->rotateRefreshToken(
            $validated['refresh_token'],
            $validated['device_name'] ?? 'mobile'
        );

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * Revoke current device token family or destroy web session.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('all_devices') && $user) {
            $this->tokenService->revokeAllTokens($user);
        } else {
            $this->tokenService->revokeCurrentDeviceTokens($request);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user) {
            AuditEvent::create([
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'action' => 'auth.logout',
                'purpose' => 'Session/token terminated',
                'metadata' => [
                    'all_devices' => $request->boolean('all_devices'),
                    'ip' => $request->ip(),
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Return authenticated user profile compliant with OpenAPI /api/v1/me UserProfile schema.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => $user->email,
                'locale' => in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar',
                'timezone' => (string) ($user->timezone ?? 'Africa/Cairo'),
                'roles' => is_array($user->roles ?? null) ? $user->roles : ['student'],
                'verification_status' => $user->status === 'active' ? 'verified' : ((string) ($user->status ?? 'pending')),
            ],
        ]);
    }
}
