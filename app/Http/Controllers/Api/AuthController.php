<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\RecycleBinService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller {
    protected RecycleBinService $recycleBinService;

    public function __construct (RecycleBinService $recycleBinService) {
        $this->recycleBinService = $recycleBinService;
    }

    public function register (Request $request) {
        $validated = $request->validate([
            'usr_name' => 'required|string|max:80|unique:users,usr_name',
            'usr_email' => 'required|string|email|max:160|unique:users,usr_email',
            'usr_password' => 'required|string|min:8',
            'usr_phone' => 'nullable|string|max:30',
            'usr_dept' => 'nullable|string|max:40',
        ]);

        $user = User::create([
            'usr_name' => $validated['usr_name'],
            'usr_email' => $validated['usr_email'],
            // Pass plain password string; model cast automatically hashes it ONCE
            'usr_password' => $validated['usr_password'],
            'usr_phone' => $validated['usr_phone'] ?? '',
            'usr_dept' => $validated['usr_dept'] ?? 'General',
            'usr_status' => 1,
            'usr_must_change' => 1,
            'usr_created_by' => setCreatedUpdatedBy(), // Or auth()->id() if logged in
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }

    /**
     * Login user and issue Bearer token
     */
    /*public function login(Request $request)
    {
        $request->validate([
            'usr_email' => 'required',
            'usr_password' => 'required|string',
        ]);

        $user = User::query()->where('usr_email', $request->usr_email)->first();

        if (!$user || !Hash::check($request->usr_password, $user->usr_password)) {
            app(AuditService::class)->log(
                action: 'login_failed',
                module: 'security',
                record: !$user ? '-' : 'user:' . $user->usr_id,
                oldValues: [],
                newValues: [
                    'reason' => !$user ? 'unknown email' : 'The provided credentials do not match our records',
                ],
                description: 'Security: Login failed'
            );
            throw ValidationException::withMessages([
                'usr_email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if (!$user->usr_status) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $user->update([
            'usr_online' => true,
            'usr_last_seen' => now(),
        ]);
        $forcePasswordReset = false;
        if ($user->usr_must_change == 1) {
            $forcePasswordReset = true;
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        app(AuditService::class)->log(
            action: 'login',
            module: 'auth',
            record: 'user:' . $user->usr_id,
            oldValues: [],
            newValues: [],
            description: 'Signed in.'
        );
        app(AuditService::class)->log(
            action: 'login-success',
            module: 'security',
            record: 'user:' . $user->usr_id,
            oldValues: [],
            newValues: [],
            description: 'Security: Login success'
        );
        return response()->json([
            'message' => 'Logged in successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'forcePasswordReset' => $forcePasswordReset,
            'redirectPasswordResetApiUrl' => route('password.reset'),
            'permissions' => $user->getFlattenedPermissions(),
        ]);
    }*/

    public function login (Request $request) {
        $validated = $request->validate([
            'usr_email' => 'required|email',
            'usr_password' => 'required|string',
        ]);

        $email = Str::lower(trim($validated['usr_email']));
        $now = now();

        // Create a hashed throttle key; never store raw email here.
        $keyHash = hash('sha256', 'email:' . $email);

        $throttle = \DB::table('login_throttles')
            ->where('lgt_key_hash', $keyHash)
            ->first();

        // Reject attempts while the account is locked.
        if (
            $throttle?->lgt_blocked_until &&
            $now->lt(Carbon::parse($throttle->lgt_blocked_until))
        ) {
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again later.',
                'retry_after' => $now->diffInSeconds(
                    Carbon::parse($throttle->lgt_blocked_until)
                ),
            ], 429);
        }

        // Find the user.
        $user = User::query()
            ->where('usr_email', $email)
            ->first();

        // Verify credentials.
        if (
            !$user ||
            !Hash::check($validated['usr_password'], $user->usr_password)
        ) {
            // Record the failed login attempt.
            DB::transaction(function () use ($keyHash, $now) {
                $row = DB::table('login_throttles')
                    ->where('lgt_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                $windowExpired = !$row ||
                    $now->gte(
                        Carbon::parse($row->lgt_first_fail_at)->addMinutes(15)
                    );

                if ($windowExpired) {
                    DB::table('login_throttles')->updateOrInsert(
                        ['lgt_key_hash' => $keyHash],
                        [
                            'lgt_scope' => 'email',
                            'lgt_fails' => 1,
                            'lgt_first_fail_at' => $now,
                            'lgt_last_fail_at' => $now,
                            'lgt_blocked_until' => null,
                        ]
                    );

                    return;
                }

                $fails = $row->lgt_fails + 1;

                DB::table('login_throttles')
                    ->where('lgt_key_hash', $keyHash)
                    ->update([
                        'lgt_fails' => $fails,
                        'lgt_last_fail_at' => $now,
                        'lgt_blocked_until' => $fails >= MAX_LOGIN_ATTEMPTS
                            ? $now->copy()->addMinutes(PROFILE_BLOCK_DURATION)
                            : null,
                    ]);
            });

            // Fetch the latest throttle state after the transaction.
            $throttle = DB::table('login_throttles')
                ->where('lgt_key_hash', $keyHash)
                ->first();

            if ($throttle->lgt_fails == MAX_LOGIN_ATTEMPTS) {
                app(AuditService::class)->log(
                    action: 'login_excessive_attempts',
                    module: 'security',
                    record: !$user ? '-' : 'user:' . $user->usr_id,
                    oldValues: [],
                    newValues: [
                        'scope' => $throttle->lgt_scope,
                        'account' => substr($throttle->lgt_key_hash, -1, 16),
                        /*'failed_attempts' => $throttle->lgt_fails,
                        'scope' => $throttle->lgt_scope,*/
                    ],
                    description: 'Security: Login excessive attempts'
                );
            }

            // Existing audit log for every failed login.
            app(AuditService::class)->log(
                action: 'login_failed',
                module: 'security',
                record: !$user ? '-' : 'user:' . $user->usr_id,
                oldValues: [],
                newValues: [
                    'reason' => !$user
                        ? 'unknown email'
                        : 'The provided credentials do not match our records',
                    'account' => substr($throttle->lgt_key_hash, -1, 16),
                    /*'failed_attempts' => $throttle->lgt_fails,
                    'scope' => $throttle->lgt_scope,*/
                ],
                description: 'Security: Login failed'
            );

            // Additional audit log only when the account is blocked.
            if ($throttle->lgt_blocked_until !== null) {
                app(AuditService::class)->log(
                    action: 'login_throttled',
                    module: 'security',
                    record: !$user ? '-' : 'user:' . $user->usr_id,
                    oldValues: [],
                    newValues: [
                        'failed_attempts' => $throttle->lgt_fails,
                        'blocked_until' => $throttle->lgt_blocked_until,
                        'scope' => $throttle->lgt_scope,
                    ],
                    description: 'Security: Login blocked due to repeated failed attempts'
                );
            }

            // Return the same generic credentials error.
            throw ValidationException::withMessages([
                'usr_email' => [
                    'The provided credentials do not match our records.',
                ],
            ]);
        }

        // Do not allow inactive users to log in.
        if (!$user->usr_status) {
            return response()->json([
                'message' => 'Account is inactive.',
            ], 403);
        }

        // Clear failed attempts after successful authentication.
        \DB::table('login_throttles')->where('lgt_key_hash', $keyHash)->delete();

        // Your existing login logic.
        $user->update([
            'usr_online' => true,
            'usr_last_seen' => now(),
        ]);

        $forcePasswordReset = (bool)$user->usr_must_change;

        $token = $user->createToken('auth_token')->plainTextToken;

        app(AuditService::class)->log(
            action: 'login',
            module: 'auth',
            record: 'user: ' . $user->usr_id,
            oldValues: [],
            newValues: [],
            description: 'Signed in.'
        );

        app(AuditService::class)->log(
            action: 'login_success',
            module: 'security',
            record: 'user: ' . $user->usr_id,
            oldValues: [],
            newValues: [],
            description: 'Security: Login success'
        );

        return response()->json([
            'message' => 'Logged in successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'forcePasswordReset' => $forcePasswordReset,
            'redirectPasswordResetApiUrl' => route('password.reset'),
            'permissions' => $user->getFlattenedPermissions(),
        ]);
    }

    public function resetPassword (Request $request) {
        $validated = $request->validate([
            'usr_password' => 'required|string|min:8',
            'new_password' => 'required|string|confirmed|min:8',
        ]);

        // Access the current authenticated user via Sanctum middleware OR pass user ID
        $user = $request->has('usr_id') ? User::find($request->input('usr_id')) : $request->user();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Verify current password
        if (!Hash::check($validated['usr_password'], $user->usr_password)) {
            app(AuditService::class)->log(
                action: 'password_change_failed',
                module: 'security',
                record: !$user ? '-' : 'user:' . $user->usr_id,
                oldValues: [],
                newValues: [],
                description: 'Security: password change failed'
            );
            throw ValidationException::withMessages([
                'usr_password' => ['The provided current password does not match our records.'],
            ]);
        }

        // Update password AND reset the forced flag
        $user->update([
            'usr_password' => $validated['new_password'],
            'usr_must_change' => 0,
            'usr_online' => true,
            'usr_last_seen' => now(),
        ]);

        // Revoke all existing tokens (Security best practice)
        $user->tokens()->delete();

        // Generate fresh Bearer Token
        $token = $user->createToken('auth_token')->plainTextToken;

        app(AuditService::class)->log(
            action: 'password_changed',
            module: 'auth',
            record: 'user: ' . get_logged_in_user_id(),
            oldValues: [],
            newValues: [],
            description: 'Changed own password.'
        );

        app(AuditService::class)->log(
            action: 'password_change',
            module: 'security',
            record: 'user: ' . get_logged_in_user_id(),
            oldValues: [],
            newValues: [],
            description: 'Password change.'
        );

        return response()->json([
            'message' => 'Password reset successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->fresh(),
            'permissions' => $user->getFlattenedPermissions(),
        ]);
    }

    /**
     * Revoke token / Logout
     */
    public function logout (Request $request) {
        $request->user()->update(['usr_online' => false]);

        app(AuditService::class)->log(
            action: 'logout',
            module: 'auth',
            record: 'user:' . get_logged_in_user_id(),
            oldValues: [],
            newValues: [],
            description: 'Signed out.'
        );

        app(AuditService::class)->log(
            action: 'login_success',
            module: 'security',
            record: 'user:' . get_logged_in_user_id(),
            oldValues: [],
            newValues: [],
            description: 'Security: Logout'
        );
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user profile
     */


    public function createUser (Request $request) {
        logger($request->all());
        // Validate request data
        $validated = $request->validate([
            'usr_name' => [
                'required',
                'string',
                'max:80', Rule::unique('users', 'usr_name'),
            ],

            'usr_email' => [
                'required',
                'string',
                'email',
                'max:160', Rule::unique('users', 'usr_email'),
            ],

            'usr_phone' => 'nullable|string|max:30',
            'usr_role' => 'required|string|max:50',
            'usr_designation' => 'nullable|string|max:100',
            'usr_department' => 'nullable|string|max:40',
            'usr_city' => 'nullable|string|max:100',
            'usr_status' => 'required|in:0,1',

            // Optional password: update only when provided
            'usr_password' => 'nullable|string|min:10|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/',
        ]);

        $user = new User();
        // Update user details
        $user->usr_name = $validated['usr_name'];
        $user->usr_email = $validated['usr_email'];
        $user->usr_phone = $validated['usr_phone'] ?? '';
        $user->usr_role = $validated['usr_role'];
        $user->usr_designation = $validated['usr_designation'] ?? '';
        $user->usr_dept = $validated['usr_department'] ?? 'General';
        $user->usr_city = $validated['usr_city'] ?? '';
        $user->usr_status = (int)$validated['usr_status'];
        $user->usr_joined = $request->usr_joined_on;

        // Only update password when a new one is provided
        if (!empty($validated['usr_password'])) {
            $user->usr_password = $validated['usr_password'];
            $user->usr_must_change = 1;
        }

        // Update audit information if these columns exist
        $user->usr_created_by = auth()->id();
        $user->usr_created_on = now();
        $user->save();

        // Get the fields actually changed by this save
        $changes = $user->getChanges();

        // Exclude audit metadata from the business change history
        unset(
            $changes['usr_updated_by'],
            $changes['usr_updated_on'],
            $changes['usr_created_by'],
            $changes['usr_created_on'],
            $changes['usr_deleted_on']
        );

        $oldValues = [];
        $newValues = [];

        foreach ($changes as $field => $newValue) {
            $oldValue = $originalValues[$field] ?? null;

            // Record only genuine value changes
            if ($oldValue != $newValue) {
                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }
        }

        app(AuditService::class)->log(
            action: 'create',
            module: 'user',
            record: $user->usr_id,
            oldValues: [],
            newValues: $newValues,
            description: 'Created user ' . $user->usr_name
        );

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->fresh(),
        ], 200);
    }

    public function updateUser (Request $request, $id) {
        // Validate request data
        $validated = $request->validate([
            'usr_name' => [
                'required',
                'string',
                'max:80', Rule::unique('users', 'usr_name')->ignore($id, 'usr_id'),
            ],

            'usr_email' => [
                'required',
                'string',
                'email',
                'max:160', Rule::unique('users', 'usr_email')->ignore($id, 'usr_id'),
            ],

            'usr_phone' => 'nullable|string|max:30',
            'usr_role' => 'required|string|max:50',
            'usr_designation' => 'nullable|string|max:100',
            'usr_dept' => 'nullable|string|max:40',
            'usr_city' => 'nullable|string|max:100',
            'usr_status' => 'required|in:0,1',
            'usr_joined' => 'nullable|date',

            // Optional password: update only when provided
            'usr_password' => 'nullable|string|min:10|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/',
        ]);

        // Find user
        $user = User::findOrFail($id);

        // Capture original values BEFORE changing the model
        $originalValues = $user->getRawOriginal();
        $old_role = $user->usr_role;
        // Update user details
        $user->usr_name = $validated['usr_name'];
        $user->usr_email = $validated['usr_email'];
        $user->usr_phone = $validated['usr_phone'] ?? '';
        $user->usr_role = $validated['usr_role'];
        $user->usr_designation = $validated['usr_designation'] ?? '';
        $user->usr_dept = $validated['usr_dept'] ?? 'General';
        $user->usr_city = $validated['usr_city'] ?? '';
        $user->usr_status = (int)$validated['usr_status'];
        $user->usr_joined = $validated['usr_joined'];

        // Only update password when a new one is provided
        if (!empty($validated['usr_password'])) {
            $user->usr_password = $validated['usr_password'];
            $user->usr_must_change = 1;
        }

        // Update audit information if these columns exist
        $user->usr_updated_by = auth()->id();
        $user->save();

        // Get the fields actually changed by this save
        $changes = $user->getChanges();

        // Exclude audit metadata from the business change history
        unset(
            $changes['usr_updated_by'],
            $changes['usr_updated_on'],
            $changes['usr_created_by'],
            $changes['usr_created_on'],
            $changes['usr_deleted_on']
        );

        $oldValues = [];
        $newValues = [];

        foreach ($changes as $field => $newValue) {
            $oldValue = $originalValues[$field] ?? null;

            // Record only genuine value changes
            if ($oldValue != $newValue) {
                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }
        }

        app(AuditService::class)->log(
            action: 'update',
            module: 'user',
            record: $user->usr_id,
            oldValues: $oldValues,
            newValues: $newValues,
            description: 'Updated user ' . $user->usr_name
        );

        if (array_key_exists('usr_role', $newValues)) {
            app(AuditService::class)->log(
                action: 'role_changed',
                module: 'security',
                record: 'user:' . $user->usr_id,
                oldValues: [],
                newValues: [
                    'from' => config('constants.ROLES')[$old_role],
                    'to' => config('constants.ROLES')[$user->usr_role],
                ],
                description: 'Security: role changed'
            );
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->fresh(),
        ], 200);
    }

    public function profile (Request $request) {
        return response()->json($request->user());
    }


    public function resetThroughEmail (Request $request) {
        $request->validate([
            'requested_mail' => 'required|email|exists:users,usr_email',
        ]);

        $userExist = User::query()->select('usr_id', 'usr_name', 'usr_email')->where([
            ['usr_email', $request->requested_mail],
            ['usr_status', '=', 1]
        ])->first();

        if (!empty($userExist)) {
            $is_reset_request_exist = PasswordResetRequest::query()->where('prr_usr_email', $request->requested_mail)->orderByDesc('prr_id')->first();
            try {
                $lastRequest = PasswordResetRequest::orderBy('prr_id', 'desc')->first();

                $nextNumber = $lastRequest
                    ? ((int)str_replace('PR-', '', $lastRequest->prr_code)) + 1
                    : 1;

                $request_code = 'PR-' . str_pad($nextNumber, 2, '0', STR_PAD_LEFT);
                $resetRequest = new PasswordResetRequest([
                    'prr_code' => $request_code,
                    'prr_usr_id' => $userExist->usr_id,
                    'prr_usr_name' => $userExist->usr_name,
                    'prr_usr_email' => $userExist->usr_email,
                    'prr_request_ip' => $request->ip(),
                    'prr_request_count' => !empty($is_reset_request_exist) ? $is_reset_request_exist->prr_request_count + 1 : 1,
                    'prr_requested_at' => now(),
                    'prr_last_requested_at' => !empty($is_reset_request_exist) ? $is_reset_request_exist->prr_last_requested_at : now(),
                    'prr_last_notified_at' => /*!empty($is_reset_request_exist) ? $is_reset_request_exist->prr_last_notified_at :*/ null,
                    'prr_admin_action' => 'pending',
                    'prr_created_by' => (str_replace(' ', '_', $userExist->usr_name) . '~#~' . $userExist->usr_id . '~#~' . $_SERVER['REMOTE_ADDR']),
                    'prr_created_on' => now()
                ]);

                $resetRequest->save();
                app(AuditService::class)->log(
                    action: 'password_reset_request',
                    module: 'auth',
                    record: $resetRequest['prr_code'],
                    oldValues: [],
                    newValues: [
                        'Status' => config('constants.PASSWORD_RESET_STATUS.pending'),
                        'Priority' => config('constants.PASSWORD_RESET_PRIORITY.high'),
                    ],
                    description: 'Password reset request.'
                );

                $notification_data = [
                    'kind' => 'pw_reset_request',
                    'title' => 'HIGH PRIORITY: Password Reset Request',
                    'body' => get_logged_in_user_name() . ' (' . get_logged_in_user_data()['usr_email'] . ') requested a password reset.',
                    'actor' => get_logged_in_user_name(),
                    'redirect_to' => route('reset.request.opened', ['prr_id' => $resetRequest->prr_id]),
                    'extra_params' => [
                        'prr_id' => $resetRequest->prr_id,
                        'prr_usr_id' => get_logged_in_user_id(),
                        'prr_usr_name' => get_logged_in_user_name(),
                        'prr_usr_email' => get_logged_in_user_data()['usr_email'],
                    ]
                ];
                $admins = get_all_super_admins();
                if (!empty($admins)) {
                    foreach ($admins->toArray() as $admin) {
                        if ($admin['usr_id'] == get_logged_in_user_id())
                            continue;
                        $notification_data['receiver_id'] = $admin['usr_id'];
                        sendNotification($notification_data);
                    }
                }
                return response()->json([
                    'message' => 'Reset request successfully sent.',
                    //                    'reset_request' => $resetRequest,
                ], 201);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        } else {
            throw ValidationException::withMessages(['user not found.']);
        }
    }

    public function getResetRequestsToNotify () {
        $notify_reset_request = PasswordResetRequest::query()->whereNull('prr_last_notified_at')->where('prr_status', '=', 'pending')->get();
        return response()->json($notify_reset_request);
    }

    public function resetPasswordNotified ($prr_id) {
        if (!empty($prr_id)) {
            $requestExist = PasswordResetRequest::query()->where('prr_id', '=', $prr_id)->whereNull('prr_last_notified_at')->first();
            if (!empty($requestExist)) {
                $is_updated = $requestExist->update([
                    'prr_last_notified_at' => now(),
                ]);
                if ($is_updated) {
                    $all_admins = User::query()->select('usr_name')->where('usr_role', 'admin')->get()->pluck('usr_name')->toArray();
                    app(AuditService::class)->log(
                        action: 'password_reset_notified',
                        module: 'auth',
                        record: $requestExist->prr_code,
                        oldValues: [],
                        newValues: [
                            'Admins' => '[' . implode(', ', $all_admins) . ']',
                        ],
                        description: 'Admins notified (' . config('constants.PASSWORD_RESET_PRIORITY')[$requestExist->prr_priority] . ')'
                    );
                }
                return response()->json([
                    'message' => 'Password reset request notified.',
                ], 200);
            } else {
                return response()->json(['message' => 'Reset request not found.'], 404);
            }
        } else {
            return response()->json(['message' => 'Invalid request.'], 400);
        }
    }

    public function resetPasswordRequestOpened ($prr_id) {
        if (!empty($prr_id)) {
            $requestExist = PasswordResetRequest::query()->where('prr_id', '=', $prr_id)->whereNull('prr_opened_at')->first();
            if (!empty($requestExist)) {
                $is_opened = $requestExist->update([
                    'prr_opened_at' => now(),
                    'prr_opened_by' => get_logged_in_user_id(),
                    'prr_updated_on' => now(),
                    'prr_updated_by' => setCreatedUpdatedBy(),
                ]);
                if ($is_opened) {
                    app(AuditService::class)->log(
                        action: 'password_reset_opened',
                        module: 'auth',
                        record: $requestExist->prr_code,
                        oldValues: [],
                        newValues: [],
                        description: 'Opened the password reset request of ' . $requestExist->prr_usr_name
                    );
                }
                return response()->json([
                    'status' => true,
                    'password_reset_request' => $requestExist,
                ], 200);
            } else {
                return response()->json(['message' => 'Reset request not found.'], 404);
            }
        } else {
            return response()->json(['message' => 'Invalid request.'], 400);
        }
    }

    public function rejectPasswordRequest (Request $request, $prr_id) {
        $request->validate([
            'reject_reason' => 'required|min:2|max:200',
        ]);
        if (!empty($prr_id)) {
            $requestExist = PasswordResetRequest::query()->where([['prr_id', '=', $prr_id], ['prr_status', '=', 'pending']])->first();
            if (!empty($requestExist)) {
                $is_opened = $requestExist->update([
                    'prr_status' => 'reject',
                    'prr_note' => $request->reject_reason,
                    'prr_admin_action' => 'rejected',
                    'prr_completed_by' => get_logged_in_user_id(),
                    'prr_completed_at' => now(),
                    'prr_updated_by' => setCreatedUpdatedBy(),
                    'prr_updated_on' => now(),
                ]);
                if ($is_opened) {
                    app(AuditService::class)->log(
                        action: 'password_reset_rejected',
                        module: 'auth',
                        record: $requestExist->prr_code,
                        oldValues: [
                            'Status' => 'Pending'
                        ],
                        newValues: [
                            'Status' => 'Rejected',
                            'Note' => $request->reject_reason
                        ],
                        description: 'Rejected the password reset request of ' . $requestExist->prr_usr_name
                    );
                }
                return response()->json([
                    'status' => true,
                    'password_reset_request' => $requestExist,
                ], 200);
            } else {
                return response()->json(['message' => 'Reset request not found.'], 404);
            }
        } else {
            return response()->json(['message' => 'Invalid request.'], 400);
        }
    }

    public function resetPasswordCompleted (Request $request, $prr_id) {
        // 1. Validate incoming request payload
        $request->validate([
            'is_system_generated' => 'required_without:is_manually_generated|nullable|boolean',
            'is_manually_generated' => 'required_without:is_system_generated|nullable|boolean',
            'password' => 'required_if:is_manually_generated,true,1',
        ]);

        // 2. Early return if $prr_id is missing
        if (empty($prr_id)) {
            return response()->json([
                'status' => false,
                'message' => 'Reset request not found.',
            ], 404);
        }

        // 3. Find pending reset request
        $resetRequestExist = PasswordResetRequest::query()
            ->where('prr_id', $prr_id)
            ->where('prr_status', 'pending')
            ->first();

        if (!$resetRequestExist) {
            return response()->json([
                'status' => false,
                'message' => 'Reset request not found.',
            ], 404);
        }

        // 4. Safely handle boolean input values
        $isSystemGenerated = $request->boolean('is_system_generated');
        $isManuallyGenerated = $request->boolean('is_manually_generated');

        $generatedPassword = null;

        if ($isSystemGenerated) {
            $generatedPassword = Str::password(length: 12);
        } elseif ($isManuallyGenerated && $request->filled('password')) {
            $generatedPassword = $request->input('password');
        }

        // 5. Fail response if no password was resolved
        if (!$generatedPassword) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to resolve temporary password.',
            ], 400);
        }

        $resetRequestExist->update([
            'prr_status' => 'reset_successfully',
            'prr_admin_action' => 'resets',
            'prr_completed_by' => get_logged_in_user_id(),
            'prr_completed_at' => now(),
            'prr_updated_by' => setCreatedUpdatedBy(),
            'prr_updated_on' => now(),
        ]);

        User::query()->where('usr_id', '=', $resetRequestExist->prr_usr_id)->update([
            'usr_must_change' => 1,
            'usr_password' => Hash::make($generatedPassword)
        ]);

        app(AuditService::class)->log(
            action: 'password_reset_completed',
            module: 'auth',
            record: $resetRequestExist->prr_code,
            oldValues: [
                'Status' => 'Pending'
            ],
            newValues: [
                'Status' => 'Completed',
                'Method' => 'temporary_password',
            ],
            description: 'Reset the password of ' . $resetRequestExist->prr_usr_name . ' (request completed, reminders stopped)'
        );

        // 6. Success response
        return response()->json([
            'status' => true,
            'password_reset_data' => [
                'user_name' => $resetRequestExist->prr_usr_name,
                'email' => $resetRequestExist->prr_usr_email,
                'temporary_password' => $generatedPassword,
            ]
        ], 200);
    }

    public function destroy (Request $request, int $id): \Illuminate\Http\JsonResponse {
        $authUser = $request->user();

        // 1. Authorization: Only Admin or Super Admin can remove users
        $isSuperAdmin = (bool)($authUser->usr_is_super_admin ?? false);
        $isAdmin = strtolower($authUser->usr_role ?? '') === 'admin';

        if (!$isSuperAdmin && !$isAdmin) {
            return response()->json([
                'message' => 'Only an Admin can remove users.',
                'error_code' => 'permission_denied',
                'required_role' => 'admin',
            ], 403);
        }

        // 2. Fetch Target User
        $targetUser = \DB::table('users')->where('usr_id', $id)->first();

        if (!$targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // 3. Rule: Prevent Self-Deletion
        if ((int)$targetUser->usr_id === (int)$authUser->usr_id) {
            return response()->json(['message' => 'You cannot remove your own account.'], 422);
        }

        // 4. Rule: Guard Admin Count
        if (strtolower($targetUser->usr_role ?? '') === 'admin') {
            $otherAdminsCount = \DB::table('users')
                ->whereRaw('LOWER(usr_role) = ?', ['admin'])
                ->where('usr_id', '!=', $id)
                ->where('usr_status', 1)
                ->count();

            if ($otherAdminsCount === 0) {
                return response()->json(['message' => 'You cannot remove the last active Admin.'], 422);
            }
        }

        // 5. Rule: Guard Super Admin Count & Permissions
        if ((int)($targetUser->usr_is_super_admin ?? 0) === 1) {
            if (!$isSuperAdmin) {
                return response()->json(['message' => 'Only a Super Admin can remove another Super Admin.'], 403);
            }

            $otherSuperAdminsCount = \DB::table('users')
                ->where('usr_is_super_admin', 1)
                ->where('usr_id', '!=', $id)
                ->where('usr_status', 1)
                ->count();

            if ($otherSuperAdminsCount === 0) {
                return response()->json(['message' => 'You cannot remove the last Super Admin.'], 422);
            }
        }

        // 6. Check for Open Tasks
        $openTasksCount = \DB::table('tasks as t')
            ->leftJoin('task_assignees as ta', 'ta.tsa_tsk_id', '=', 't.tsk_id')
            ->where(function ($q) use ($targetUser) {
                $q->where('t.tsk_assigned_by', $targetUser->usr_id)
                    ->orWhere('ta.tsa_usr_id', $targetUser->usr_id);
            })
            ->whereNotIn('t.tsk_status', ['completed', 'cancelled'])
            ->distinct()
            ->count('t.tsk_id');

        $force = $request->boolean('force', false);

        if ($openTasksCount > 0 && !$force) {
            return response()->json([
                'message' => "{$targetUser->usr_name} still has {$openTasksCount} open task" . ($openTasksCount === 1 ? '' : 's') . '. Reassign them first.',
                'need_force' => true,
                'open_tasks_count' => $openTasksCount,
            ], 422);
        }

        // 7. Invalidate Active User Sessions / Personal Access Tokens
        if (class_exists(\App\Models\User::class)) {
            $userModel = \App\Models\User::find($id);
            if ($userModel && method_exists($userModel, 'tokens')) {
                $userModel->tokens()->delete();
            }
        }

        // Convert targetUser to associative array cleanly
        $targetUserData = json_decode(json_encode($targetUser), true);

        // 8. Move User to Recycle Bin via Service
        $reason = $request->input('reason', '');
        $binId = $this->recycleBinService->recycleUser($authUser, $targetUserData, $reason);

        // 9. Logging / Audit Trails
        app(AuditService::class)->log(
            action: 'delete',
            module: 'users',
            record: $targetUser->usr_id,
            oldValues: [
                'name' => $targetUser->usr_name,
                'email' => $targetUser->usr_email,
                'role' => $targetUser->usr_role,
            ],
            newValues: [
                'recycle_bin_id' => $binId
            ],
            description: 'Moved user ' . $targetUser->usr_name . ' to the Recycle Bin.'
        );

        return response()->json([
            'status' => true,
            'message' => "Moved user {$targetUser->usr_name} to the Recycle Bin.",
            'recycled' => true,
            'recycle_bin_id' => $binId,
        ], 200);
    }

    public function updateProfileCredentials (Request $request) {
        $validated = $request->validate([
            'usr_password' => ['required', 'current_password'],
            'new_password' => [
                'required',
                'string',
                'min:10',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
            ],
        ]);

        $user = auth()->user();

        $user->usr_password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
