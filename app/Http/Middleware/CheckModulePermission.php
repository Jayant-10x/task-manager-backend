<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModulePermission {
    /**
     * Handle an incoming request.
     *
     * @param string $moduleSlug e.g. 'projects', 'dashboard'
     * @param string $action e.g. 'can_view', 'can_add', 'can_edit', 'can_delete'
     */
    public function handle(Request $request, Closure $next, string $moduleSlug, string $action = 'can_view'): Response {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$user->usr_status) {
            return response()->json([
                'message' => 'Account is inactive.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        // 1. Bypass check for Super Admins
        if ($user->usr_is_super_admin) {
            return $next($request);
        }

        // 2. Fetch permission record for the given module
        $permission = $user->modulePermissions()
            ->where('ump_module_slug', $moduleSlug)
            ->first();

        // Map column names based on action parameter
        $columnMap = [
            'can_view' => 'ump_can_view',
            'can_add' => 'ump_can_add',
            'can_edit' => 'ump_can_edit',
            'can_delete' => 'ump_can_delete',
            'view' => 'ump_can_view',
            'add' => 'ump_can_add',
            'edit' => 'ump_can_edit',
            'delete' => 'ump_can_delete',
        ];

        $column = $columnMap[$action] ?? 'ump_can_view';

        // 3. Deny access if no permission record exists or action is false
        if (!$permission || !$permission->{$column}) {
            return response()->json([
                'message' => 'Forbidden. You do not have permission to perform this action on the specified module.',
            ], 403);
        }

        return $next($request);
    }
}
