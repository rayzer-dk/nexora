<?php

declare(strict_types=1);

namespace Commerce\Modules\Accessibility\Application;

final class AccessibilityBaseline
{
    /** @return array<string, bool|string> */
    public function requirements(): array
    {
        return [
            'target' => 'EN 301 549 / WCAG 2.2 AA',
            'keyboard_navigation' => true,
            'visible_focus' => true,
            'semantic_landmarks' => true,
            'form_labels_and_errors' => true,
            'screen_reader_status_updates' => true,
            'reduced_motion_support' => true,
            'zoom_200_percent' => true,
            'touch_target_minimum' => true,
            'no_color_only_meaning' => true,
            'checkout_time_extension' => true,
        ];
    }
}
