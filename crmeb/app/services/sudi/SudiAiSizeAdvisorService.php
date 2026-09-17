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

        $fit = isset($profile['fit']) && is_scalar($profile['fit']) ? (string)$profile['fit'] : 'regular';
        if (!in_array($fit, ['slim', 'regular', 'loose'], true)) {
            $fit = 'regular';
        }

        $profileValues = [
            'bust_cm' => $this->number($profile, 'bust_cm'),
            'waist_cm' => $this->number($profile, 'waist_cm'),
            'hip_cm' => $this->number($profile, 'hip_cm'),
            'height_cm' => $this->number($profile, 'height_cm'),
            'weight_kg' => $this->number($profile, 'weight_kg'),
        ];

        $targetEase = [
            'bust_cm' => $fit === 'slim' ? 3 : ($fit === 'loose' ? 10 : 6),
            'waist_cm' => $fit === 'slim' ? 2 : ($fit === 'loose' ? 8 : 4),
            'hip_cm' => $fit === 'slim' ? 3 : ($fit === 'loose' ? 9 : 5),
        ];

        $candidates = [];
        foreach ($sizeChart as $row) {
            if (!is_array($row) || !isset($row['size']) || !is_scalar($row['size'])) {
                continue;
            }
            $size = trim((string)$row['size']);
            if ($size === '') {
                continue;
            }

            $score = 0.0;
            $checks = 0;
            foreach (['bust_cm', 'waist_cm', 'hip_cm'] as $key) {
                $body = $profileValues[$key];
                $garment = $this->number($row, $key);
                if ($body <= 0 || $garment <= 0) {
                    continue;
                }

                $ease = $garment - $body;
                $score += $ease < 0 ? 100 + abs($ease) * 5 : abs($ease - $targetEase[$key]);
                $checks++;
            }

            $weightMin = $this->number($row, 'weight_min_kg');
            $weightMax = $this->number($row, 'weight_max_kg');
            if ($profileValues['weight_kg'] > 0 && $weightMin > 0 && $weightMax >= $weightMin) {
                $weight = $profileValues['weight_kg'];
                $score += $weight < $weightMin ? ($weightMin - $weight) * 4 : ($weight > $weightMax ? ($weight - $weightMax) * 4 : 0);
                $checks++;
            }

            $heightMin = $this->number($row, 'height_min_cm');
            $heightMax = $this->number($row, 'height_max_cm');
            if ($profileValues['height_cm'] > 0 && $heightMin > 0 && $heightMax >= $heightMin) {
                $height = $profileValues['height_cm'];
                $score += $height < $heightMin ? ($heightMin - $height) * 0.5 : ($height > $heightMax ? ($height - $heightMax) * 0.5 : 0);
                $checks++;
            }

            if ($checks > 0) {
                $candidates[] = ['size' => $size, 'score' => $score / $checks, 'checks' => $checks];
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
            'reason' => '根据提供的商品尺码表与已提供身体数据匹配，仅作为试穿参考',
        ];
    }

    private function number(array $data, string $key): float
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return 0.0;
        }
        return max(0.0, (float)$data[$key]);
    }
}
