<?php

declare(strict_types=1);

namespace app\services\sudi;

/**
 * Conservative size guidance. It never invents a product size chart.
 */
class SudiAiSizeAdvisorService
{
    public function advise(array $profile, array $sizeChart): array
    {
        if (!$sizeChart) {
            return ['size' => null, 'confidence' => 'low', 'reason' => '商品缺少尺码表，不能凭空推荐尺码'];
        }

        $fit = (string)($profile['fit'] ?? 'regular');
        if (!in_array($fit, ['slim', 'regular', 'loose'], true)) {
            $fit = 'regular';
        }

        $profileValues = [
            'bust_cm' => max(0, (float)($profile['bust_cm'] ?? 0)),
            'waist_cm' => max(0, (float)($profile['waist_cm'] ?? 0)),
            'hip_cm' => max(0, (float)($profile['hip_cm'] ?? 0)),
            'height_cm' => max(0, (float)($profile['height_cm'] ?? 0)),
            'weight_kg' => max(0, (float)($profile['weight_kg'] ?? 0)),
        ];

        $targetEase = [
            'bust_cm' => $fit === 'slim' ? 3 : ($fit === 'loose' ? 10 : 6),
            'waist_cm' => $fit === 'slim' ? 2 : ($fit === 'loose' ? 8 : 4),
            'hip_cm' => $fit === 'slim' ? 3 : ($fit === 'loose' ? 9 : 5),
        ];

        $candidates = [];
        foreach ($sizeChart as $row) {
            if (!is_array($row) || empty($row['size'])) {
                continue;
            }

            $score = 0.0;
            $checks = 0;
            foreach (['bust_cm', 'waist_cm', 'hip_cm'] as $key) {
                $body = $profileValues[$key];
                $garment = isset($row[$key]) ? (float)$row[$key] : 0.0;
                if ($body <= 0 || $garment <= 0) {
                    continue;
                }

                $ease = $garment - $body;
                $score += $ease < 0 ? 100 + abs($ease) * 5 : abs($ease - $targetEase[$key]);
                $checks++;
            }

            if ($profileValues['weight_kg'] > 0 && isset($row['weight_min_kg'], $row['weight_max_kg'])) {
                $min = (float)$row['weight_min_kg'];
                $max = (float)$row['weight_max_kg'];
                if ($min > 0 && $max >= $min) {
                    $weight = $profileValues['weight_kg'];
                    $score += $weight < $min ? ($min - $weight) * 4 : ($weight > $max ? ($weight - $max) * 4 : 0);
                    $checks++;
                }
            }

            if ($profileValues['height_cm'] > 0 && isset($row['height_min_cm'], $row['height_max_cm'])) {
                $min = (float)$row['height_min_cm'];
                $max = (float)$row['height_max_cm'];
                if ($min > 0 && $max >= $min) {
                    $height = $profileValues['height_cm'];
                    $score += $height < $min ? ($min - $height) * 0.5 : ($height > $max ? ($height - $max) * 0.5 : 0);
                    $checks++;
                }
            }

            if ($checks > 0) {
                $candidates[] = ['size' => (string)$row['size'], 'score' => $score / $checks, 'checks' => $checks];
            }
        }

        if (!$candidates) {
            return [
                'size' => null,
                'confidence' => 'low',
                'reason' => '当前尺码表与用户资料没有可直接匹配的身体尺寸或身高体重区间',
            ];
        }

        usort($candidates, function ($a, $b) {
            if ($a['score'] == $b['score']) {
                return $b['checks'] <=> $a['checks'];
            }
            return $a['score'] <=> $b['score'];
        });

        $best = $candidates[0];
        $confidence = $best['checks'] >= 2 ? 'medium' : 'low';
        $alternatives = [];
        foreach (array_slice($candidates, 1, 2) as $candidate) {
            $alternatives[] = $candidate['size'];
        }

        return [
            'size' => $best['size'],
            'confidence' => $confidence,
            'alternatives' => $alternatives,
            'reason' => '根据商品真实尺码表与已提供身体数据匹配，仅作为试穿参考',
        ];
    }
}
