# DouyinVideo - 抖音视频编辑器插件

在 Typecho 后台编辑器中一键解析抖音分享链接，并插入**带封面、默认不加载视频**的 `<video>` 标签。是 DouyinParse 的增强版（解析接口需登录，返回播放地址 + 封面）。

- **版本**：1.0.0
- **作者**：xiao
- **兼容**：Typecho 1.2 / 1.3（需 PHP cURL 扩展）

## 功能特性

- **编辑器按钮**：在文章 / 独立页面编辑页的 Markdown 工具栏追加「抖音」按钮（轮询挂载，兼容异步创建的工具栏）；无 Markdown 工具栏时回退为文本框上方的独立按钮，工具栏随后出现时自动切换
- **解析弹窗**：粘贴整段分享文案（可含文字），自动提取链接并解析出**视频地址与封面图**，支持 `Ctrl/Cmd+Enter` 提交、`Esc` 关闭
- **智能插入**：根据封面图横竖屏自适应样式——横屏宽度撑满，竖屏高度自适应（最高 60vh）居中显示
- **按需加载**：插入的标签带 `preload="none"`，文章页默认只显示封面图，点击播放才发起视频请求，不浪费流量
- **防盗链处理**：为前台页面注入 `<meta name="referrer" content="same-origin">` 绕过抖音 CDN 防盗链；注入前做三重去重检测（核心 header / 主题模板硬编码 / 其他插件注入），避免重复输出
- **接口保护**：解析接口 `/action/douyin-parse` 仅登录用户可用

## 安装

1. 将 `DouyinVideo` 文件夹复制到 `usr/plugins/` 目录
2. 后台「控制台 → 插件」中启用
3. 在插件设置中按需修改配置

## 插件配置

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| 抖音 Cookie（可选） | 公共 ttwid | 解析被风控拦截时，从浏览器开发者工具复制 `douyin.com` 的 Cookie 填入可提高成功率 |
| 默认画质 | 1080p | 可选 1080p / 720p / 540p / 360p |

## 使用方法

1. 编辑文章 / 页面时点击工具栏的抖音图标
2. 在弹窗中粘贴分享链接或整段分享文案，例如：

   ```
   7.79 复制打开抖音... https://v.douyin.com/xxxxxx/
   ```

3. 点击「解析并插入」，编辑器光标处会插入如下标签：

   横屏视频（宽度撑满）：

   ```html
   <video src="https://aweme.snssdk.com/aweme/v1/play/?video_id=...&ratio=1080p&line=0"
          poster="封面图地址" controls preload="none" playsinline
          style="width:100%"></video>
   ```

   竖屏视频（高度自适应，最高 60vh）：

   ```html
   <video src="https://aweme.snssdk.com/aweme/v1/play/?video_id=...&ratio=1080p&line=0"
          poster="封面图地址" controls preload="none" playsinline
          style="display:block;max-width:100%;max-height:60vh;width:auto;height:auto"></video>
   ```

标签前后会自动补换行，避免 Markdown 把标签并入相邻段落。横竖屏由封面图的宽高自动判断（封面加载失败按横屏处理）。

## 解析接口

```
GET {站点地址}/action/douyin-parse?url={分享链接}
```

- **需登录**（未登录返回 `code:1` 提示）
- 成功响应：

```json
{
  "code": 0,
  "url": "https://aweme.snssdk.com/aweme/v1/play/?video_id=...&ratio=1080p&line=0",
  "cover": "https://..."
}
```

## 工作原理

1. **净化链接**：从输入中提取第一个 `http(s)://` 链接
2. **展开短链**：cURL 跟随重定向（PC UA），拿到长链接并提取 `video/{id}` / `note/{id}`
3. **请求分享页**：以**移动端 UA** 访问 `iesdouyin.com/share/video/{id}`（PC 分享页有 `__ac_nonce` 反爬拦截），解析 `window._ROUTER_DATA` / `window.__INITIAL_STATE__`
4. **提取 video_id**：遍历 `play_addr` / `download_addr` / `play_addr_lowbr` 多个节点，`video_id` 正则为 `^v\d{1,2}[0-9a-z]{14,}$`（**不硬编码 v0/v1e 等版本前缀**），也支持从 `uri` / `url_list` 的播放 API 中提取 `video_id=` 参数；结构变化时递归兜底搜索
5. **提取封面**：依次尝试 `cover` / `origin_cover` / `dynamic_cover`
6. **ttwid 自愈**：页面数据被风控清空时，用响应头下发的新 `ttwid` 替换 Cookie 中的旧值并重试一次

## 文件结构

```
DouyinVideo/
├── Plugin.php        # 插件主体：编辑器注入、referrer meta 去重注入、Action 注册
├── Action.php        # 解析接口（/action/douyin-parse，需登录）
├── assets/
│   └── editor.js     # 编辑器按钮、弹窗、video 标签生成
└── README.md
```

## 与 DouyinParse 的区别

| | DouyinParse | DouyinVideo |
| --- | --- | --- |
| 插入内容 | 播放 URL 纯文本 | 带封面的 `<video>` 标签 |
| 返回封面 | 否 | 是 |
| 接口权限 | 公开（CORS 全开） | 仅登录用户 |
| 画质选项 | 540p ~ 2160p | 360p ~ 1080p |
| 风控自愈 | 无 | ttwid 自动替换重试 |

两个插件功能重叠，**二选一启用即可**；若同时启用，referrer meta 注入做了去重，不会重复输出。

## 注意事项

- 依赖 cURL 扩展，且未校验 SSL 证书
- 解析依赖抖音分享页结构，被风控拦截或结构变化时会失败；更换 Cookie 或等待 ttwid 自愈
- 播放地址有 Referer 防盗链，请勿移除插件注入的 referrer meta（`same-origin` 同时保留了站内评论所需的 Referer，不影响 Typecho 评论来源校验）
