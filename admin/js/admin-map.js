/* =========================================================
 * FootprintMap Admin — 后台地点标记地图
 * 依赖：AMap JS v2.0（含 DistrictSearch/Geocoder/AutoComplete）
 * ========================================================= */
(function () {
	'use strict';

	var cfg = window.FootprintMapAdmin || {};
	var map = null;
	var pickedMarker = null;   // 当前待保存的临时标记
	var currentId = 0;         // 正在编辑的地点 id (0=新建)
	var allLocations = [];     // 后台所有地点的数据列表
	var mapMarkers = [];       // 当前渲染到地图上的 marker（含聚合圆点）
	var clusterInfoWin = null; // 聚合圆点点击后弹出的地点选择信息窗（单例）
	var pendingPosts = [];     // 当前表单里已关联的文章 id (有序)
	var postMeta = {};         // id -> {title,thumb} 标题与缩略图缓存
	var selectPost = null;     // #footprintmap-post-select 下拉
	var tagPosts = [];         // 可选的带标签文章 [{id,title,thumb}]
	var mapReady = false;      // 地图是否已创建完成
	var dataFetched = false;   // 点位数据是否已取到并渲染过表格
	var mapStarted = false;    // 是否已尝试建图（防止重复初始化）

	// 后台地图聚合阈值：屏幕像素距离小于该值时合并（点仅 14px，故比前台小）。
	var CLUSTER_DIST = 38;

	// 默认视野：以中国为中心的全世界广角（与前台一致）。
	var DEFAULT_VIEW = { zoom: 3, center: [107, 27] };

	// 把视野定位到默认广角。
	// dur>0 时做平滑动画（供右上角 home 按钮使用，复位更丝滑）；
	// 初始化建图后首次定位传 0（瞬间，避免首屏额外动画）。
	function applyDefaultView(dur) {
		var duration = (typeof dur === 'number') ? dur : 0;
		map.setZoomAndCenter(DEFAULT_VIEW.zoom, DEFAULT_VIEW.center, false, duration);
	}

	// 右上角 + - home 缩放控件（与前台地图一致）。
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
		// home 复位带平滑动画；结束后（zoomend/moveend）聚合点会自动更新，
		// 再补一个定时兜底，确保动画尾声 marker 一定落到复位后的正确聚合。
		div.querySelector('.tm-home').addEventListener('click', function () {
			applyDefaultView(900);
			setTimeout(scheduleRender, 950);
		});
	}

	function $(id) { return document.getElementById(id); }

	// ---------- AMap ready ----------
	// 轮询等待地图核心（AMap.Map）就绪后再建图；事件一律用实例方法，
	// AutoComplete/Geocoder 等可选插件不作为就绪门槛，在用到的函数内自行保护。
	function amapReady() {
		return !!(window.AMap && window.AMap.Map);
	}

	function startMapIfPossible() {
		if (mapStarted) return;
		if (!amapReady() || !$('footprintmap-admin-map')) return;
		mapStarted = true;
		try {
			initMap();
		} catch (e) {
			// 建图异常不致命：复位以便下次轮询重试。
			mapStarted = false;
			return;
		}
		mapReady = true;
		renderMarkersIfReady();
		if (!dataFetched) {
			loadAllLocations(); // 兜底：数据还没取到就补取
		}
	}

	// 兼容旧 HTML 缓存里带 callback 参数的高德脚本（不会重复建图）。
	window.__footprintmap_amap_ready = startMapIfPossible;

	// 轮询等待高德脚本执行完毕后就绪；首帧先直接尝试一次。
	var _pollTimer = setInterval(function () {
		if (amapReady()) {
			clearInterval(_pollTimer);
			startMapIfPossible();
		}
	}, 250);
	if (amapReady()) {
		startMapIfPossible();
	}
	// 约 15 秒后停止轮询，避免极端情况无限占用。
	setTimeout(function () { clearInterval(_pollTimer); }, 15000);

	function initMap() {
		var el = $('footprintmap-admin-map');
		var iv = DEFAULT_VIEW;
		map = new AMap.Map(el, {
			zoom: iv.zoom,
			center: iv.center,
			viewMode: '2D',
			mapStyle: 'amap://styles/whitesmoke' // 固定浅色底图
		});

		// 右上角 + - home 缩放控件
		buildControls(el);

		// 聚合圆点点击后弹出的地点选择信息窗（单例）
		clusterInfoWin = new AMap.InfoWindow({
			isCustom: true,
			offset: new AMap.Pixel(0, -20),
			autoMove: true
		});

		map.on('click', function (e) {
			var lnglat = e.lnglat;
			placePicker(lnglat.getLng(), lnglat.getLat());
		});

		// 缩放动画结束 / 平移结束后一次性重新聚合渲染。
		// 注意：不要监听 zoomchange —— 平滑缩放动画期间会连续触发它，
		// 若逐帧全量重建 marker 会打断缩放动画造成明显卡顿。改在 zoomend
		// 触发一次，动画全程流畅，结束后再把聚合点更新到位。
		map.on('zoomend', scheduleRender);
		map.on('moveend', scheduleRender);

		// 搜索地点（用于点选&定位）
		var searchBox = $('footprintmap-admin-search');
		if (searchBox && window.AMap && AMap.AutoComplete) {
			var ac = new AMap.AutoComplete({
				input: searchBox,
				city: '全国'
			});
			ac.on('select', function (e) {
				var loc = e.poi.location;
				map.setZoomAndCenter(12, [loc.lng, loc.lat]);
				placePicker(loc.lng, loc.lat);
			});
		}

		// 文档级委托：关闭聚合信息窗；点击信息窗内某地点则载入编辑。
		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t || !t.classList) return;
			if (t.classList.contains('tm-admin-close')) {
				if (clusterInfoWin) clusterInfoWin.close();
				return;
			}
			var item = t.closest ? t.closest('.tm-admin-cluster-item') : null;
			if (item) {
				e.stopPropagation();
				var id = Number(item.getAttribute('data-id'));
				var loc = allLocations.find(function (l) { return Number(l.id) === id; });
				if (loc) {
					clusterInfoWin.close();
					loadIntoForm(loc);
				}
			}
		});
	}

	/* 在地图上放置选点标记并反地理编码获取省市国家 */
	function placePicker(lng, lat) {
		clearPicker();
		pickedMarker = new AMap.Marker({
			position: [lng, lat],
			zIndex: 200,
			content: '<div class="tm-picker-marker"></div>',
			anchor: 'center'
		});
		pickedMarker.setMap(map);

		setCoords(lng, lat);
		// 新选点时先清空地理字段：等 reverseGeocode 回填；
		// 国外点高德通常不返回，留空便于用户手工录入国家/城市。
		$('loc-country').value = '';
		$('loc-province').value = '';
		$('loc-city').value = '';
		reverseGeocode(lng, lat);
	}

	function clearPicker() {
		if (pickedMarker) { pickedMarker.setMap(null); pickedMarker = null; }
	}

	function setCoords(lng, lat) {
		$('loc-lng').value = lng.toFixed(6);
		$('loc-lat').value = lat.toFixed(6);
		$('loc-lng-display').value = lng.toFixed(6);
		$('loc-lat-display').value = lat.toFixed(6);
	}

	/* 反地理编码：填充国家/省份/城市 */
	function reverseGeocode(lng, lat) {
		// Geocoder 为可选插件，未就绪时跳过（不影响选点与保存）。
		if (!window.AMap || !AMap.Geocoder) return;
		var geocoder = new AMap.Geocoder({ radius: 1000 });
		geocoder.getAddress([lng, lat], function (status, result) {
			if (status === 'complete' && result.regeocode) {
				var ac = result.regeocode.addressComponent;
				var country = ac.country || '';
				var province = ac.province || '';
				var city = ac.city || ac.province || '';
				if (city === '[]' || !city) { city = province; }

				// 回填策略：国内点自动补“中国”，国外点保持留空以便手工录入。
				// 高德对国内坐标点通常能识别出中国省级行政区(province 非空)，
				// 但部分点不返回 country，此时据 province 判定为国内并补“中国”；
				// 国外点高德不返回中国省份，province 为空，则国家留空让用户手录，
				// 避免被误标成“中国”。
				if (!country && province) { country = '中国'; }
				if (country) { $('loc-country').value = country; }
				if (province) { $('loc-province').value = province; }
				if (city) { $('loc-city').value = city; }
			}
		});
	}

	// ============================================================
	// 已存地点
	// ============================================================
	// 取回并渲染全部已存点位。
	// 表格渲染不依赖地图；地图点需地图就绪后才画（renderMarkersIfReady）。
	function loadAllLocations() {
		var xhr = new XMLHttpRequest();
		xhr.open('GET', cfg.ajaxUrl + '?action=footprintmap_get_locations&nonce=' + encodeURIComponent(cfg.nonce));
		xhr.onload = function () {
			if (xhr.status !== 200) return;
			try {
				var res = JSON.parse(xhr.responseText);
				if (res && res.success) {
					allLocations = res.data || [];
					dataFetched = true;
					renderLocationList(allLocations);
					renderMarkersIfReady();
				}
			} catch (e) {}
		};
		xhr.send();
	}

	// 地图就绪且有数据时，才画地图点，并定位到默认广角视野
	function renderMarkersIfReady() {
		if (!mapReady || !dataFetched) return;
		renderAllMarkers(allLocations);
		applyDefaultView();
	}

	// 启动：页面一加载就取数据（保证刷新后「已标记地点」立刻出现），并绑定事件。
	function initData() {
		loadAllLocations();
	}

	function renderAllMarkers(list) {
		clearMapMarkers();
		if (!list.length) return;

		var groups = clusterByScreenDistance(list, CLUSTER_DIST);
		groups.forEach(function (grp) {
			if (grp.length === 1) {
				addSingleMarker(grp[0]);
			} else {
				addClusterMarker(grp);
			}
		});
	}

	// 清除地图上全部已存地点 marker（不含选点临时标记）
	function clearMapMarkers() {
		mapMarkers.forEach(function (m) { m.setMap(null); });
		mapMarkers = [];
	}

	// 单独点：红点，直接点击载入编辑
	function addSingleMarker(loc) {
		var m = new AMap.Marker({
			position: [loc.lng, loc.lat],
			content: '<div class="tm-admin-dotmark"></div>',
			anchor: 'center',
			extData: { id: loc.id }
		});
		m.on('click', function () { loadIntoForm(loc); });
		m.setMap(map);
		mapMarkers.push(m);
	}

	// 聚合点：红渐变数字圆点，点击弹出该区域地点列表供选择编辑
	function addClusterMarker(group) {
		var count = group.length;
		var c = centerOf(group);
		var content = '<div class="tm-admin-cluster">' + count + '</div>';
		var m = new AMap.Marker({
			position: c,
			content: content,
			anchor: 'center',
			zIndex: 40,
			extData: { group: group }
		});
		m.on('click', function () { openClusterList(group, c); });
		m.setMap(map);
		mapMarkers.push(m);
	}

	// 点击聚合圆点：弹出该区域全部地点名列表，点某条载入该点编辑
	function openClusterList(group, centerLngLat) {
		var title = (cfg.i18n.clusterTitle || '该区域有 %d 个地点，点击选择编辑').replace('%d', group.length);
		var html = '<div class="tm-admin-infobox">';
		html += '<button type="button" class="tm-admin-close">&times;</button>';
		html += '<div class="tm-admin-infobox-title">' + esc(title) + '</div>';
		html += '<div class="tm-admin-infobox-list">';
		group.forEach(function (loc) {
			html += '<div class="tm-admin-cluster-item" data-id="' + loc.id + '">' + esc(loc.name) + '</div>';
		});
		html += '</div>';
		html += '</div>';
		clusterInfoWin.setContent(html);
		clusterInfoWin.open(map, new AMap.LngLat(centerLngLat[0], centerLngLat[1]));
	}

	// 屏幕像素距离聚类（与前台思路一致，阈值 CLUSTER_DIST 更小）。
	// 实现：网格哈希分桶（格宽=dist，距离<dist 的点至多跨一格）+ BFS 归并，
	// 候选只查 3x3 相邻格，避免原先逐点全表扫描的 O(n²)~O(n³) 开销；
	// 归并语义与旧实现一致：与组内任一成员屏幕距离 < dist 即并入。
	function clusterByScreenDistance(locs, dist) {
		var n = locs.length;
		if (!n) return [];

		var items = new Array(n);
		for (var i = 0; i < n; i++) {
			var px = map.lngLatToContainer(new AMap.LngLat(locs[i].lng, locs[i].lat));
			items[i] = { loc: locs[i], x: px.x, y: px.y };
		}

		var cell = Math.max(1, dist);
		var grid = {};
		function gridKey(gx, gy) { return gx + ',' + gy; }
		for (var g = 0; g < n; g++) {
			var k = gridKey(Math.floor(items[g].x / cell), Math.floor(items[g].y / cell));
			if (!grid[k]) grid[k] = [];
			grid[k].push(g);
		}

		var used = new Array(n).fill(false);
		var groups = [];
		for (var s = 0; s < n; s++) {
			if (used[s]) continue;
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
							if (used[j]) continue;
							var o = items[j];
							// 与组内任一成员距离 < dist 才并入（保持旧实现的传递归并语义）
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
		return groups;
	}

	function centerOf(group) {
		var lat = 0, lng = 0;
		group.forEach(function (l) { lat += l.lat; lng += l.lng; });
		return [lng / group.length, lat / group.length];
	}

	// 渲染节流：缩放/移动时用 requestAnimationFrame 合并重绘
	var renderQueued = false;
	var renderBusy = false;
	function scheduleRender() {
		if (renderBusy) { renderQueued = true; return; }
		renderBusy = true;
		requestAnimationFrame(function () {
			renderBusy = false;
			if (mapReady && dataFetched) {
				renderAllMarkers(allLocations);
			}
			if (renderQueued) { renderQueued = false; scheduleRender(); }
		});
	}

	// ============================================================
	// 表格（分页 + 筛选 + 排序）
	// ============================================================
	var PAGE_SIZE = 15;                 // 每页展示地点数
	var currentPage = 1;                // 当前页码（从 1 起）
	var regionFilter = '';              // 当前“省份/国家”筛选值（''=全部）
	var postCountOrder = 0;             // 关联文章数量排序：0=默认(id序) 1=从大到小 2=从小到大

	function esc(s) {
		if (!s) return '';
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	// 取某地点的“省份/国家”展示列文案（与列表第二列一致：国外显示国家，国内显示省份）
	function regionOf(loc) {
		return (loc.country && loc.country !== '中国') ? loc.country : (loc.province || '');
	}

	// 对列表做筛选 + 排序，返回处理后的数组（不改变原始 allLocations）
	function applyFilterSort(list) {
		var out = list.slice();
		if (regionFilter) {
			out = out.filter(function (loc) {
				return regionOf(loc) === regionFilter;
			});
		}
		if (postCountOrder !== 0) {
			out.sort(function (a, b) {
				var ca = (a.post_ids || []).length;
				var cb = (b.post_ids || []).length;
				return postCountOrder === 1 ? cb - ca : ca - cb;
			});
		}
		return out;
	}

	// 重建“省份/国家”筛选下拉（去重、按文本排序），保留当前选中值
	function rebuildRegionFilter(list) {
		var sel = $('footprintmap-region-filter');
		if (!sel) return;
		var values = [];
		var seen = {};
		list.forEach(function (loc) {
			var r = regionOf(loc);
			if (r && !seen[r]) { seen[r] = true; values.push(r); }
		});
		values.sort(function (a, b) { return String(a).localeCompare(String(b), 'zh'); });

		var current = sel.value;
		sel.innerHTML = '';
		var optAll = document.createElement('option');
		optAll.value = '';
		optAll.textContent = cfg.i18n.filterAll || '全部';
		sel.appendChild(optAll);
		values.forEach(function (v) {
			var o = document.createElement('option');
			o.value = v;
			o.textContent = v;
			sel.appendChild(o);
		});
		// 恢复之前的选择；若之前的值已不存在（如删除了该地区最后一点），回退到“全部”
		if (current && values.indexOf(current) !== -1) {
			sel.value = current;
			regionFilter = current;
		} else {
			sel.value = '';
			regionFilter = '';
		}
	}

	function renderLocationList(list) {
		rebuildRegionFilter(list);

		var filtered = applyFilterSort(list);
		var totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
		if (currentPage > totalPages) currentPage = totalPages;
		if (currentPage < 1) currentPage = 1;

		var start = (currentPage - 1) * PAGE_SIZE;
		var pageItems = filtered.slice(start, start + PAGE_SIZE);

		var tbody = document.querySelector('#footprintmap-locations-table tbody');
		tbody.innerHTML = '';
		if (!pageItems.length) {
			tbody.innerHTML = '<tr><td colspan="7" class="footprintmap-no-rows">' + esc(cfg.i18n.noMatch ? '没有符合筛选条件的地点。' : '暂无地点') + '</td></tr>';
		} else {
			pageItems.forEach(function (loc) {
				var tr = document.createElement('tr');
				var postsCount = (loc.post_ids || []).length;
				tr.innerHTML =
					'<td><a class="footprintmap-edit-link" data-id="' + loc.id + '">' + esc(loc.name) + '</a></td>' +
					'<td>' + esc(regionOf(loc) || '-') + '</td>' +
					'<td>' + esc(loc.city || '-') + '</td>' +
					'<td>' + loc.lng.toFixed(4) + '</td>' +
					'<td>' + loc.lat.toFixed(4) + '</td>' +
					'<td>' + postsCount + '</td>' +
					'<td><span class="footprintmap-delete-link" data-id="' + loc.id + '" data-name="' + esc(loc.name) + '">' + esc(cfg.i18n.delete) + '</span></td>';
				tbody.appendChild(tr);
			});

			// bind
			tbody.querySelectorAll('.footprintmap-edit-link').forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					var id = Number(a.getAttribute('data-id'));
					// 用 == 宽松比较，兼容数据库 id 可能以字符串返回的情况
					var loc = allLocations.find(function (l) { return Number(l.id) === id; });
					if (loc) {
						loadIntoForm(loc);
						// 视觉提示：把右侧编辑表单滚到可见，等同新建那样编辑
						var fc = $('footprintmap-location-form');
						if (fc && fc.scrollIntoView) fc.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}
				});
			});
			tbody.querySelectorAll('.footprintmap-delete-link').forEach(function (a) {
				a.addEventListener('click', function () {
					var id = +a.getAttribute('data-id');
					if (!window.confirm(cfg.i18n.confirmDelete)) return;
					requestDelete(id);
				});
			});
		}

		renderPagination(filtered.length, totalPages);
		renderListCount(filtered.length);
	}

	// 渲染分页控件
	function renderPagination(total, totalPages) {
		var box = $('footprintmap-pagination');
		if (!box) return;
		box.innerHTML = '';
		if (totalPages <= 1) return;

		var prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'button footprintmap-page-btn';
		prev.textContent = '‹ ' + (cfg.i18n.prev || '上一页');
		prev.disabled = currentPage <= 1;
		prev.addEventListener('click', function () {
			if (currentPage > 1) { currentPage--; renderLocationList(allLocations); }
		});
		box.appendChild(prev);

		var info = document.createElement('span');
		info.className = 'footprintmap-page-info';
		info.textContent = (cfg.i18n.pageInfo ? cfg.i18n.pageInfo.replace('%1$d', currentPage).replace('%2$d', totalPages) : (currentPage + ' / ' + totalPages));
		box.appendChild(info);

		var next = document.createElement('button');
		next.type = 'button';
		next.className = 'button footprintmap-page-btn';
		next.textContent = (cfg.i18n.next || '下一页') + ' ›';
		next.disabled = currentPage >= totalPages;
		next.addEventListener('click', function () {
			if (currentPage < totalPages) { currentPage++; renderLocationList(allLocations); }
		});
		box.appendChild(next);
	}

	// 顶部“共 X 个地点”计数
	function renderListCount(total) {
		var el = $('footprintmap-list-count');
		if (el) el.textContent = (cfg.i18n.total ? cfg.i18n.total.replace('%d', total) : ('共 ' + total + ' 个地点'));
	}

	// 更新排序表头指示箭头
	function updateSortIndicator() {
		var ind = $('footprintmap-sort-indicator');
		if (!ind) return;
		if (postCountOrder === 0) { ind.textContent = ''; }
		else if (postCountOrder === 1) { ind.textContent = '↓'; }
		else { ind.textContent = '↑'; }
	}

	// ============================================================
	// 编辑：把一条记录载入表单
	// ============================================================
	function loadIntoForm(loc) {
		currentId = loc.id;
		$('loc-id').value = loc.id;
		$('loc-name').value = loc.name;
		$('loc-country').value = loc.country || '';
		$('loc-province').value = loc.province || '';
		$('loc-city').value = loc.city || '';
		setCoords(loc.lng, loc.lat);
		pendingPosts = (loc.post_ids || []).slice();
		// 填充 postMeta（标题/缩略图）
		(loc.posts || []).forEach(function (p) {
			postMeta[p.id] = { title: p.title, thumb: p.thumb || '' };
		});
		renderLinkedPosts();

		$('footprintmap-delete-btn').classList.remove('footprintmap-hidden');

		// 定位到该点并放大到可拆开单点的层级
		clearPicker();
		if (map) map.setZoomAndCenter(8, [loc.lng, loc.lat]);
	}

	// ============================================================
	// 关联文章
	// ============================================================
	function renderLinkedPosts() {
		var box = $('footprintmap-linked-posts');
		box.innerHTML = '';
		if (!pendingPosts.length) {
			box.innerHTML = '<p class="footprintmap-empty">' + esc(cfg.i18n.noArticles) + '</p>';
		} else {
			pendingPosts.forEach(function (pid) {
				var meta = postMeta[pid] || { title: '#' + pid, thumb: '' };
				var item = document.createElement('div');
				item.className = 'footprintmap-linked-item';
				item.innerHTML =
					(meta.thumb ? '<img src="' + meta.thumb + '" alt="" />' : '') +
					'<span class="t">' + esc(meta.title) + '</span>' +
					'<button type="button" class="remove" data-id="' + pid + '" title="移除">&times;</button>';
				box.appendChild(item);
			});
			box.querySelectorAll('.remove').forEach(function (b) {
				b.addEventListener('click', function () {
					var pid = +b.getAttribute('data-id');
					pendingPosts = pendingPosts.filter(function (p) { return p !== pid; });
					delete postMeta[pid];
					renderLinkedPosts();
				});
			});
		}
		renderPostOptions();
	}

	// 重建下拉选项：列出带标签文章，已关联的置为不可再选
	function renderPostOptions() {
		if (!selectPost) return;
		selectPost.innerHTML = '';
		if (!tagPosts.length) {
			var none = document.createElement('option');
			none.value = '';
			none.disabled = true;
			none.textContent = cfg.i18n.noTagPosts;
			selectPost.appendChild(none);
		} else {
			var ph = document.createElement('option');
			ph.value = '';
			ph.textContent = cfg.i18n.selectPost;
			selectPost.appendChild(ph);
			tagPosts.forEach(function (p) {
				var opt = document.createElement('option');
				opt.value = p.id;
				opt.textContent = p.title;
				opt.disabled = pendingPosts.indexOf(p.id) !== -1; // 已关联的不允许重复选择
				selectPost.appendChild(opt);
			});
		}
		// 提示当前筛选范围（标签为空=列出全部文章；否则提示标签名）
		var hint = $('footprintmap-tag-hint');
		if (hint) {
			var pt = (cfg.postTag || '').trim();
			if (pt) {
				hint.textContent = cfg.i18n.tagHint.replace('%s', pt);
			} else {
				hint.textContent = cfg.i18n.allPostsHint || '';
			}
		}
	}

	function onSelectPost() {
		var v = +selectPost.value;
		if (!v) return;
		var hit = null;
		tagPosts.forEach(function (p) { if (p.id === v) hit = p; });
		if (hit) addLinkedPost(hit);
		selectPost.value = '';
	}

	function addLinkedPost(it) {
		if (pendingPosts.indexOf(it.id) === -1) {
			pendingPosts.push(it.id);
			postMeta[it.id] = { title: it.title, thumb: it.thumb || '' };
		}
		renderLinkedPosts();
	}

	// ============================================================
	// 保存 / 删除 / 清空
	// ============================================================
	function showMsg(text, ok) {
		var m = $('footprintmap-save-msg');
		m.textContent = text;
		m.className = 'footprintmap-msg ' + (ok ? 'ok' : 'err');
	}

	function doSave(e) {
		e.preventDefault();
		var name = $('loc-name').value.trim();
		var lng = parseFloat($('loc-lng').value);
		var lat = parseFloat($('loc-lat').value);
		if (!name) { showMsg(cfg.i18n.inputName, false); return; }
		if (isNaN(lng) || isNaN(lat)) { showMsg(cfg.i18n.pleaseClick, false); return; }

		var body =
			'action=footprintmap_save_location&nonce=' + encodeURIComponent(cfg.nonce) +
			'&id=' + currentId +
			'&name=' + encodeURIComponent(name) +
			'&lat=' + lat + '&lng=' + lng +
			'&province=' + encodeURIComponent($('loc-province').value.trim()) +
			'&city=' + encodeURIComponent($('loc-city').value.trim()) +
			'&country=' + encodeURIComponent($('loc-country').value.trim());

		pendingPosts.forEach(function (p) { body += '&post_ids[]=' + p; });

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onload = function () {
			if (xhr.status !== 200) { showMsg(cfg.i18n.error, false); return; }
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.success) {
					showMsg(cfg.i18n.saved, true);
					loadAllLocations();
					// 重置为"新建"状态，避免下次误更新
					resetFormForNew();
				} else {
					showMsg(res.data && res.data.message ? res.data.message : cfg.i18n.error, false);
				}
			} catch (err) { showMsg(cfg.i18n.error, false); }
		};
		xhr.send(body);
	}

	function requestDelete(id) {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onload = function () {
			// 校验响应：失败（nonce 过期/权限/网络）时不能假装“已删除”，
			// 否则列表看似刷新了、数据其实还在，用户会被误导。
			var ok = false;
			try {
				var res = JSON.parse(xhr.responseText);
				ok = xhr.status === 200 && !!(res && res.success);
			} catch (e) { ok = false; }
			if (ok) {
				showMsg(cfg.i18n.deleted || '已删除', true);
				loadAllLocations();
				resetFormForNew();
			} else {
				showMsg(cfg.i18n.deleteError || '删除失败，请重试', false);
			}
		};
		xhr.onerror = function () {
			showMsg(cfg.i18n.deleteError || '删除失败，请重试', false);
		};
		xhr.send('action=footprintmap_delete_location&id=' + id + '&nonce=' + encodeURIComponent(cfg.nonce));
	}

	function resetFormForNew() {
		currentId = 0;
		$('loc-id').value = 0;
		$('loc-name').value = '';
		$('loc-province').value = '';
		$('loc-country').value = '';
		$('loc-city').value = '';
		$('loc-lng-display').value = '';
		$('loc-lat-display').value = '';
		$('loc-lng').value = '';
		$('loc-lat').value = '';
		pendingPosts = [];
		postMeta = {};
		renderLinkedPosts();
		clearPicker();
		$('footprintmap-delete-btn').classList.add('footprintmap-hidden');
		$('footprintmap-save-msg').textContent = '';
	}

	// ============================================================
	// events
	// ============================================================
	function bind() {
		var form = $('footprintmap-location-form');
		if (form) form.addEventListener('submit', doSave);

		var clearBtn = $('footprintmap-clear-btn');
		if (clearBtn) clearBtn.addEventListener('click', function () { resetFormForNew(); });

		var delBtn = $('footprintmap-delete-btn');
		if (delBtn) delBtn.addEventListener('click', function () {
			if (!currentId) return;
			if (!window.confirm(cfg.i18n.confirmDelete)) return;
			requestDelete(currentId);
		});

		// 关联文章下拉：一次性列出打了「关联标签」的文章
		selectPost = $('footprintmap-post-select');
		tagPosts = cfg.posts || [];
		renderPostOptions();
		if (selectPost) selectPost.addEventListener('change', onSelectPost);

		// 列表筛选：省份/国家下拉
		var regionSel = $('footprintmap-region-filter');
		if (regionSel) regionSel.addEventListener('change', function () {
			regionFilter = regionSel.value;
			currentPage = 1;
			renderLocationList(allLocations);
		});

		// 列表排序：点击“关联文章数量”表头循环切换 默认→从大到小→从小到大→默认
		var sortTh = $('footprintmap-post-count-th');
		if (sortTh) sortTh.addEventListener('click', function () {
			postCountOrder = (postCountOrder + 1) % 3; // 0→1→2→0
			currentPage = 1;
			updateSortIndicator();
			renderLocationList(allLocations);
		});
	}

	// 绑定事件 + 加载已存点位（表格不依赖高德回调，刷新即可见）
	function boot() {
		if (window.FootprintMapAdmin === undefined) {
			return; // 非地图管理页（无本地化数据），无需初始化
		}
		bind();
		initData();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
