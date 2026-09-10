<div align="center">

# 🗺️ Footprint Map · 足迹地图

A personal travel-footprint map for WordPress, powered by [AMap (高德地图) JS API](https://lbs.amap.com/).  
在 WordPress 里记录「你去过的地方」，并把足迹渲染成一张可交互的世界地图。

[![Version](https://img.shields.io/badge/version-1.0.1-blue)](https://github.com/aermisty/footprintmap/releases)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.2-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPLv2-green)](#license)
[![AMap](https://img.shields.io/badge/AMap-JS%20API%20v2.0-FF6A00)](https://github.com/aermisty/footprintmap)

**简体中文** · [English summary](#english-summary)

</div>

---

**Footprint Map** 是一个中文友好的 WordPress 插件：你在后台用高德地图**点选**去过的地方（自动取经纬度、反查省市国家、可关联多篇文章），在前台用一行短代码 `[footprintmap]` 把全部足迹渲染成一张支持**聚合、点击放大、行政区着色**的可交互世界地图。

- ✅ 基于高德地图 JS API v2.0，国内加载快、无 Google 依赖
- ✅ 国内省级行政区 / 国外国家自动着色，边界数据内置、零外部请求
- ✅ 点位聚合 + 点击拆开，密集地区也能逐个看清
- ✅ 一个地点可关联多篇文章，前台以文章封面图呈现
- ✅ CSV 导入 / 导出，数据可迁移、可备份

---

## 📦 一、功能特性

| 特性         | 说明                                                                  |
| ---------- | ------------------------------------------------------------------- |
| 🖱️ 后台点选建点 | 后台内置地图，单击选点即自动取经纬度并反查**省 / 市 / 国家**，可自定义名称、随时删除                     |
| 🔗 关联多篇文章  | 一个地点可关联**多篇文章**；可选用「标签名」只列出打了指定标签的文章                                |
| 🔴 前端自动聚合  | 缩小时近邻点合并为**红色圆点 + 白色数字**，放大后按就近关系自动拆分                               |
| ➕ 点击聚合点放大  | 点聚合圆点即聚焦放大；密集区支持**逐级点开**直到全部单点可见                                    |
| 🖼️ 单点样式   | 关联了文章的单点显示**文章特色图**；无文章默认显示红色小圆点（可在后台设置一张**默认点位图**统一观感）             |
| 💬 点击气泡    | 国内点显示省份、国外点显示国家；有文章则顶部展示封面、下方列出全部文章标题，右上角 × 关闭                      |
| 🗺️ 行政区着色  | 国内点把所在**省级行政区**填浅绿（含港澳台与南海诸岛归属）；国外点用内置世界边界填充；缺国名/省名的点按经纬度**自动推断归属** |
| 🧭 视野与控件   | 默认世界广角视野，右下角显示已去地点数，右上角 **+ / − / home** 缩放复位                       |
| 📄 短代码     | 任意页面插入 `[footprintmap]` 即可渲染                                        |
| 💾 CSV 迁移  | 一键导出全部点位；导入支持表头/无表头、多种编码自动识别、覆盖式重建                                  |
| 🌐 合规友好    | 中国省级边界符合国家测绘规范（详见下方「行政区着色的合规与实现」）                                   |

---

## ⚙️ 二、环境要求

| 依赖 | 要求 |
|------|------|
| WordPress | 5.6+ / 6.x |
| PHP | 7.2+ |
| 高德开放平台账号 | 需申请 **Web 端 (JS API) 的 Key** 与配套「安全密钥」 |
| 网络 | 前台需能访问 `webapi.amap.com`（加载高德地图与边界由本地数据提供，无 QPS 压力） |

---

## 🚀 三、快速开始

### 1. 安装

**方式 A（推荐，zip）**：把本仓库打包为 zip，在 WordPress → 后台 → 插件 → 安装插件 → 上传 → 启用。

**方式 B（目录）**：将本插件目录（含 `footprintmap.php`、`admin/`、`public/` 等）放到 `wp-content/plugins/` 下 → 后台启用「Footprint Map 足迹地图」。

### 2. 配置高德 Key（必填）

1. 到 [高德开放平台](https://console.amap.com/) 注册并创建「Web 端 (JS API)」应用。
2. 添加 Key，并复制该 Key 对应的 **「安全密钥」(jscode)**。
3. 在 Key 设置里把安全密钥与 Key **绑定**，并配置**域名白名单**为你的站点域名。
4. 回 WordPress → **足迹地图 → 地图设置**，填入 **Key** 与 **安全密钥** → 保存。

### 3. 使用

```text
[footprintmap]
```

把短代码插入任意文章 / 页面即可。

---

## 🧭 四、使用手册

### 添加一个地点
1. 进入 **足迹地图 → 地图管理**。
2. 在左侧地图上**单击**你去过的地方（或用顶部搜索框定位）。
3. 右侧自动回填经纬度与省 / 市 / 国家，输入自定义地点名。
4. （可选）在「关联文章」下拉里选择要关联的文章，可多选。
5. 点 **保存标记**。下方「已标记地点」列表支持搜索、按省/国家筛选、按关联文章数排序、分页；点某条可继续编辑或删除。

### 关联文章如何按标签过滤
「关联文章标签名」默认**留空 = 列出全部文章**；若只想让打了某些标签的文章出现在下拉里，在**地图设置**里填入标签名（可多个，用英文逗号分隔，如 `旅行,攻略`）。

### CSV 导入 / 导出
位于 **地图管理** 页顶部：

- **导出**：生成 `footprintmap-日期.csv`（UTF-8 带 BOM，Excel 直接打开不乱码；不含 `id`）。
- **导入**：**覆盖更新**——先清空全部旧点、再按 CSV 重建，导入后列表严格等于文件内容（**不可撤销，请先导出备份**）。
  - 支持带表头（中/英文列名自动对应）或无表头（固定顺序 `name,city,province,country,lat,lng,post_ids`）。
  - `post_ids` 用分号分隔文章 ID，如 `12;34`。
  - 自动识别 UTF-8（含/不含 BOM）、UTF-16、GB18030/GBK 编码。

---

## 🌏 五、行政区着色的合规与实现

- **中国国内地点**：仅把足迹所在**省级行政区**填浅绿（支持台湾、香港 、澳门；海南省边界含南海诸岛，台湾含钓鱼岛、赤尾屿）。省级边界由插件内置 `public/js/footprintmap-provinces.js` 提供（34 个省级行政区，本地绘制、零高德请求）。
- **中国之外地点**：高德不提供国外国家边界，故用内置简化世界边界 `public/js/footprintmap-world.js` 本地绘制该国家范围。
- **涉界争议国剔除**：部分第三方世界数据会将藏南、阿克塞钦等划入印度，故插件对**印度不做国家填充（仅显示点位）**，且内置世界数据不会用于绘制中国及其省级区域。

---

## 📁 六、目录结构

> 本仓库根即插件根：克隆后把整个目录放入 `wp-content/plugins/` 即可启用。

```
footprintmap/                          # 仓库根 = 插件根
├── README.md                          # 本说明（GitHub 首页展示）
├── LICENSE                            # GPL v2 开源协议
├── .gitignore
├── footprintmap.php                   # 主插件文件（激活建表 / 菜单 / AJAX / 短代码 / 资源加载）
├── admin/
│   ├── map-admin-page.php             # 后台地图管理页
│   ├── settings-page.php              # 高德 Key / 设置页
│   ├── css/admin.css
│   └── js/
│       ├── admin-map.js               # 后台选点 / 反查 / 文章关联 / 列表分页筛选排序
│       └── media-picker.js            # 「默认点位图片」媒体库选择器
├── public/
│   ├── css/footprintmap.css
│   └── js/
│       ├── footprintmap.js            # 前台渲染 / 聚合 / 气泡 / 行政区着色 / 缩放控件
│       ├── footprintmap-world.js      # 世界国家边界（国外点着色）
│       └── footprintmap-provinces.js  # 中国 34 个省级行政区边界（含港澳台与南海诸岛）
└── uninstall.php                      # 卸载时清理数据表与设置
```

地点数据存储于自定义表 `wp_footprintmap_locations`（插件卸载时会一并清理）。

---

## 🛠️ 七、开发者指引

- 高德 JS 通过**轮询 `window.AMap` 就绪**后再初始化，规避脚本加载时序竞态；缩放/移动只在 `zoomend` / `moveend` 时**一次性重绘**，保证动画流畅。
- 聚合采用**屏幕距离网格哈希 + BFS 归并**（O(n)），避免逐点全表 O(n²) 扫描。
- 前台点位数据用 transient 缓存（10 分钟 TTL）并在后台改动时显式失效，降低数据库压力。
- 主要逻辑都在 JS，纯前端即插即用；后端 PHP 只负责数据与配置。

**改动后自检**：`node --check` 校验 JS 语法；PHP 无 php lint 环境时可用括号配平做基本检查。

---

## ❓ 八、常见问题

| 问题 | 排查 |
|------|------|
| 地图空白 / 无法加载 | 高德 Key 域名白名单未包含站点、或 Key/安全密钥不匹配。检查**地图设置**与控制台。 |
| 前台只有聚合点、放不大 | 多为旧版 JS 缓存未更新——强制刷新 / 清缓存，确认加载的是最新版脚本。 |
| 国外国家不显示着色 | 该国不在内置世界边界中，或保存的国家名无法匹配；印度因合规原因仅显示点位。 |
| 气泡里的城市名不对 | 城市名来自反地理编码自动识别，可把地点名改成你的自定义称呼。 |

---

## 📝 License

本项目基于 **GPLv2**（GNU General Public License v2）发布，与 WordPress 生态保持一致。

- © 2026 [Aermisty](https://github.com/aermisty)

---

## English summary

> **Footprint Map** is a WordPress plugin that lets you mark places you've visited on an AMap-based map in the admin, and renders your whole footprint as an interactive, clustered world map on the front end via the shortcode `[footprintmap]`.

- **Backend**: click anywhere on a map to drop a pin → geocode + reverse-geocode (province / city / country) automatically; attach multiple posts to one location.
- **Frontend**: nearby points merge into red clusters with a count that split apart as you zoom in; single points show the post's featured image (or a configurable default image); clicking a point opens a bubble with the region name and linked articles.
- **Region coloring**: domestic points tint their province light green (incl. Taiwan, Hong Kong, Macao & South China Sea islands); foreign points tint their country using bundled boundary data; points without a stored country are inferred by coordinates.
- **CSV** import/export for migration & backup. AMap key + security code required.
- Full Chinese documentation above; the underlying boundaries comply with PRC surveying & mapping regulations (India is intentionally **not** shaded due to disputed-border data).

**License**: GPLv2.
