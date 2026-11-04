<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

function get_logged_in_user_data() {
    if (!Auth::check()) {
        return null;
    }

    return Auth::user();
}

function get_user_data_by_id($usr_id) {
    if (!Auth::check()) {
        return null;
    }

    return User::query()->find($usr_id);
}

function get_logged_in_user_id() {
    if (!Auth::check()) {
        return null;
    }
    return Auth::user()->usr_id;
}

function get_logged_in_user_name() {
    if (!Auth::check()) {
        return null;
    }
    return Auth::user()->usr_name;
}

/*function get_logged_in_adm_pic() {
    return Auth::user()?->adm_photo;
}*/

function is_admin() {
    return get_logged_in_user_role() == 'admin';
}

function get_logged_in_user_role() {
    return Auth::user()?->usr_role;
}

function get_logged_in_user_designation() {
    $designation = 'admin';
    if (!is_admin()) {
        $designation = Auth::user()?->usr_designation;
    }
    return $designation;
}

if (!function_exists('get_users_children_in_depth')) {

    /**
     * Get children of an employee up to a specified depth.
     *
     * @param int $userId
     * @param bool $onlyIds
     * @param int|null $depth
     * @return array
     */

    function get_users_children_in_depth(int $userId, bool $onlyIds = false, ?int $depth = 1): array {
        $users = User::query()
            ->select('usr_id', 'usr_name', 'usr_email', 'usr_phone', 'usr_role', 'usr_designation', 'usr_reporting_to', 'usr_status', 'usr_online', 'usr_last_seen', 'usr_joined')->get()->groupBy('usr_reporting_to');

        $result = [];
        $findChildren = function (int $parentId, int $currentDepth) use (&$findChildren, &$result, $users, $onlyIds, $depth) {
            // Stop when the depth limit is reached.
            if ($depth !== null && $currentDepth > $depth) {
                return;
            }
            foreach ($users->get($parentId, collect()) as $user) {
                $result[] = $onlyIds ? $user->usr_id : $user->toArray();
                $findChildren((int)$user->usr_id, $currentDepth + 1);
            }
        };
        $findChildren($userId, 1);
        return $result;
    }

}

/*if (!function_exists('user_online_status')) {
    function user_online_status($usr_id): array {
        $details = User::where('usr_id', '=', $usr_id)->first();

        // Panel is currently active
        $isPanelActive = !empty($details->adm_panel_active)
            && !empty($details->adm_panel_last_seen_at)
            && Carbon::parse($details->adm_panel_last_seen_at)->gt(now()->subSeconds(PANEL_ACTIVE_DURATION));

        if ($isPanelActive) {
            return [
                'status' => 'online',
                'label' => 'Online',
                'bg' => '#ecfdf5',
                'text' => '#047857',
                'border' => '#a7f3d0',
                'dot' => '#10b981',
                'pulse' => true,
            ];
        }

        // User is logged in but panel is not active
        $isLoggedIn = !empty($details->adm_last_activity_at)
            && Carbon::parse($details->adm_last_activity_at)->gt(now()->subMinutes(IS_LOGGED_CHECK_DURATION));

        if ($isLoggedIn) {
            return [
                'status' => 'logged_in',
                'label' => 'Logged In',
                'bg' => '#f0f9ff',
                'text' => '#0369a1',
                'border' => '#bae6fd',
                'dot' => '#0284c7',
                'pulse' => false,
            ];
        }

        // Offline
        return [
            'status' => 'offline',
            'label' => 'Offline',
            'bg' => '#f8fafc',
            'text' => '#64748b',
            'border' => '#e2e8f0',
            'dot' => '#94a3b8',
            'pulse' => false,
        ];
    }
}

if (!function_exists('user_online_status_badge')) {
    function user_online_status_badge($adm_id): string {
        $s = user_online_status($adm_id);

        return sprintf(
            '<span class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill" style="background-color: %s; color: %s; border: 1px solid %s; font-size: 12px; font-weight: 500;">' .
            '<span style="width: 7px; height: 7px; border-radius: 50%%; background-color: %s;"></span>' .
            '<span>%s</span>' .
            '</span>',
            $s['bg'],
            $s['text'],
            $s['border'],
            $s['dot'],
            e($s['label'])
        );
    }
}

if (!function_exists('user_online_status_dot')) {
    function user_online_status_dot($adm_id, string $customStyle = 'bottom: 7px; right: 11px;'): string {
        $s = user_online_status($adm_id);

        $pulseHtml = $s['pulse'] ? sprintf('<span class="status-pulse-ring" style="background-color: %s;"></span>', $s['dot']) : '';

        $style = sprintf('width: 16px; height: 16px; background-color: %s; %s', $s['dot'], $customStyle);
        return sprintf(
            '<span class="position-absolute rounded-circle border border-2 border-white" ' .
            'style="%s" ' .
            'title="%s">%s</span>',
            $style,
            e($s['label']),
            $pulseHtml
        );
    }
}*/
