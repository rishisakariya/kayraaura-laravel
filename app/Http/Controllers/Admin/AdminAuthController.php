<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    /**
     * Admin login
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid email or password'
                ]
            ], 401);
        }

        // Check if user is admin
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Access denied. Admin privileges required.'
                ]
            ], 403);
        }

        // Check if user is banned
        if ($user->isBanned()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACCOUNT_BANNED',
                    'message' => 'Account is banned. Please contact administrator.'
                ]
            ], 403);
        }

        // Allow concurrent sessions (prod + QA, multiple tabs). Only prune very old tokens.
        $this->pruneStaleAdminTokens($user);

        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => new AdminResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ],
            'message' => 'Admin login successful'
        ]);
    }

    /**
     * Admin logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Admin logout successful'
        ]);
    }

    /**
     * Get admin profile
     */
    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'admin' => new AdminResource($request->user())
            ],
            'message' => 'Admin profile retrieved successfully'
        ]);
    }

    /**
     * Update admin profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20'
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => new AdminResource($user)
            ],
            'message' => 'Admin profile updated successfully'
        ]);
    }

    /**
     * Refresh admin token
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();

        // Issue a new token without revoking the current one so other tabs/environments
        // (prod + QA) keep working until they pick up the new token.
        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

        $this->pruneStaleAdminTokens($user);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer'
            ],
            'message' => 'Token refreshed successfully'
        ]);
    }

    /**
     * Remove admin tokens older than 30 days. Logout still revokes the active token.
     */
    private function pruneStaleAdminTokens(User $user): void
    {
        $user->tokens()
            ->where('name', 'admin-token')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }
}
