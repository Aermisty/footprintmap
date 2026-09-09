<?php
/**
 * Admin map management page.
 *
 * @package FootprintMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = footprintmap()->get_settings();
$has_key  = ! empty( $settings['key'] );

// ---- 导入结果提示 ----
$tm_msg    = isset( $_GET['footprintmap_msg'] ) ? sanitize_key( wp_unslash( $_GET['footprintmap_msg'] ) ) : '';
$imp_res   = get_transient( 'footprintmap_import_result' );
$msg_html  = '';
if ( $imp_res ) {
	$d = isset( $imp_res['deleted'] ) ? (int) $imp_res['deleted'] : 0;
	// 覆盖更新为“清空旧点 → 按 CSV 重建”，汇总用“重建/跳过”口径（updated 恒为 0）。
	$msg_html .= '<div class="notice notice-success is-dismissible"><p>' .
		esc_html( sprintf( __( '导入完成：重建 %1$d 个地点（清空原 %2$d 个旧点），跳过 %3$d 条。', 'footprintmap' ), (int) $imp_res['added'], $d, (int) $imp_res['skipped'] ) ) . '</p>';
	foreach ( (array) $imp_res['errors'] as $err ) {
		$msg_html .= '<p style="margin:2px 0;color:#b32d2e;">' . esc_html( $err ) . '</p>';
	}
	$msg_html .= '</div>';
	delete_transient( 'footprintmap_import_result' );
} elseif ( 'imported' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '导入请求已处理，请查看下方提示。', 'footprintmap' ) . '</p></div>';
} elseif ( 'no-file' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '请选择要导入的 CSV 文件。', 'footprintmap' ) . '</p></div>';
} elseif ( 'upload-error' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '文件上传失败，请重试。', 'footprintmap' ) . '</p></div>';
} elseif ( 'read-error' === $tm_msg || 'empty' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '无法读取 CSV，或文件内容为空。请检查文件是否为有效 CSV 且包含数据行。', 'footprintmap' ) . '</p></div>';
} elseif ( 'too-large' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'CSV 文件过大（超过 5 MB），已拒绝导入。请精简文件后重试。', 'footprintmap' ) . '</p></div>';
} elseif ( 'too-many-rows' === $tm_msg ) {
	$msg_html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'CSV 数据行过多（超过约 5000 条），已拒绝导入以避免超时。请拆分文件后分批导入。', 'footprintmap' ) . '</p></div>';
}
?>
<div class="wrap footprintmap-admin">
	<h1><?php esc_html_e( '足迹地图 — 地点管理', 'footprintmap' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $msg_html;

	$export_nonce = wp_create_nonce( 'footprintmap_export_csv' );
	$import_nonce = wp_create_nonce( 'footprintmap_import_csv' );
	$admin_post   = admin_url( 'admin-post.php' );
	?>
	<div class="footprintmap-transfer">
		<h2 class="tm-transfer-title"><?php esc_html_e( '地点数据 导入 / 导出', 'footprintmap' ); ?></h2>

		<div class="tm-transfer-btns">
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>" class="tm-inline-form">
				<input type="hidden" name="action" value="footprintmap_export_csv" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $export_nonce ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( '⬇ 导出 CSV', 'footprintmap' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( $admin_post ); ?>" enctype="multipart/form-data" class="tm-inline-form" id="footprintmap-import-form">
				<input type="hidden" name="action" value="footprintmap_import_csv" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $import_nonce ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( '⬆ 导入 CSV', 'footprintmap' ); ?></button>
				<input type="file" name="footprintmap_csv" accept=".csv,text/csv" class="tm-file" />
			</form>
		</div>
		<p class="tm-transfer-note">
			<strong style="color:#b32d2e;"><?php esc_html_e( '注意事项：', 'footprintmap' ); ?></strong>
			<?php esc_html_e( '导入将先清空数据库中的全部现有地点，再按 CSV 内容逐条重建。导入后地点列表将严格等于上传文件内容，文件中没有的地点会被删除。此操作不可逆，建议先点击「导出 CSV」备份后再继续。', 'footprintmap' ); ?>
			<br/>
			<strong><?php esc_html_e( 'CSV 格式要求：', 'footprintmap' ); ?></strong>
			<?php esc_html_e( '字段顺序：', 'footprintmap' ); ?><code>name, city, province, country, lat, lng, post_ids</code><?php esc_html_e( '；', 'footprintmap' ); ?>
			<code>post_ids</code><?php esc_html_e( ' 为文章 ID，多个用分号分隔（如', 'footprintmap' ); ?><code>12;34</code><?php esc_html_e( '）。', 'footprintmap' ); ?>
			<?php esc_html_e( '支持带表头行（可使用中文列名），推荐使用本插件导出的 CSV 文件。', 'footprintmap' ); ?>
		</p>
	</div>

	<script>
	(function () {
		var f = document.getElementById('footprintmap-import-form');
		if (!f) return;
		var confirmMsg = <?php echo wp_json_encode( __( "⚠ 导入后地点列表将严格等于文件内容，CSV 中没有的地点会被删除，此操作不可撤销！建议先点击「导出 CSV」备份后再继续。\n\n确定要执行覆盖更新导入吗？", 'footprintmap' ) ); ?>;
		f.addEventListener('submit', function (e) {
			if (!window.confirm(confirmMsg)) {
				e.preventDefault();
			}
		});
	})();
	</script>

	<?php if ( ! $has_key ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( '尚未配置高德地图 API，无法加载地图。请先前往', 'footprintmap' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=footprintmap-settings' ) ); ?>"><?php esc_html_e( '地图设置', 'footprintmap' ); ?></a>
				<?php esc_html_e( '填入 JS Key 与安全密钥。', 'footprintmap' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="footprintmap-admin-layout">
			<div class="footprintmap-admin-map-col">
				<div class="footprintmap-toolbar">
					<input type="text" id="footprintmap-admin-search" class="footprintmap-search" placeholder="<?php esc_attr_e( '搜索地点/城市…', 'footprintmap' ); ?>" />
					<span class="footprintmap-tip"><?php esc_html_e( '提示：在地图上单击也可选点。', 'footprintmap' ); ?></span>
				</div>
				<div id="footprintmap-admin-map" class="footprintmap-admin-map"></div>
			</div>

			<div class="footprintmap-admin-form-col">
				<h2><?php esc_html_e( '地点信息', 'footprintmap' ); ?></h2>
				<div id="footprintmap-mode-bar" class="footprintmap-mode mode-new"></div>
				<form id="footprintmap-location-form">
					<input type="hidden" id="loc-id" name="id" value="0" />
					<input type="hidden" id="loc-lat" name="lat" value="" />
					<input type="hidden" id="loc-lng" name="lng" value="" />

					<div class="footprintmap-field">
						<div class="description"><?php esc_html_e( '前台着色规则：中国之内按省份着色，地点信息会自动识别；中国之外按国家着色，需手工填写国家与城市信息。', 'footprintmap' ); ?></div>
					</div>

					<div class="footprintmap-row">
						<p>
							<label for="loc-name"><?php esc_html_e( '地点名称 *', 'footprintmap' ); ?></label>
							<input type="text" id="loc-name" name="name" class="widefat" placeholder="<?php esc_attr_e( '例如：深圳 / 启真湖底', 'footprintmap' ); ?>" />
						</p>
						<p>
							<label for="loc-country"><?php esc_html_e( '国家', 'footprintmap' ); ?></label>
							<input type="text" id="loc-country" name="country" class="widefat" placeholder="<?php esc_attr_e( '国家', 'footprintmap' ); ?>" />
						</p>
					</div>

					<div class="footprintmap-row">
						<p>
							<label><?php esc_html_e( '省级行政区', 'footprintmap' ); ?></label>
							<input type="text" id="loc-province" name="province" class="widefat" placeholder="<?php esc_attr_e( '省级行政区', 'footprintmap' ); ?>" />
						</p>
						<p>
							<label><?php esc_html_e( '城市', 'footprintmap' ); ?></label>
							<input type="text" id="loc-city" name="city" class="widefat" placeholder="<?php esc_attr_e( '城市', 'footprintmap' ); ?>" />
						</p>
					</div>



					<div class="footprintmap-row">
						<p>
							<label><?php esc_html_e( '经度', 'footprintmap' ); ?></label>
							<input type="text" id="loc-lng-display" class="widefat" readonly 
							placeholder="<?php esc_attr_e( '经度（自动识别）', 'footprintmap' ); ?>" />
						</p>
						<p>
							<label><?php esc_html_e( '纬度', 'footprintmap' ); ?></label>
							<input type="text" id="loc-lat-display" class="widefat" readonly 
							placeholder="<?php esc_attr_e( '纬度（自动识别）', 'footprintmap' ); ?>" />
						</p>
					</div>

					<div class="footprintmap-field">
						<label><?php esc_html_e( '关联文章（可多选）', 'footprintmap' ); ?></label>
						<select id="footprintmap-post-select" class="widefat">
							<option value=""><?php esc_html_e( '— 选择要关联的文章 —', 'footprintmap' ); ?></option>
						</select>
						<p class="description" id="footprintmap-tag-hint"></p>
						<div id="footprintmap-linked-posts" class="footprintmap-linked">
							<p class="footprintmap-empty"><?php esc_html_e( '暂无关联文章', 'footprintmap' ); ?></p>
						</div>
					</div>

					<p class="footprintmap-actions">
						<button type="submit" id="footprintmap-save-btn" class="button button-primary"><?php esc_html_e( '保存地点', 'footprintmap' ); ?></button>
						<button type="button" id="footprintmap-exit-edit-btn" class="button footprintmap-hidden"><?php esc_html_e( '退出编辑', 'footprintmap' ); ?></button>
						<button type="button" id="footprintmap-edit-btn" class="button footprintmap-hidden"><?php esc_html_e( '编辑此地点', 'footprintmap' ); ?></button>
						<button type="button" id="footprintmap-delete-btn" class="button button-link-delete footprintmap-hidden"><?php esc_html_e( '删除该地点', 'footprintmap' ); ?></button>
					</p>
					<div id="footprintmap-save-msg" class="footprintmap-msg"></div>
				</form>
			</div>
		</div>

		<div class="footprintmap-locations-list">
			<h2><?php esc_html_e( '已标记地点', 'footprintmap' ); ?></h2>
			<div class="footprintmap-list-toolbar">
				<label class="footprintmap-filter-label">
					<?php esc_html_e( '筛选省份/国家：', 'footprintmap' ); ?>
					<select id="footprintmap-region-filter" class="footprintmap-region-filter">
						<option value=""><?php esc_html_e( '全部', 'footprintmap' ); ?></option>
					</select>
				</label>
				<span class="footprintmap-list-count" id="footprintmap-list-count"></span>
			</div>
			<table class="widefat striped" id="footprintmap-locations-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '名称', 'footprintmap' ); ?></th>
						<th><?php esc_html_e( '省份/国家', 'footprintmap' ); ?></th>
						<th><?php esc_html_e( '城市', 'footprintmap' ); ?></th>
						<th class="footprintmap-sortable" id="footprintmap-post-count-th">
							<span><?php esc_html_e( '文章数', 'footprintmap' ); ?></span>
							<span class="footprintmap-sort-indicator" id="footprintmap-sort-indicator"></span>
						</th>
						<th><?php esc_html_e( '操作', 'footprintmap' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<div class="footprintmap-pagination" id="footprintmap-pagination"></div>
		</div>

	<?php endif; ?>
</div>
