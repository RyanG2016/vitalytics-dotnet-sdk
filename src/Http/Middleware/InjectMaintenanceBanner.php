<?php

namespace Vitalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vitalytics\Vitalytics;

/**
 * Middleware to automatically inject maintenance banners into HTML responses
 *
 * Enable via config: VITALYTICS_MAINTENANCE_AUTO_INJECT=true
 * Then register in your Kernel.php or bootstrap/app.php
 */
class InjectMaintenanceBanner
{
    /**
     * Handle an incoming request
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only inject into HTML responses
        if (!$this->shouldInject($response)) {
            return $response;
        }

        $content = $response->getContent();
        $bannerHtml = $this->buildBannerHtml();

        // Inject banner after opening <body> tag
        if ($bannerHtml && preg_match('/<body[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $bodyTagEnd = $matches[0][1] + strlen($matches[0][0]);
            $content = substr($content, 0, $bodyTagEnd) . $bannerHtml . substr($content, $bodyTagEnd);
            $response->setContent($content);
        }

        return $response;
    }

    /**
     * Check if we should inject the banner
     */
    private function shouldInject($response): bool
    {
        if (!config('vitalytics.maintenance.enabled', true)) {
            return false;
        }

        if (!config('vitalytics.maintenance.auto_inject', false)) {
            return false;
        }

        if (!$response instanceof Response) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');
        return str_contains($contentType, 'text/html');
    }

    /**
     * Build the HTML for maintenance banners
     */
    private function buildBannerHtml(): string
    {
        $vitalytics = Vitalytics::instance();
        $notifications = $vitalytics->getDisplayableMaintenanceNotifications();

        if (empty($notifications)) {
            return '';
        }

        $html = '<div id="vitalytics-maintenance-banners" style="position:relative;z-index:9999;">';

        foreach ($notifications as $notification) {
            $id = $notification['id'] ?? 0;
            $title = htmlspecialchars($notification['title'] ?? 'Maintenance', ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars($notification['message'] ?? '', ENT_QUOTES, 'UTF-8');
            $severity = $notification['severity'] ?? 'info';
            $dismissible = $notification['dismissible'] ?? true;

            // Severity-based colors
            $colors = [
                'info' => ['bg' => '#dbeafe', 'border' => '#3b82f6', 'text' => '#1e40af', 'icon' => 'info-circle'],
                'warning' => ['bg' => '#fef3c7', 'border' => '#f59e0b', 'text' => '#92400e', 'icon' => 'exclamation-triangle'],
                'critical' => ['bg' => '#fee2e2', 'border' => '#ef4444', 'text' => '#991b1b', 'icon' => 'exclamation-circle'],
            ];
            $color = $colors[$severity] ?? $colors['info'];

            $dismissButton = '';
            if ($dismissible) {
                $dismissUrl = route('vitalytics.maintenance.dismiss');
                $csrfToken = csrf_token();
                $dismissButton = <<<HTML
                <form method="POST" action="{$dismissUrl}" style="margin:0;padding:0;display:inline;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <input type="hidden" name="notification_id" value="{$id}">
                    <button type="submit" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:{$color['text']};opacity:0.7;padding:0 4px;" title="Dismiss">&times;</button>
                </form>
HTML;
            }

            $html .= <<<HTML
<div class="vitalytics-maintenance-banner" data-notification-id="{$id}" style="background:{$color['bg']};border-left:4px solid {$color['border']};color:{$color['text']};padding:12px 16px;margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;max-width:1200px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:12px;">
            <strong style="font-weight:600;">{$title}</strong>
            <span>{$message}</span>
        </div>
        {$dismissButton}
    </div>
</div>
HTML;
        }

        $html .= '</div>';

        return $html;
    }
}
