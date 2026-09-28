<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace crmeb\services\easywechat\v3pay;


use crmeb\exceptions\PayException;
use crmeb\services\CacheService;

/**
 * Class Certficates
 * @package crmeb\services\easywechat\v3pay
 */
trait Certficates
{

    /**
     * @param string|null $key
     * @return array|mixed|null
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getCertficatescAttr(string $key = null)
    {
        $cacheKey = '_wx_v3' . $this->app['config']['v3_payment']['serial_no'];
        if (CacheService::has($cacheKey)) {
            $res = CacheService::get($cacheKey);
            if ($key && $res) {
                return $res[$key] ?? null;
            } else {
                return $res;
            }
        }
        $certficates = $this->getCertficates();
        CacheService::set($cacheKey, $certficates, 3600 * 24 * 30);
        if ($key && $certficates) {
            return $certficates[$key] ?? null;
        }
        return $certficates;
    }

    /**
     * Return the platform certificate matching a notification serial number.
     * WeChat can return multiple active certificates during key rotation.
     */
    public function getCertficatesBySerial(string $serial): ?array
    {
        $certificates = $this->getCertficatesList();
        foreach ($certificates as $certificate) {
            if (isset($certificate['serial_no']) && hash_equals((string)$certificate['serial_no'], $serial)) {
                return $certificate;
            }
        }

        // The cached list may predate a platform key rotation. Refresh once
        // before rejecting an otherwise valid notification.
        $certificates = $this->getCertficatesList(true);
        foreach ($certificates as $certificate) {
            if (isset($certificate['serial_no']) && hash_equals((string)$certificate['serial_no'], $serial)) {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getCertficatesList(bool $refresh = false): array
    {
        $config = $this->app['config']['v3_payment'];
        $cacheKey = '_wx_v3_certificates_v2_' . ($config['serial_no'] ?? 'default');
        if (!$refresh && CacheService::has($cacheKey)) {
            $cached = CacheService::get($cacheKey);
            if (is_array($cached)) return $cached;
        }

        $response = $this->request('v3/certificates', 'GET', [], false);
        if (isset($response['code']) || empty($response['data']) || !is_array($response['data'])) {
            throw new PayException($response['message'] ?? '微信支付平台证书获取失败');
        }

        $certificates = [];
        foreach ($response['data'] as $item) {
            if (!is_array($item) || empty($item['serial_no']) || empty($item['encrypt_certificate'])) continue;
            $item['certificates'] = $this->decrypt($item['encrypt_certificate']);
            unset($item['encrypt_certificate']);
            $certificates[] = $item;
        }
        if (!$certificates) throw new PayException('微信支付平台证书列表无有效证书');

        CacheService::set($cacheKey, $certificates, 3600 * 24);
        return $certificates;
    }

    /**
     * get certficates.
     *
     * @return array
     */
    public function getCertficates()
    {
        return $this->getCertficatesList()[0];
    }
}
