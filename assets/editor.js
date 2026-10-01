/**
 * 抖音视频 - 编辑器「插入抖音视频」按钮与弹窗
 * 依赖 Plugin.php 注入的 window.douyinVideoConfig.parseUrl
 */
(function () {
    'use strict';

    var cfg = window.douyinVideoConfig || {};
    if (!cfg.parseUrl) {
        return;
    }

    var button = null;
    var bar = null;
    var mounted = ''; // '' | 'bar' | 'toolbar'
    var polls = 0;
    var timer = null;
    var mask = null;
    var busy = false;

    function getTextarea() {
        return document.getElementById('text');
    }

    var TIKTOK_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="display:block;pointer-events:none;"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>';

    // 工具栏按钮：与原生按钮一致采用 <li><span> 结构，几何样式（3px 内边距、
    // 右间距、垂直居中、hover 灰底、20x20 图标块）均由 admin CSS 提供
    function createToolbarButton() {
        var li = document.createElement('li');
        li.id = 'wmd-douyin-button';
        li.className = 'wmd-button dy-editor-button';
        li.title = '插入抖音视频';
        li.innerHTML = '<span>' + TIKTOK_ICON + '</span>';
        li.addEventListener('click', openDialog);
        return li;
    }

    // 非 Markdown 模式的独立按钮（文本按钮，套用 admin 按钮样式）
    function createFallbackButton() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-s dy-editor-button dy-bar-button';
        btn.title = '插入抖音视频';
        btn.textContent = '插入抖音视频';
        btn.addEventListener('click', openDialog);
        return btn;
    }

    function mount() {
        var textarea = getTextarea();
        if (!textarea) {
            stop();
            return;
        }

        var row = document.getElementById('wmd-button-row');
        if (row) {
            // 非 li 结构（独立按钮模式）需重建为工具栏按钮
            if (button && button.tagName !== 'LI') {
                button = null;
            }
            button = button || createToolbarButton();
            if (button.parentNode !== row) {
                row.appendChild(button);
            }
            if (bar && bar.parentNode) {
                bar.parentNode.removeChild(bar);
            }
            mounted = 'toolbar';
            stop();
            return;
        }

        // 无 Markdown 工具栏时（关闭 markdown / 非 markdown 文章未确认前），
        // 延迟片刻在文本框上方放置独立按钮
        if (mounted !== 'bar' && polls > 6) {
            button = button || createFallbackButton();
            if (!bar) {
                bar = document.createElement('p');
                bar.className = 'dy-editor-bar';
            }
            if (bar.parentNode !== textarea.parentNode) {
                textarea.parentNode.insertBefore(bar, textarea);
            }
            if (button.parentNode !== bar) {
                bar.appendChild(button);
            }
            mounted = 'bar';
        }
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    // 工具栏由 pagedown 编辑器在 ready 后异步创建，轮询挂载；
    // 独立按钮挂载后仍继续轮询，若工具栏随后出现则自动切换
    timer = setInterval(function () {
        polls++;
        if (polls > 90) {
            stop();
            return;
        }
        mount();
    }, 400);

    // ==================== 弹窗 ====================

    function openDialog() {
        if (mask) {
            var input = mask.querySelector('textarea');
            input.focus();
            input.select();
            return;
        }

        mask = document.createElement('div');
        mask.className = 'dy-mask';
        mask.innerHTML =
            '<div class="dy-dialog">' +
            '<h3>插入抖音视频</h3>' +
            '<p class="dy-tip">粘贴抖音分享链接或整段分享文案（可含文字），将自动提取链接并解析出视频地址与封面。文章里默认只显示封面，点击播放才加载视频。</p>' +
            '<textarea rows="3" placeholder="例如：7.79 复制打开抖音... https://v.douyin.com/xxxx/"></textarea>' +
            '<p class="dy-status"></p>' +
            '<div class="dy-actions">' +
            '<button type="button" class="btn" data-act="cancel">取消</button> ' +
            '<button type="button" class="btn primary" data-act="submit">解析并插入</button>' +
            '</div></div>';
        document.body.appendChild(mask);

        mask.querySelector('[data-act="cancel"]').addEventListener('click', closeDialog);
        mask.querySelector('[data-act="submit"]').addEventListener('click', submit);
        mask.addEventListener('mousedown', function (e) {
            if (e.target === mask) {
                closeDialog();
            }
        });
        document.addEventListener('keydown', onKeydown);

        mask.querySelector('textarea').focus();
    }

    function onKeydown(e) {
        if (!mask) {
            return;
        }
        if (e.key === 'Escape') {
            closeDialog();
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            submit();
        }
    }

    function closeDialog() {
        if (!mask) {
            return;
        }
        document.removeEventListener('keydown', onKeydown);
        mask.parentNode.removeChild(mask);
        mask = null;
        busy = false;
    }

    function setStatus(text, isError) {
        var el = mask ? mask.querySelector('.dy-status') : null;
        if (el) {
            el.textContent = text || '';
            el.className = 'dy-status' + (isError ? ' err' : '');
        }
    }

    function setBusy(state) {
        busy = state;
        if (mask) {
            mask.querySelector('[data-act="submit"]').disabled = state;
            mask.querySelector('[data-act="cancel"]').disabled = state;
        }
    }

    function submit() {
        if (busy || !mask) {
            return;
        }

        var textarea = mask.querySelector('textarea');
        var raw = textarea.value.trim();
        if (!raw) {
            setStatus('请先粘贴抖音分享链接', true);
            textarea.focus();
            return;
        }

        setBusy(true);
        setStatus('正在解析…');

        fetch(cfg.parseUrl + '?url=' + encodeURIComponent(raw), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (data && data.code === 0 && data.url) {
                    insertVideo(data.url, data.cover || '');
                    closeDialog();
                } else {
                    setBusy(false);
                    setStatus((data && data.msg) || '解析失败，请稍后重试', true);
                }
            })
            .catch(function (err) {
                setBusy(false);
                setStatus('请求失败：' + err.message, true);
            });
    }

    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * 插入 <video>：poster=封面、src=视频地址、preload=none
     * 默认不加载视频，播放器只显示封面图，点击播放才发起请求
     */
    function insertVideo(url, cover) {
        var editor = getTextarea();
        if (!editor) {
            return;
        }

        /**
         * 横屏：宽度撑满；竖屏：高度自适应可视窗口（60vh），宽度按封面比例收缩并居中。
         * preload="none" 时元素尺寸由封面图决定，故可用封面判断横竖屏
         */
        var build = function (style) {
            var tag = '<video src="' + escapeAttr(url) + '"' +
                (cover ? ' poster="' + escapeAttr(cover) + '"' : '') +
                ' controls preload="none" playsinline style="' + style + '"></video>';

            var start = editor.selectionStart || 0;
            var end = editor.selectionEnd || 0;
            var before = editor.value.substring(0, start);
            var after = editor.value.substring(end);

            // 前后补换行，避免 Markdown 把标签并入相邻段落
            if (before !== '' && !/\n\s*$/.test(before)) {
                tag = '\n' + tag;
            }
            if (after !== '' && !/^\s*\n/.test(after)) {
                tag = tag + '\n';
            }

            editor.value = before + tag + after;
            var pos = (before + tag).length;
            editor.setSelectionRange(pos, pos);
            editor.focus();
            editor.dispatchEvent(new Event('input', { bubbles: true }));
        };

        if (!cover) {
            build('width:100%');
            return;
        }

        // 加载封面图取宽高判断横竖屏（Image 对象读取 naturalWidth 不受跨域限制），失败按横屏兜底
        var probe = new Image();
        probe.onload = function () {
            build(probe.naturalHeight > probe.naturalWidth
                ? 'display:block;max-width:100%;max-height:60vh;width:auto;height:auto'
                : 'width:100%');
        };
        probe.onerror = function () {
            build('width:100%');
        };
        probe.src = cover;
    }
})();
