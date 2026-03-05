<?php

namespace Vitalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Vitalytics\Vitalytics;

/**
 * Handles maintenance banner dismissal
 */
class MaintenanceController extends Controller
{
    /**
     * Dismiss a maintenance notification
     */
    public function dismiss(Request $request)
    {
        $notificationId = $request->input('notification_id');

        if ($notificationId) {
            Vitalytics::instance()->dismissMaintenance((int) $notificationId);
        }

        // Return to previous page or respond with JSON for AJAX requests
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }
}
