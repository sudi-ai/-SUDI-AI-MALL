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
        $height = (float)($profile['height_cm'] ?? 0);
        $weight = (float)($profile['weight_kg'] ?? 0);
        $bust = (float)($profile['bust_cm'] ?? 0);
        $waist = (float)($profile['waist_cm'] ?? 0);
        $hip = (float)($profile['hip_cm'] ?? 0);
        $fit = (string)($profile['fit'] ?? 'regular');

        if ($height <= 0 || $weight <= 0) {
            return ['size' => null, 'confidence' => 'low', 'reason' => '需要身高和体重后才能给出尺码建议'];
        }
        if (!$sizeChart) {
            return ['size' => null, 'confidence' => 'low', 'reason' => '商品缺少尺码表，不能凭空推荐尺码'];
        }

        $candidates = [];
        foreach ($sizeChart as $row) {
            if (empty($row['size'])) continue;
            $score = 0;
            $checks = 0;
            foreach ([['bust_cm', $bust], ['waist_cm', $waist], ['hip_cm', $hip]] as [$key, $value]) {
                if ($value > 0 && isset($row[$key]) && (float)$row[$key] > 0) {
                    $ease = (float)$row[$key] - $value;
                    $target = $fit === 'loose' ? 10 : ($fit === 'slim' ? 3 : 6);
                    $score += abs($ease - $target);
                    $checks++;
                }
            }
            if ($checks) $candidates[] = ['size' => (string)$row['size'], 'score' => $score / $checks];
        }

        if (!$candidates) {
            return ['size' => null, 'confidence' => 'low', 'reason' => '尺码表缺少可与身体数据匹配的胸围/腰围/臀围'];
        }
        usort($candidates, static fn($a, $b) => $a['score'] <=> $b['score']);
        return ['size' => $candidates[0]['size'], 'confidence' => count($candidates) > 1 ? 'medium' : 'low', 'reason' => '根据商品尺码表与用户身体数据匹配，仅作为试穿参考'];
    }
}
