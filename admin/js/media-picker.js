/* =========================================================
 * FootprintMap Admin — 设置页「默认点位图片」媒体库选择器
 * 不依赖 media-editor handle（避免因依赖不满足被静默跳过）；
 * 改为轮询 window.wp.media 就绪后再绑定，与项目内 AMap 轮询模式一致。
 * ========================================================= */
(function () {
	'use strict';

	var frame = null;
	var booted = false;

	// 属性转义（纵深防御）：媒体库 URL 虽可信，拼 HTML 时仍应转义
	function escAttr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function mediaReady() {
		return !!(window.wp && window.wp.media);
	}

	function boot() {
		if (booted) return;
		var selectBtn = document.getElementById('footprintmap-select-image');
		if (!selectBtn) return;
		booted = true;

		var removeBtn = document.getElementById('footprintmap-remove-image');
		var input = document.getElementById('default_image');
		var preview = document.getElementById('footprintmap-media-preview');

		function renderPreview(att) {
			var url = '';
			if (att.sizes && att.sizes.medium) {
				url = att.sizes.medium.url;
			} else if (att.sizes && att.sizes.thumbnail) {
				url = att.sizes.thumbnail.url;
			} else {
				url = att.url;
			}
			preview.innerHTML = '<img src="' + escAttr(url) + '" alt="" />';
			preview.style.display = '';
		}

		selectBtn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!mediaReady()) return;
			if (frame) { frame.open(); return; }
			frame = window.wp.media({
				title: window.FootprintMapMediaPicker ? window.FootprintMapMediaPicker.title : '选择默认点位图片',
				button: { text: window.FootprintMapMediaPicker ? window.FootprintMapMediaPicker.button : '使用这张图片' },
				multiple: false,
				library: { type: 'image' }
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				input.value = attachment.id;
				renderPreview(attachment);
				removeBtn.style.display = '';
			});
			frame.open();
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function () {
				input.value = '';
				preview.innerHTML = '';
				preview.style.display = 'none';
				removeBtn.style.display = 'none';
			});
		}
	}

	function startWhenReady() {
		if (!document.getElementById('footprintmap-select-image')) return;
		boot();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startWhenReady);
	} else {
		startWhenReady();
	}
})();
