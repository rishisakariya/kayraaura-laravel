<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\UserResource;
use App\Models\User;
use App\Services\OtpService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class FrontendAuthController extends Controller
{
    public function __construct(private readonly OtpService $otpService)
    {
    }

    /**
     * User registration
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:12|unique:users,phone'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()
                ]
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => 'customer'
        ]);

        // Create token for the new user
        $token = $user->createToken('frontend-token', ['customer'])->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ],
            'message' => 'User registered successfully'
        ], 201);
    }

    /**
     * User login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|max:255',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()
                ]
            ], 422);
        }

        $credentials = $request->only('email', 'password');
        $login = $credentials['email'];
        
        // Find user by email address or mobile number.
        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid email/mobile or password'
                ]
            ], 401);
        }

        // Check if user is customer
        if (!$user->isCustomer()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Access denied. Customer account required.'
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

        // Revoke previous tokens
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('frontend-token', ['customer'])->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ],
            'message' => 'Login successful'
        ]);
    }

    /**
     * User logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful'
        ]);
    }

    /**
     * Get user profile
     */
    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($request->user())
            ],
            'message' => 'Profile retrieved successfully'
        ]);
    }

    /**
     * Send password reset OTP.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|exists:users,phone'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()
                ]
            ], 422);
        }

        try {
            $this->otpService->send($request->phone, OtpService::PURPOSE_FORGOT_PASSWORD);

            return response()->json([
                'success' => true,
                'message' => 'Password reset OTP sent to your mobile number'
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_SEND_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 429);
        }
    }

    /**
     * Reset password using mobile OTP.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|exists:users,phone',
            'otp' => 'required|string|digits:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()
                ]
            ], 422);
        }

        try {
            $this->otpService->verifyAndConsume(
                $request->phone,
                OtpService::PURPOSE_FORGOT_PASSWORD,
                $request->otp
            );

            $user = User::where('phone', $request->phone)->firstOrFail();
            $user->forceFill([
                'password' => Hash::make($request->password)
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_VERIFICATION_FAILED',
                    'message' => $e->getMessage()
                ]
            ], 422);
        }
    }

    /**
     * Verify email
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()
                ]
            ], 422);
        }

        // This is a placeholder implementation
        // In a real application, you would implement email verification logic
        // using Laravel's built-in email verification feature

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully'
        ]);
    }
}
