<?php

namespace TypechoPlugin\DouyinVideo;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 抖音视频 - 文章编辑器一键解析并插入抖音视频
 *
 * @package DouyinVideo
 * @author xiao
 * @version 1.0.0
 * @link https://bug.loc.cc
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        // 编辑器按钮（文章 + 独立页面）
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'renderEditor'];
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'renderEditor'];

        // 前端头部 referrer meta（已存在则不添加）
        \Typecho\Plugin::factory('Widget_Archive')->header = [__CLASS__, 'outputHeader'];

        // 解析接口
        Helper::addAction('douyin-parse', 'TypechoPlugin\DouyinVideo\Action');

        return _t('插件已启用，文章编辑器将出现「插入抖音视频」按钮。');
    }

    public static function deactivate()
    {
        Helper::removeAction('douyin-parse');
        return _t('插件已禁用');
    }

    public static function config(Form $form)
    {
        $cookie = new Form\Element\Textarea(
            'cookie',
            null,
            'ttwid=1%7CRejU7p-9wM-Qu_kG3IwTGTUCLrZHqB14nkyV-aaViJI%7C1790666684%7Cc31a024ddfe52459acaff945bb806643c229e0682cbced738176c70945f676a2',
            _t('抖音 Cookie（可选）'),
            _t('解析被风控拦截时，从浏览器开发者工具复制 douyin.com 的 Cookie 填入可提高成功率；默认值是一个公共 ttwid')
        );
        $form->addInput($cookie);

        $ratio = new Form\Element\Select(
            'ratio',
            ['1080p' => _t('1080P'), '720p' => _t('720P'), '540p' => _t('540P'), '360p' => _t('360P')],
            '1080p',
            _t('默认画质'),
            _t('插入的播放地址请求的清晰度')
        );
        $form->addInput($ratio);
    }

    public static function personalConfig(Form $form)
    {
    }

    /**
     * 编辑器注入按钮与弹窗（write-post / write-page 的 bottom 钩子）
     */
    public static function renderEditor($post = null)
    {
        $options = Helper::options();
        $parseUrl = $options->index . '/action/douyin-parse';
        $jsFile = __DIR__ . '/assets/editor.js';
        $version = is_file($jsFile) ? filemtime($jsFile) : '1';

        echo '<style>
.dy-editor-bar{margin:6px 0}
#wmd-button-bar .wmd-button-row li.dy-editor-button span{display:flex;align-items:center;justify-content:center}
#wmd-button-bar .wmd-button-row li.dy-editor-button svg{pointer-events:none}
.dy-mask{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center}
.dy-dialog{width:440px;max-width:92%;background:#fff;border-radius:6px;padding:18px 20px;box-shadow:0 6px 30px rgba(0,0,0,.25)}
.dy-dialog h3{margin:0 0 10px;font-size:15px}
.dy-dialog .dy-tip{margin:0 0 10px;color:#999;font-size:12px;line-height:1.6}
.dy-dialog textarea{width:100%;box-sizing:border-box;resize:vertical}
.dy-dialog .dy-status{min-height:18px;margin:8px 0;font-size:12px}
.dy-dialog .dy-status.err{color:#b94a48}
.dy-dialog .dy-actions{text-align:right}
</style>' . "\n";

        echo '<script>window.douyinVideoConfig = { parseUrl: ' . json_encode($parseUrl) . ' };</script>' . "\n";
        echo '<script src="' . $options->pluginUrl . '/DouyinVideo/assets/editor.js?v=' . $version . '"></script>' . "\n";
    }

    /**
     * 前端 head 输出 referrer meta：
     * same-origin 使跨域视频请求不带 Referer 绕过抖音 CDN 防盗链，
     * 同时保留站内 Referer 不影响 Typecho 评论来源校验。
     * 已存在（核心已输出 / 主题模板硬编码 / 其他插件注入）则不添加。
     */
    public static function outputHeader($header, $widget)
    {
        if (self::referrerMetaExists(is_string($header) ? $header : '')) {
            return;
        }
        echo '<meta name="referrer" content="same-origin">' . "\n";
    }

    private static function referrerMetaExists(string $header): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        // 1. 核心 header 输出中已包含
        $cached = self::containsReferrerMeta($header);

        // 2. 当前主题 header.php 模板中已硬编码
        if (!$cached) {
            try {
                $options = Helper::options();
                $themeHeader = $options->themeFile($options->theme, 'header.php');
                if (is_file($themeHeader)) {
                    $cached = self::containsReferrerMeta((string) @file_get_contents($themeHeader));
                }
            } catch (\Throwable $e) {
                // 主题信息异常时忽略
            }
        }

        // 3. 其他插件在同一钩子上注入（如 VideoCollector）
        if (!$cached) {
            $handles = \Typecho\Plugin::export()['handles'] ?? [];
            foreach ($handles as $componentKey => $callbacks) {
                if (stripos($componentKey, 'archive:header') === false) {
                    continue;
                }
                foreach ((array) $callbacks as $callback) {
                    $file = self::callbackSourceFile($callback);
                    if (
                        $file !== null
                        && 0 !== strcasecmp(dirname($file), __DIR__)
                        && self::containsReferrerMeta((string) @file_get_contents($file))
                    ) {
                        $cached = true;
                        break 2;
                    }
                }
            }
        }

        return $cached;
    }

    private static function containsReferrerMeta(string $content): bool
    {
        return stripos($content, 'name="referrer"') !== false
            || stripos($content, "name='referrer'") !== false;
    }

    private static function callbackSourceFile($callback): ?string
    {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $reflection = new \ReflectionClass(substr($callback, 0, strpos($callback, '::')));
            } elseif (is_array($callback) && !empty($callback[0])) {
                $reflection = new \ReflectionClass($callback[0]);
            } else {
                return null;
            }
            $file = $reflection->getFileName();
            return is_string($file) && is_file($file) ? $file : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
