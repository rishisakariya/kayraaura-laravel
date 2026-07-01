<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\UserResource;
use App\Models\User;
use App\Models\UserAddress;
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
        $validated = $this->validateRegistrationFields($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $otpValidator = Validator::make($request->all(), [
            'otp' => 'required|string|digits:6',
        ]);

        if ($otpValidator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $otpValidator->errors(),
                ],
            ], 422);
        }

        $phone = $validated['phone'];

        try {
            $this->otpService->verifyAndConsume(
                $phone,
                OtpService::PURPOSE_REGISTER,
                $request->otp
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_VERIFICATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $phone,
            'gender' => $request->gender,
            'role' => 'customer',
            'is_verified' => true,
        ]);

        // Create token for the new user
        $token = $user->createToken('frontend-token', ['customer'])->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'requires_otp' => false,
                'step' => 'registered',
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
     * Update profile. If phone differs from current and no otp → sends OTP.
     * Call again with the same phone + otp to complete the update.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
            'gender' => 'sometimes|required|in:male,female',
            'phone' => 'sometimes|required|string|max:12',
            'otp' => 'nullable|string|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $updates = collect($request->only(['name', 'email', 'gender']))
            ->filter(fn ($value) => $value !== null)
            ->all();

        $phoneChanging = false;
        $newPhone = null;

        if ($request->filled('phone')) {
            $newPhone = $this->otpService->normalizeMobile($request->phone);

            if ($newPhone !== $user->phone) {
                $phoneChanging = true;

                if ($phoneTaken = $this->phoneTakenByAnotherUserResponse($newPhone, $user->id)) {
                    return $phoneTaken;
                }

                if (!$request->filled('otp')) {
                    try {
                        $this->otpService->send($newPhone, OtpService::PURPOSE_UPDATE_PHONE);
                    } catch (DomainException $e) {
                        return response()->json([
                            'success' => false,
                            'error' => [
                                'code' => 'OTP_SEND_FAILED',
                                'message' => $e->getMessage(),
                            ],
                        ], 429);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'OTP sent to your new mobile number',
                        'data' => [
                            'requires_otp' => true,
                            'step' => 'otp_required',
                        ],
                    ]);
                }

                try {
                    $this->otpService->verifyAndConsume(
                        $newPhone,
                        OtpService::PURPOSE_UPDATE_PHONE,
                        $request->otp
                    );
                } catch (DomainException $e) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'OTP_VERIFICATION_FAILED',
                            'message' => $e->getMessage(),
                        ],
                    ], 422);
                }

                $updates['phone'] = $newPhone;
                $updates['is_verified'] = true;
            }
        }

        if ($updates === []) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $phoneChanging
                        ? 'OTP is required to update your mobile number'
                        : 'No profile fields provided to update',
                ],
            ], 422);
        }

        $user->update($updates);

        return response()->json([
            'success' => true,
            'data' => [
                'requires_otp' => false,
                'step' => 'profile_updated',
                'user' => new UserResource($user->fresh()),
            ],
            'message' => 'Profile updated successfully',
        ]);
    }

    /**
     * Send registration OTP after validating all registration fields.
     */
    public function sendRegisterOtp(Request $request): JsonResponse
    {
        $validated = $this->validateRegistrationFields($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        try {
            $this->otpService->send($validated['phone'], OtpService::PURPOSE_REGISTER);

            return response()->json([
                'success' => true,
                'message' => 'Registration OTP sent to your mobile number',
                'data' => [
                    'requires_otp' => true,
                    'step' => 'otp_required',
                ],
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_SEND_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 429);
        }
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
     * Verify OTP without consuming it (register, forgot password, or COD order).
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $purposes = implode(',', [
            OtpService::PURPOSE_REGISTER,
            OtpService::PURPOSE_FORGOT_PASSWORD,
            OtpService::PURPOSE_COD_ORDER,
            OtpService::PURPOSE_UPDATE_PHONE,
        ]);

        $phonePurposes = implode(',', [
            OtpService::PURPOSE_REGISTER,
            OtpService::PURPOSE_FORGOT_PASSWORD,
            OtpService::PURPOSE_UPDATE_PHONE,
        ]);

        $validator = Validator::make($request->all(), [
            'purpose' => 'required|in:' . $purposes,
            'otp' => 'required|string|digits:6',
            'phone' => 'required_if:purpose,' . $phonePurposes . '|string',
            'address_id' => 'required_if:purpose,' . OtpService::PURPOSE_COD_ORDER . '|integer|exists:user_addresses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $purpose = $request->input('purpose');

        try {
            if (in_array($purpose, [OtpService::PURPOSE_COD_ORDER, OtpService::PURPOSE_UPDATE_PHONE], true) && !Auth::guard('sanctum')->check()) {
                throw new DomainException('Invalid or expired OTP');
            }

            $mobile = match ($purpose) {
                OtpService::PURPOSE_COD_ORDER => $this->resolveCodOtpMobile($request->integer('address_id')),
                default => $this->otpService->normalizeMobile($request->input('phone')),
            };

            if ($purpose === OtpService::PURPOSE_REGISTER && User::where('phone', $mobile)->exists()) {
                throw new DomainException('This phone number is already registered');
            }

            if ($purpose === OtpService::PURPOSE_FORGOT_PASSWORD && !User::where('phone', $mobile)->exists()) {
                throw new DomainException('No account found for this mobile number');
            }

            if ($purpose === OtpService::PURPOSE_UPDATE_PHONE) {
                $user = Auth::guard('sanctum')->user();

                if ($mobile === $user->phone) {
                    throw new DomainException('This is already your current mobile number');
                }

                if (User::where('phone', $mobile)->where('id', '!=', $user->id)->exists()) {
                    throw new DomainException('This phone number is already registered to another account');
                }
            }

            $this->otpService->verify($mobile, $purpose, $request->input('otp'));

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully',
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_VERIFICATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    private function resolveCodOtpMobile(int $addressId): string
    {
        $address = UserAddress::where('user_id', Auth::guard('sanctum')->id())->find($addressId);

        if (!$address) {
            throw new DomainException('Selected address was not found');
        }

        return $this->otpService->normalizeMobile($address->phone);
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

    private function phoneTakenByAnotherUserResponse(string $phone, int $exceptUserId): ?JsonResponse
    {
        if (!User::where('phone', $phone)->where('id', '!=', $exceptUserId)->exists()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'PHONE_ALREADY_REGISTERED',
                'message' => 'This phone number is already registered to another account',
                'details' => ['phone' => ['This phone number is already registered to another account.']],
            ],
        ], 422);
    }

    /**
     * @return array{name: string, email: string, password: string, phone: string, gender: string}|JsonResponse
     */
    private function validateRegistrationFields(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $this->registrationFieldRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $phone = $this->otpService->normalizeMobile($request->phone);

        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PHONE_ALREADY_REGISTERED',
                    'message' => 'This phone number is already registered',
                    'details' => ['phone' => ['This phone number is already registered.']],
                ],
            ], 422);
        }

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EMAIL_ALREADY_REGISTERED',
                    'message' => 'This email address is already registered',
                    'details' => ['email' => ['This email address is already registered.']],
                ],
            ], 422);
        }

        return [
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $phone,
            'gender' => $request->gender,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function registrationFieldRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:12',
            'gender' => 'required|in:male,female',
        ];
    }
}
