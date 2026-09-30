<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Dashboard;

/** Geometry of the revenue chart, computed server side so the dashboard needs no chart library. */
final class DashboardChart
{
    private const W = 720;
    private const H = 220;
    private const PAD_L = 8;
    private const PAD_R = 8;
    private const PAD_T = 14;
    private const PAD_B = 22;

    /**
     * @param list<array{day:string,revenue_minor:int,orders:int}> $series
     * @param list<array{id:int|string,day:string,note:string}> $annotations
     * @return array<string,mixed>
     */
    public static function layout(array $series, array $annotations): array
    {
        $n = count($series);
        $max = 0;
        foreach ($series as $p) {
            $max = max($max, (int) $p['revenue_minor']);
        }
        $niceMax = self::niceMax($max);
        $innerW = self::W - self::PAD_L - self::PAD_R;
        $innerH = self::H - self::PAD_T - self::PAD_B;
        $step = $n > 1 ? $innerW / ($n - 1) : 0;
        $points = [];
        $index = [];
        foreach ($series as $i => $p) {
            $x = self::PAD_L + ($n > 1 ? $i * $step : $innerW / 2);
            $y = self::PAD_T + $innerH - ($niceMax > 0 ? ((int) $p['revenue_minor'] / $niceMax) * $innerH : 0);
            $points[] = ['x' => round($x, 1), 'y' => round($y, 1), 'day' => $p['day'], 'revenue_minor' => (int) $p['revenue_minor'], 'orders' => (int) $p['orders']];
            $index[$p['day']] = round($x, 1);
        }
        $line = '';
        foreach ($points as $i => $pt) {
            $line .= ($i === 0 ? 'M' : 'L') . $pt['x'] . ' ' . $pt['y'] . ' ';
        }
        $line = trim($line);
        $baseline = self::PAD_T + $innerH;
        $area = $points === [] ? '' : $line . ' L' . $points[$n - 1]['x'] . ' ' . $baseline . ' L' . $points[0]['x'] . ' ' . $baseline . ' Z';
        $marks = [];
        foreach ($annotations as $a) {
            if (isset($index[$a['day']])) {
                $marks[] = ['x' => $index[$a['day']], 'day' => $a['day'], 'note' => $a['note'], 'id' => (int) ($a['id'] ?? 0)];
            }
        }
        $grid = [];
        foreach ([0, 0.5, 1] as $f) {
            $grid[] = ['y' => round(self::PAD_T + $innerH - $f * $innerH, 1), 'value_minor' => (int) round($niceMax * $f)];
        }
        $labels = [];
        if ($n > 0) {
            foreach (array_unique([0, (int) floor(($n - 1) / 2), $n - 1]) as $i) {
                $labels[] = ['x' => $points[$i]['x'], 'day' => $points[$i]['day']];
            }
        }

        return ['w' => self::W, 'h' => self::H, 'baseline' => $baseline, 'top' => self::PAD_T, 'line' => $line, 'area' => $area, 'points' => $points, 'marks' => $marks, 'grid' => $grid, 'labels' => $labels, 'max_minor' => $niceMax, 'has_data' => $max > 0];
    }

    private static function niceMax(int $max): int
    {
        if ($max <= 0) {
            return 0;
        }
        $pow = 10 ** max(0, (int) floor(log10($max)) - 1);
        return (int) (ceil($max / $pow / 2) * 2 * $pow);
    }
}
