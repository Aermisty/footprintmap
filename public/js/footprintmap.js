/* =========================================================
 * FootprintMap Frontend — 旅行足迹地图
 * 聚合（近邻点合并为红色圆点+白色数字）→ 放大自动拆离；
 * 单独点：有关联文章→封面图，无文章→红色圆点；
 * 点击弹气泡（文章卡片/城市名）；区划着色：国内点把「所在省级行政区」填浅绿
 * （含港澳台，用内置省级边界本地绘制；无省名时回退整中国），国外点把「所在国家」填浅绿
 * （用内置世界边界）；右下角计数；右上角 + - home。
 * ========================================================= */
(function () {
	'use strict';

	var DATA = window.FootprintMapData || {};
	var CLUSTER_DIST = DATA.cluster || 50; // 屏幕像素聚合阈值
	var map = null;
	var allLocations = [];       // 已去的点位
	var markers = [];            // 当前渲染的 marker（含聚合）
	var infoWindow = null;       // 单例气泡
	var layerPolygons = [];      // 已绘制的区划多边形（国家着色用）
	var busy = false;
	var closeBound = false;      // 是否已绑定气泡关闭按钮事件委托
	var _bubbleOpenTick = 0;     // 气泡最近一次打开的时间戳（防地图 click 误关刚开的气泡）
	var frontStarted = false;    // 是否已初始化地图（防高德回调与轮询重复执行）
	var frontBooted = false;     // 是否已启动就绪检测

	function $(sel, root) { return (root || document).querySelector(sel); }

	// ---------- 区划配色（固定，不随主题切换） ----------
	// 底图固定用淡灰白 whitesmoke；行政区统一用浅绿。
	// 配色原则：边界不要太深、填充不要太淡，两者色差收窄，观感统一柔和。
	var MAP_STYLE = 'amap://styles/whitesmoke';
	var REGION_STYLE = {
		fillColor: '#b8e2bd',   // 填充：清晰但不刺眼的浅绿
		fillOpacity: 0.50,      // 让范围明显可见，又不完全盖住底图
		strokeColor: '#8fca97', // 描边：与填充同色系的柔和绿，色差小、不显深
		strokeOpacity: 0.85,
		strokeWeight: 1.5
	};

	// 默认视野：以中国为中心的全世界广角（初载与 home 复位共用）。
	var DEFAULT_VIEW = { zoom: 3.4, center: [107, 30] };

	// 建图前的初始视野近似值，减少地图创建后立刻定位的跳动感。
	function initialApproxView() {
		return { center: DEFAULT_VIEW.center, zoom: Math.max(DEFAULT_VIEW.zoom, 3.4) };
	}

	// ---------- AMap 就绪后初始化 ----------
	// 自行轮询 window.AMap 就绪后再初始化，规避高德 callback 触发时机与脚本加载的竞态；
	// 保留 __footprintmap_front_ready 兼容仍带 callback 的旧缓存。
	function initFrontMap() {
		if (frontStarted) return;
		var el = document.getElementById('footprintmap-container');
		if (!el) return;
		frontStarted = true;
		// 给个近似初始视野，减少随后定位的跳动感
		var initView = initialApproxView();
		map = new AMap.Map(el, {
			zoom: initView.zoom,
			center: initView.center,
			viewMode: '3D', // 地图模式
			pitch: 0, // 地图俯仰角度，有效范围 0 度- 83 度，不想要3D效果此处设置为0即可
			mapStyle: MAP_STYLE // 固定淡灰白底图
		});
		buildControls(el);
		// 直接复用 DATA.locations（其 posts/featured 等字符串值本就不可变共享，无需再复制一份数组与包装对象）。
		// 仅把后续算法强依赖的 id/lng/lat 在**原对象上**归一为 Number（原数组中可会以字符串出现，
		// centerOf 求和、经纬度比较等必须为数值，否则会字符串拼接出错）。
		allLocations = DATA.locations || [];
		for (var ai = 0; ai < allLocations.length; ai++) {
			var al = allLocations[ai];
			al.id  = +al.id;
			al.lng = +al.lng;
			al.lat = +al.lat;
			al.posts = al.posts || [];
			al.featured = al.featured || '';
		}

		// 气泡单例
		infoWindow = new AMap.InfoWindow({
			isCustom: true,
			offset: new AMap.Pixel(0, -28),
			autoMove: true
		});
		// 缩放动画结束（zoomend）/ 平移结束（moveend）后再重新聚合渲染与上色：
		// 不监听 zoomchange / mapmove，否则平滑缩放与拖动过程会逐帧全量重建
		// marker，打断地图动画造成明显卡顿；改为结束后一次性更新，动画全程流畅。
		map.on('zoomend', scheduleRender);
		map.on('moveend', scheduleRender);
		// 点击地图空白处关闭已打开的气泡（常规实现下点击标记不会触发地图 click；
		// _bubbleOpenTick 防御个别环境标记点击冒泡到地图时误关刚打开的气泡）。
		map.on('click', function () {
			if (Date.now() - _bubbleOpenTick < 50) return;
			if (infoWindow) infoWindow.close();
		});

		setDefaultView();
		colorRegions();

		// 首次渲染：务必等地图真正完成首屏布局后再做像素投影聚类。
		// 若地图刚 new 出来、容器尚未完成尺寸/投影就立刻调用 lngLatToContainer，
		// 会得到 NaN 或异常坐标，把所有点错误地归进同一网格格而聚成一个大聚合点
		// 且永不拆开（这正是“加载只能看到聚合点、放大也拆不开”的根因）。
		// 故把首次渲染挂到地图 complete 事件；并加定时兜底，防止 complete 已错过。
		var firstRendered = false;
		function doFirstRender() {
			if (firstRendered) return;
			firstRendered = true;
			scheduleRender();
		}
		map.on('complete', doFirstRender);
		setTimeout(doFirstRender, 1500);
	}

	// 保留旧回调名兼容（若某些缓存页面仍带 callback 参数）
	window.__footprintmap_front_ready = function () { initFrontMap(); };

	// 就绪检测：轮询等待地图核心 AMap.Map 就绪后建图（事件一律用 map.on/m.on 实例方法；
	// 区划数据均为内置文件，无需异步插件，故建图门槛只需地图核心）。
	function coreReady() {
		return !!(window.AMap && window.AMap.Map);
	}
	function startWhenReady() {
		if (frontStarted) return;
		if (coreReady()) {
			initFrontMap();
			return true;
		}
		return false;
	}
	window.__footprintmap_start = startWhenReady;
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startWhenReady);
	} else {
		startWhenReady();
	}
	if (!coreReady()) {
		// 最多轮询约 10 秒，避免极端情况下无限占用
		var _frontPoll = setInterval(function () {
			if (coreReady()) {
				clearInterval(_frontPoll);
				startWhenReady();
			}
		}, 250);
		setTimeout(function () { clearInterval(_frontPoll); }, 10000);
	}

	// ============================================================
	// 右上角 + - home 控件
	// ============================================================
	function buildControls(el) {
		var div = document.createElement('div');
		div.className = 'tm-zoomctrl';
		var svgHome = '<svg viewBox="0 0 24 24"><path d="M12 3 2 12h3v8h5v-5h4v5h5v-8h3L12 3z"/></svg>';
		div.innerHTML =
			'<button type="button" class="tm-zin" title="放大">+</button>' +
			'<button type="button" class="tm-zout" title="缩小">-</button>' +
			'<button type="button" class="tm-home" title="复位(显示全部足迹点)">' + svgHome + '</button>';
		el.appendChild(div);
		div.querySelector('.tm-zin').addEventListener('click', function () { map.zoomIn(); });
		div.querySelector('.tm-zout').addEventListener('click', function () { map.zoomOut(); });
		// home 复位带平滑动画（初始化/自适应仍走 setDefaultView 的瞬间定位）；
		// 动画结束（zoomend/moveend）会自动重绘，再补一个定时兜底确保 marker 落位。
		div.querySelector('.tm-home').addEventListener('click', function () {
			map.setZoomAndCenter(DEFAULT_VIEW.zoom, DEFAULT_VIEW.center, false, 900);
			setTimeout(scheduleRender, 950);
		});
	}

	// 默认视野（初载 / home 复位 / fitToAll 共用）。
	function setDefaultView() {
		map.setZoomAndCenter(DEFAULT_VIEW.zoom, DEFAULT_VIEW.center, false, 0);
	}

	function fitToAll() {
		setDefaultView();
	}

	// ============================================================
	// 行政区划上色
	//  · 国内坐标点 → 只把「足迹所在省级行政区」填浅绿（省级精确着色，含港澳台；
	//    无法匹配到省名时**不做任何填充**——不整国填绿，仅显示点位）。
	//  · 国外坐标点 → 把所在「国家」填浅绿。
	// 全部用插件内置边界数据本地绘制，零网络请求、零 QPS 压力、秒开。
	// ============================================================
	// 把“省名”归一为 FOOTPRINTMAP_PROVINCES.polys 的键（核心名）：
	// 优先走 alias 表（“中国香港/香港特别行政区/香港”等），再按“省/市/自治区/特别行政区”后缀剥离。
	var _PROV_SUFFIX = ['维吾尔自治区', '壮族自治区', '回族自治区', '特别行政区', '自治区', '省', '市'];
	function _provinceKey(name) {
		var P = window.FOOTPRINTMAP_PROVINCES;
		if (!P || !name) return '';
		name = String(name).trim();
		if (P.alias && P.alias[name]) return P.alias[name];
		if (P.polys && P.polys[name]) return name;
		for (var i = 0; i < _PROV_SUFFIX.length; i++) {
			var s = _PROV_SUFFIX[i];
			if (name.length > s.length && name.indexOf(s, name.length - s.length) !== -1) {
				var core = name.slice(0, name.length - s.length);
				if (P.polys && P.polys[core]) return core;
			}
		}
		return '';
	}

	// 通用：把一组边界环（每环为 [ [lng,lat], ... ]）绘制为浅绿多边形
	function _paintRings(rings) {
		var c = REGION_STYLE;
		rings.forEach(function (ring) {
			if (!ring || ring.length < 3) return;
			var poly = new AMap.Polygon({
				path: ring,
				strokeColor: c.strokeColor,
				strokeWeight: c.strokeWeight,
				strokeOpacity: c.strokeOpacity,
				fillColor: c.fillColor,
				fillOpacity: c.fillOpacity,
				zIndex: 5,
				strokeStyle: 'solid'
			});
			poly.setMap(map);
			layerPolygons.push(poly);
		});
	}

	// 把某个省级行政区填绿（省名 → 内置省级边界）。
	function _shadeProvince(province) {
		var key = _provinceKey(province);
		if (!key) return;
		var rings = window.FOOTPRINTMAP_PROVINCES && window.FOOTPRINTMAP_PROVINCES.polys[key];
		if (!rings || !rings.length) return;
		_paintRings(rings);
	}

	// 去过的省级行政区（按省着色，每省只画一次）
	var _shadedProvKeys = {};

	function colorRegions() {
		if (!allLocations.length) return;
		var seenC = {}, seenI = {};
		var W = window.FOOTPRINTMAP_WORLD;

		allLocations.forEach(function (loc) {
			var country  = (loc.country || '').trim();
			var province = (loc.province || '').trim();
			// ① 最强信号：省名能命中中国省级边界 → 必为国内（含台湾/香港/澳门，
			//    即便其 country 被存成了“台湾/香港/澳门”也按国内省着色）。
			if (province) {
				var key = _provinceKey(province);
				if (key) {
					if (!_shadedProvKeys[key]) {
						_shadedProvKeys[key] = true;
						_shadeProvince(province);
					}
					return;
				}
			}
			// ② 省名为空或匹配不到中国省 → 用 country 判定国外并绘制国家。
			//    先解析 country 是否归属中国（“中国/中华人民共和国/CN/China”等，注意不含港澳台，
			//    因港澳台有省名会命中①；此处仅排除真正“中国内地”标成国家的点）。
			if (country) {
				var cnIso = '';
				if (W) {
					var _i = W.zh ? W.zh[country] : undefined;
					if (!_i && W.en) _i = W.en[String(country).toLowerCase()];
					cnIso = _i || '';
				}
				// 真正“中国内地”点（无省名）→ 不做整国填充；其余国家 → 绘制该国。
				if (cnIso === 'CHN') return;
				var ck = 'country:' + country;
				if (seenC[ck]) return;
				seenC[ck] = true;
				drawWorldCountry(country);
				return;
			}
			// ③ country 与 province 皆空（早年存下的点）→ 按经纬度推断归属。
			var iso = inferCountryISO(loc.lng, loc.lat);
			if (iso === 'CHN' || iso === 'HKG' || iso === 'MAC' || iso === 'TWN') {
				// 推断为中国内地/港澳台但无省名 → 不做填充（不画）。
				return;
			}
			if (iso && !NO_SHADE_ISO[iso] && !seenI[iso]) {
				seenI[iso] = true;
				drawCountryByISO(iso);
			}
		});
	}

	// 用内置世界国家边界绘制国外点位所在国。
	// 合规说明：仅绘制“外国”国家范围；中国及其香港/澳门/台湾、藏南/阿克塞钦等一律不由此绘制
	// （国内走 AMap 省界，符合国家测绘规范）。第三方数据的印度边界常把藏南/阿克塞钦划给印度，
	// 故对印度等一律不做第三方填充，仅显示点位。
	var NO_SHADE_ISO = { CHN: 1, HKG: 1, MAC: 1, TWN: 1, IND: 1 };

	// 按 iso3 直接绘制该国多边形（REGION_STYLE）
	function drawCountryByISO(iso) {
		var W = window.FOOTPRINTMAP_WORLD;
		if (!iso || !W || !W.polys) return;
		var rings = W.polys[iso];
		if (!rings || !rings.length) return;
		var c = REGION_STYLE;
		rings.forEach(function (ring) {
			if (!ring || ring.length < 3) return;
			var poly = new AMap.Polygon({
				path: ring,
				strokeColor: c.strokeColor,
				strokeWeight: c.strokeWeight,
				strokeOpacity: c.strokeOpacity,
				fillColor: c.fillColor,
				fillOpacity: c.fillOpacity,
				zIndex: 5,
				strokeStyle: 'solid'
			});
			poly.setMap(map);
			layerPolygons.push(poly);
		});
	}

	// 按国名(中/英)解析 iso3 后绘制
	function drawWorldCountry(name) {
		if (!name) return;
		var W = window.FOOTPRINTMAP_WORLD;
		if (!W || !W.polys) return;
		var iso = W.zh ? W.zh[name] : undefined;
		if (!iso && W.en) iso = W.en[String(name).toLowerCase()];
		if (!iso || NO_SHADE_ISO[iso]) return;
		drawCountryByISO(iso);
	}

	// ---------- 按经纬度推断所在国家（用于 country/province 均空的国外点） ----------
	var _worldIdx = null; // { iso: bbox }
	function buildWorldIdx() {
		var W = window.FOOTPRINTMAP_WORLD;
		if (!W || !W.polys || _worldIdx) return;
		var idx = {};
		Object.keys(W.polys).forEach(function (iso) {
			var rings = W.polys[iso], minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9;
			rings.forEach(function (ring) {
				if (!ring) return;
				for (var i = 0; i < ring.length; i++) {
					var x = ring[i][0], y = ring[i][1];
					if (x < minX) minX = x; if (x > maxX) maxX = x;
					if (y < minY) minY = y; if (y > maxY) maxY = y;
				}
			});
			if (minX <= maxX && minY <= maxY) idx[iso] = { minX: minX, maxX: maxX, minY: minY, maxY: maxY };
		});
		_worldIdx = idx;
	}
	function ptInRing(lng, lat, ring) {
		var inside = false;
		for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
			var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
			if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi)) inside = !inside;
		}
		return inside;
	}
	function inferCountryISO(lng, lat) {
		var W = window.FOOTPRINTMAP_WORLD;
		if (!W || !W.polys) return '';
		buildWorldIdx();
		var found = '';
		Object.keys(W.polys).forEach(function (iso) {
			if (found) return;
			var b = _worldIdx[iso];
			if (!b) return;
			if (lng < b.minX || lng > b.maxX || lat < b.minY || lat > b.maxY) return; // 外包框粗筛
			var rings = W.polys[iso], hits = false;
			for (var r = 0; r < rings.length; r++) {
				if (rings[r] && rings[r].length >= 3 && ptInRing(lng, lat, rings[r])) hits = !hits;
			}
			if (hits) found = iso;
		});
		return found;
	}

	// iso3 → 中文国名（气泡等文字展示用，懒构建一次）。
	// world.zh 里同一 iso 常有多个别名（官方全称、规范通称、口语别称等），
	// 策略：优先用 override 表指定通称；否则取最短候选（去掉“……共和国”这类全称）。
	var _CN_NAME_OVERRIDE = {
		MYS: '马来西亚', // 数据别名有“大马/马来西亚”，最短会误选“大马”
		KOR: '韩国',     // 数据别名有“南韩/韩国/大韩民国”，最短会误选“南韩”
		PRK: '朝鲜'      // 数据别名有“北韩/朝鲜/…”，最短会误选“北韩”
	};
	var _zhNameByISO = null;
	function buildZhNameIdx() {
		var W = window.FOOTPRINTMAP_WORLD;
		if (!W || !W.zh || _zhNameByISO) return;
		_zhNameByISO = {};
		Object.keys(W.zh).forEach(function (cn) {
			var iso = W.zh[cn];
			var cur = _zhNameByISO[iso];
			if (!cur || cn.length < cur.length) _zhNameByISO[iso] = cn;
		});
		// 覆盖表优先于“最短”结果
		Object.keys(_CN_NAME_OVERRIDE).forEach(function (iso) {
			_zhNameByISO[iso] = _CN_NAME_OVERRIDE[iso];
		});
	}
	function countryNameByISO(iso) {
		if (!iso) return '';
		buildZhNameIdx();
		return (_zhNameByISO && _zhNameByISO[iso]) || '';
	}

	// ============================================================
	// 渲染（按屏幕距离动态聚合）
	// ============================================================
	var renderQueued = false;
	function scheduleRender() {
		if (busy) { renderQueued = true; return; }
		busy = true;
		requestAnimationFrame(function () {
			busy = false;
			render();
			if (renderQueued) { renderQueued = false; scheduleRender(); }
		});
	}

	function clearMarkers() {
		markers.forEach(function (m) { m.setMap(null); });
		markers = [];
	}

	function render() {
		clearMarkers();
		if (!allLocations.length) {
			return;
		}

		var groups = clusterByScreenDistance(allLocations, CLUSTER_DIST);

		groups.forEach(function (grp) {
			// 组内若含被用户点开过的成员，强制按单点渲染；用后即清。
			// 这样用户每次点聚合点都能可靠看到具体地点，不再被"密集区永远聚合"困住。
			var hasForce = false;
			for (var fi = 0; fi < grp.length; fi++) {
				if (_forceIndividual[grp[fi].id]) { hasForce = true; break; }
			}
			if (hasForce) {
				grp.forEach(function (loc) { delete _forceIndividual[loc.id]; });
				grp.forEach(function (loc) { renderSingle(loc); });
			} else if (grp.length === 1) {
				renderSingle(grp[0]);
			} else {
				renderCluster(grp);
			}
		});
	}

	/**
	 * 按当前屏幕像素距离聚类：返回点数组的二维数组。
	 * 实现：网格哈希分桶（格宽=dist，距离<dist 的点至多跨一格）+ BFS 归并，
	 * 候选只查 3x3 相邻格，避免逐点全表扫描的 O(n²)~O(n³) 开销；
	 * 归并语义与旧实现一致：与组内任一成员屏幕距离 < dist 即并入。
	 */
	function clusterByScreenDistance(locs, dist) {
		var n = locs.length;
		if (!n) return [];

		// 先将经纬度转像素
		var items = new Array(n);
		var singles = [];
		for (var i = 0; i < n; i++) {
			var px = map.lngLatToContainer(new AMap.LngLat(locs[i].lng, locs[i].lat));
			var xx = (px && px.x !== undefined && px.x !== null) ? px.x : NaN;
			var yy = (px && px.y !== undefined && px.y !== null) ? px.y : NaN;
			if (!isFinite(xx) || !isFinite(yy)) {
				// 坐标暂时无法可靠投影（地图未就绪等）：不参与聚类，直接作单点兜底渲染，
				// 避免 NaN 被归进同一网格格而把不相干点错误聚成一个大聚合点。
				singles.push(locs[i]);
				continue;
			}
			items[i] = { loc: locs[i], x: xx, y: yy };
		}

		var cell = Math.max(1, dist);
		var grid = {};
		function gridKey(gx, gy) { return gx + ',' + gy; }
		for (var g = 0; g < n; g++) {
			if (!items[g]) continue; // 该点是单点兜底，已排除，不参与网格
			var k = gridKey(Math.floor(items[g].x / cell), Math.floor(items[g].y / cell));
			if (!grid[k]) grid[k] = [];
			grid[k].push(g);
		}

		var used = new Array(n).fill(false);
		var groups = [];
		for (var s = 0; s < n; s++) {
			if (!items[s] || used[s]) continue;
			used[s] = true;
			var members = [s];
			var queue = [s];
			while (queue.length) {
				var cur = queue.shift();
				var cgx = Math.floor(items[cur].x / cell);
				var cgy = Math.floor(items[cur].y / cell);
				for (var dx = -1; dx <= 1; dx++) {
					for (var dy = -1; dy <= 1; dy++) {
						var bucket = grid[gridKey(cgx + dx, cgy + dy)];
						if (!bucket) continue;
						for (var b = 0; b < bucket.length; b++) {
							var j = bucket[b];
							if (used[j] || !items[j]) continue;
							var o = items[j];
							var near = false;
							for (var m = 0; m < members.length; m++) {
								var g2 = items[members[m]];
								if (Math.hypot(g2.x - o.x, g2.y - o.y) < dist) { near = true; break; }
							}
							if (near) {
								used[j] = true;
								members.push(j);
								queue.push(j);
							}
						}
					}
				}
			}
			groups.push(members.map(function (idx) { return items[idx].loc; }));
		}
		// 单点兜底直接作为独立分组追加，避免与聚类组混叠
		singles.forEach(function (loc) { groups.push([loc]); });
		return groups;
	}

	function centerOf(group) {
		var lat = 0, lng = 0;
		group.forEach(function (l) { lat += l.lat; lng += l.lng; });
		return [lng / group.length, lat / group.length];
	}

	// ---- 单独点 ----
	function renderSingle(loc) {
		var content;
		var hasArticle = loc.posts && loc.posts.length > 0;
		// 统一默认图片：无文章点若设置了默认图，也用圆形封面样式呈现，与有文章点观感一致。
		var defaultImg = (DATA.defaultImage && String(DATA.defaultImage).trim()) || '';
		var useDefaultImg = !hasArticle && !!defaultImg;

		if (hasArticle && loc.featured) {
			content = '<div class="tm-point" style="background-image:url(\'' + escapeAttr(loc.featured) + '\')"></div>';
		} else if (useDefaultImg) {
			content = '<div class="tm-point" style="background-image:url(\'' + escapeAttr(defaultImg) + '\')"></div>';
		} else if (hasArticle) {
			// 有文章但无特色图：纯灰色圆块占位（无文字/图片），仍可点击弹文章列表
			content = '<div class="tm-point" style="background:#e0e0e0;"></div>';
		} else {
			content = '<div class="tm-point tm-red"></div>';
		}
		var hasCover = (hasArticle && !!loc.featured) || useDefaultImg;
		var m = new AMap.Marker({
			position: [loc.lng, loc.lat],
			content: content,
			anchor: 'center',
			zIndex: 30,
			offset: hasCover ? new AMap.Pixel(0, -2) : new AMap.Pixel(0, 0)
		});
		m.on('click', function () { openBubble(loc); });
		m.setMap(map);
		markers.push(m);
	}

	// ---- 聚合点：红色圆点 + 白色数字 ----
	// 尺寸固定为 18px（比单个无关联红点 16px 略大以便容纳数字，不随合并数量增大）；数字过大时压缩字号避免超出圈径。
	function renderCluster(group) {
		var count = group.length;
		var size = 18;
		var c = centerOf(group);
		var content = '<div class="tm-cluster" style="width:' + size + 'px;height:' + size + 'px;font-size:' + (count >= 100 ? 8 : (count >= 10 ? 9 : 11)) + 'px;">' + count + '</div>';
		var m = new AMap.Marker({
			position: c,
			content: content,
			anchor: 'center',
			zIndex: 40,
			extData: { group: group }
		});
		m.on('click', function () { zoomToSplit(group); });
		m.setMap(map);
		markers.push(m);
	}

	// 临时集合：被用户点开过的聚合点成员，渲染时强制按单点呈现。
	// 解决"密集区永远聚类、用户点了看不到具体点"的问题；用后即清。
	var _forceIndividual = {};

	// 点击聚合圆点 → 平滑放大并尝试拆开。
	// 旧版用 setTimeout 链做 3 次 setZoom(...,true,200) 瞬跳，第二参 true 意味着
	// "立即跳转"（duration 被忽略），且 3 级放大对密集区常常不够。这里改为：
	//   1) 单次 setZoomAndCenter 放大 4 级（≈16x），并带 600ms 平滑动画；
	//   2) 若聚类点成员 ≤ 20，记下成员，渲染时强制按单点呈现，
	//      让"我点开了聚合点就一定看到具体点"成为可预期行为；
	//      上限 20 避免一次性铺出几十上百个互相重叠的标记。
	function zoomToSplit(group) {
		if (group.length < 2) return;
		var STEP = 4;
		var c = centerOf(group);
		var newZoom = Math.min(20, map.getZoom() + STEP);
		map.setZoomAndCenter(newZoom, c, false, 600);

		if (group.length <= 20) {
			group.forEach(function (loc) { _forceIndividual[loc.id] = true; });
		}
	}

	// esc() 与 escapeAttr() 声明在本文件末尾，函数声明会提升（hoisting），故此处可正常调用。

	// ============================================================
	// 气泡
	// ============================================================
	function openBubble(loc) {
		var hasArticle = loc.posts && loc.posts.length > 0;
		var name = loc.name || loc.city || '';
		var sub = buildSub(loc);

		var html = '';
		if (hasArticle) {
			// 有文章点排版：顶部特色图 → [坐标名 空格1-2 省/国] → 各文章标题链接。
			// 取「第一篇有特色图的文章」作顶图；全部无图则不显示顶图。
			var hero = '';
			for (var hi = 0; hi < loc.posts.length; hi++) {
				if (loc.posts[hi].thumb) { hero = loc.posts[hi].thumb; break; }
			}
			html += '<div class="tm-bubble tm-bubble--art">';
			html += '<button type="button" class="tm-close"></button>';
			// 顶图置顶（单篇与多篇一致：都显示第一张有特色图的文章封面）
			if (hero) {
				html += '<div class="tm-bubble-hero" style="background-image:url(\'' + escapeAttr(hero) + '\')"></div>';
			}
			// 坐标名 + 右侧省/国（间距由 CSS .tm-bubble-meta 的 gap 控制，不用全角空格占位）
			html += '<div class="tm-bubble-meta">';
			html += '<span class="tm-bubble-name">' + esc(name) + '</span>';
			if (sub) {
				html += '<span class="tm-bubble-region">' + esc(sub) + '</span>';
			}
			html += '</div>';
			// 全部关联文章标题（图片下方），逐个可点击
			loc.posts.forEach(function (p) {
				html += '<a class="tm-article-link" href="' + escapeAttr(p.link) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a>';
			});
			html += '</div>';
		} else {
			// 无文章 → 坐标名下方同样显示地理小字（国内省/国外国家）
			var geoLabel = sub || loc.name || name;
			html += '<div class="tm-bubble">';
			html += '<button type="button" class="tm-close"></button>';
			html += '<div class="tm-bubble-name">' + esc(name) + '</div>';
			html += '<div class="tm-bubble-sub">' + esc(geoLabel) + '</div>';
			html += '</div>';
		}

		var pos = new AMap.LngLat(loc.lng, loc.lat);
		infoWindow.setContent(html);
		infoWindow.open(map, pos);
		_bubbleOpenTick = Date.now();
		// 关闭按钮（右上角叉）：用事件委托统一处理，
		// 避免依赖 infoWindow.getContent() 返回 DOM 而可能得到字符串导致报错。
		if (!closeBound) {
			closeBound = true;
			document.addEventListener('click', function (e) {
				var t = e.target;
				if (t && t.classList && t.classList.contains('tm-close')) {
					if (infoWindow) infoWindow.close();
				}
			});
		}
	}

	// 坐标名下的一行小字（tm-bubble-sub）：国内→省级行政区名，国外→国家名。
	// 判定“国内”：country 为空或为「中国」。国内显示省份（province，如“广东省”）；
	// 国外显示国家名（country，如“日本”）。
	// 兜底：个别旧点 country / province 均未存，按经纬度推断所在国家并显示中文国名，
	//       确保国外点的气泡不再空白（与 colorRegions 的着色推断保持一致）。
	function buildSub(loc) {
		var country = (loc.country || '').trim();
		var prov    = (loc.province || '').trim();
		var city    = (loc.city || '').trim();
		var isChina = !country || country === '中国' || country === 'CN';
		if (isChina && prov) return prov;         // 国内且有省名 → 显示省名
		if (country && !isChina) return country;  // 国外且存有国名 → 显示国名
		// 走到这里：country 空（可能是国内点省名也空，或旧国外点完全没存国名）
		var iso = inferCountryISO(loc.lng, loc.lat);
		var cn = countryNameByISO(iso);
		if (cn && iso !== 'CHN' && iso !== 'HKG' && iso !== 'MAC' && iso !== 'TWN') {
			return cn;                            // 推断为外国 → 显示推断出的国名
		}
		// 推断为国内（或未能推断）→ 回退省名 / 城市名
		return prov || city || '';
	}

	function esc(s) {
		if (s === undefined || s === null) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}
	function escapeAttr(s) {
		return esc(s).replace(/'/g, '&#39;');
	}
})();
