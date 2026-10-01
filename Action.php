<?php

namespace TypechoPlugin\DouyinVideo;

use Typecho\Widget;
use Utils\Helper;
use Widget\ActionInterface;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 抖音视频解析接口（需登录）：净化链接 → 展开短链 → 提取 video_id 与封面
 */
class Action extends Widget implements ActionInterface
{
    /** PC UA：用于短链展开 */
    private const UA_PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** 移动端 UA：PC 分享页被 __ac_nonce 反爬拦截（需浏览器 JS 签名），页面数据必须用移动端 UA 请求 */
    private const UA_MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    public function execute()
    {
    }

    public function action()
    {
        $this->response->setContentType('application/json');

        if (!User::alloc()->hasLogin()) {
            $this->fail('请先登录后再使用抖音解析');
        }

        $shareUrl = trim($this->request->get('url', ''));
        if ($shareUrl === '') {
            $this->fail('缺少 url 参数');
        }

        try {
            echo json_encode($this->resolve($shareUrl), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
        exit;
    }

    private function fail(string $msg)
    {
        echo json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 解析分享链接为播放地址与封面
     */
    private function resolve(string $shareUrl): array
    {
        // 净化：移动端常把整段分享文案（文字+链接）直接粘贴进来，提取其中第一个链接
        if (preg_match("/https?:\/\/[A-Za-z0-9\-._~:\/?#\[\]@!\$&'()*+,;=%]+/i", $shareUrl, $m)) {
            $shareUrl = $m[0];
        }

        $cookie = self::pluginOption('cookie');
        $ratio = strtolower(self::pluginOption('ratio', '1080p'));
        if (!preg_match('/^(1080p|720p|540p|360p)$/', $ratio)) {
            $ratio = '1080p';
        }

        // 展开短链接（可能本身就是长链接）
        $longUrl = $this->expandShortUrl($shareUrl, $cookie) ?: $shareUrl;
        if (!preg_match('/(?:video|note)\/(\d+)/', $longUrl, $m)) {
            throw new \Exception('无法从链接中提取视频 ID，请确认分享链接是否完整有效');
        }

        $info = $this->getVideoInfo($m[1], $cookie);
        if (empty($info['video_id'])) {
            throw new \Exception('解析失败：页面数据中无视频信息，可能被风控拦截（服务器 IP 被抖音限制）或 Cookie 已失效');
        }

        return [
            'code'  => 0,
            'url'   => "https://aweme.snssdk.com/aweme/v1/play/?video_id={$info['video_id']}&ratio={$ratio}&line=0",
            'cover' => $info['cover'] ?? '',
        ];
    }

    /**
     * 读取插件配置（插件启用后未保存过配置时 Options::plugin() 会抛异常，需兜底）
     */
    private static function pluginOption(string $key, string $default = ''): string
    {
        try {
            $value = Helper::options()->plugin('DouyinVideo')->{$key} ?? null;
            return ($value === null || $value === '') ? $default : (string) $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * 从分享页提取 video_id 和封面（数据被风控清空时，用响应下发的新 ttwid 重试一次）
     */
    private function getVideoInfo(string $awemeId, string $cookie): array
    {
        $pageUrl = "https://www.iesdouyin.com/share/video/{$awemeId}";

        for ($i = 0; $i < 2; $i++) {
            $newTtwid = null;
            $data = $this->fetchRouterData($pageUrl, $cookie, $newTtwid);
            if ($data === null) {
                return [];
            }

            $info = $this->extractVideoInfo($data);
            if ($info !== null) {
                return $info;
            }

            if ($i === 0 && $newTtwid) {
                $cookie = $this->replaceTtwid($cookie, $newTtwid);
            }
        }

        return [];
    }

    /**
     * 展开短链接，获取最终长链接
     */
    private function expandShortUrl(string $shortUrl, string $cookie = ''): ?string
    {
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $shortUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => self::UA_PC,
        ];
        if ($cookie) {
            $options[CURLOPT_COOKIE] = $cookie;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($httpCode >= 400 || empty($effectiveUrl)) {
            return null;
        }
        return $effectiveUrl;
    }

    /**
     * 请求分享页，解析 window._ROUTER_DATA 数据（顺带捕获响应下发的 ttwid 供失效重试）
     */
    private function fetchRouterData(string $pageUrl, string $cookie = '', ?string &$newTtwid = null): ?array
    {
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $pageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => self::UA_MOBILE,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: https://www.douyin.com/',
            ],
        ];
        if ($cookie) {
            $options[CURLOPT_COOKIE] = $cookie;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            return null;
        }

        // 响应头可能下发新 ttwid，留作数据被清空时重试使用
        if (preg_match('/set-cookie:\s*ttwid=([^;\r\n]+)/i', substr($raw, 0, $headerSize), $m)) {
            $newTtwid = $m[1];
        }

        // 提取 window._ROUTER_DATA 或 window.__INITIAL_STATE__
        if (!preg_match('/window\._ROUTER_DATA\s*=\s*(.*?)<\/script>/s', $raw, $matches)
            && !preg_match('/window\.__INITIAL_STATE__\s*=\s*(.*?)<\/script>/s', $raw, $matches)) {
            return null;
        }

        $data = json_decode(rtrim(trim($matches[1]), ';'), true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * 从页面数据中提取视频信息（video_id + 封面）
     */
    private function extractVideoInfo($data): ?array
    {
        // 尝试多种路径定位 video 节点
        $paths = [
            ['loaderData', 'video_(id)/page', 'videoInfoRes', 'item_list', 0, 'video'],
            ['videoInfoRes', 'item_list', 0, 'video'],
            ['item_list', 0, 'video'],
        ];

        foreach ($paths as $path) {
            $video = $this->getPathValue($data, $path);
            if (!is_array($video)) {
                continue;
            }

            // video_id 以 v 开头（老版 v0d...，新版 v1e... 等），不要硬编码前缀版本
            $videoId = null;
            foreach (['play_addr', 'download_addr', 'play_addr_lowbr'] as $key) {
                $addr = $video[$key] ?? null;
                if (!is_array($addr)) {
                    continue;
                }
                $uri = $addr['uri'] ?? null;
                if (is_string($uri) && preg_match('/^v\d{1,2}[0-9a-z]{14,}$/i', $uri)) {
                    $videoId = $uri;
                    break;
                }
                // uri 本身是完整播放 URL 时，从 uri / url_list 的播放 API 中提取 video_id 参数
                if (is_string($uri) && preg_match('/[?&]video_id=(v\d{1,2}[0-9a-z]{14,})/i', $uri, $m)) {
                    $videoId = $m[1];
                    break;
                }
                $url0 = $addr['url_list'][0] ?? null;
                if (is_string($url0) && preg_match('/[?&]video_id=(v\d{1,2}[0-9a-z]{14,})/i', $url0, $m)) {
                    $videoId = $m[1];
                    break;
                }
            }
            if ($videoId === null) {
                continue;
            }

            // 封面：依次尝试 cover / origin_cover / dynamic_cover
            $cover = null;
            foreach (['cover', 'origin_cover', 'dynamic_cover'] as $key) {
                $url = $this->getPathValue($video, [$key, 'url_list', 0]);
                if (is_string($url) && $url !== '') {
                    $cover = $url;
                    break;
                }
            }

            return ['video_id' => $videoId, 'cover' => $cover];
        }

        // 结构变化兜底：递归搜索
        $videoId = $this->findVideoIdRecursive($data);
        return $videoId !== null ? ['video_id' => $videoId, 'cover' => null] : null;
    }

    /**
     * 递归搜索数组中的 video_id（v 开头）
     */
    private function findVideoIdRecursive($data): ?string
    {
        if (is_array($data)) {
            foreach ($data as $value) {
                if (is_string($value) && preg_match('/^v\d{1,2}[0-9a-z]{14,}$/i', $value)) {
                    return $value;
                }
                $result = $this->findVideoIdRecursive($value);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * 按路径依次取数组中的值，取不到返回 null
     */
    private function getPathValue($data, array $path)
    {
        $value = $data;
        foreach ($path as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        return $value;
    }

    /**
     * 替换 Cookie 串中的 ttwid 值
     */
    private function replaceTtwid(string $cookie, string $ttwid): string
    {
        if (preg_match('/ttwid=[^;]+/', $cookie)) {
            return preg_replace('/ttwid=[^;]+/', 'ttwid=' . $ttwid, $cookie, 1);
        }
        return $ttwid . '; ' . $cookie;
    }
}
