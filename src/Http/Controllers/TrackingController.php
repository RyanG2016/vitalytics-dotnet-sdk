<?php

namespace Vitalytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Vitalytics\VitalyticsAnalytics;

class TrackingController extends Controller
{
    /**
     * Handle incoming tracking requests from the JavaScript tracker.
     */
    public function track(Request $request): JsonResponse
    {
        // Validate basic structure
        $type = $request->input('type');
        if (!$type) {
            return response()->json(['error' => 'Missing type'], 400);
        }

        $screen = $request->input('screen');
        $properties = $request->input('properties', []);
        $label = $request->input('label');
        $screenLabel = $request->input('screenLabel');

        // Ensure properties is an array
        if (!is_array($properties)) {
            $properties = [];
        }

        // Add label to properties if provided (for elements)
        if ($label) {
            $properties['label'] = $label;
        }

        // Add screen label to properties if provided
        if ($screenLabel) {
            $properties['screen_label'] = $screenLabel;
        }

        // Get analytics instance
        $analytics = VitalyticsAnalytics::instance();

        // Set screen context if provided
        if ($screen) {
            $analytics->setScreen($screen);
        }

        // Handle different event types
        switch ($type) {
            case 'click':
                $element = $request->input('element');
                if ($element) {
                    $analytics->trackClick($element, $properties);
                }
                break;

            case 'feature':
                $feature = $request->input('feature');
                if ($feature) {
                    $analytics->trackFeature($feature, $properties);
                }
                break;

            case 'screen':
                $screenName = $request->input('screen');
                if ($screenName) {
                    $analytics->trackScreen($screenName, $properties);
                }
                break;

            case 'form':
                $form = $request->input('form');
                $action = $request->input('action', 'submitted');
                if ($form) {
                    $analytics->trackForm($form, $action, $properties);
                }
                break;

            default:
                return response()->json(['error' => 'Unknown type'], 400);
        }

        return response()->json(['ok' => true]);
    }
}
