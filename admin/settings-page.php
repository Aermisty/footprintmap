<?php
/**
 * Admin settings page (地图设置).
 *
 * @package FootprintMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$saved = footprintmap()->get_settings();

// 保存处理已移至主类 handle_settings_save()（load-{page} 钩子，早于输出），
// 保存后 PRG 重定向回本页并带 settings-updated=1，此处仅负责展示提示。
$updated = isset( $_GET['settings-updated'] ) ? sanitize_key( wp_unslash( $_GET['settings-updated'] ) ) : '';
$notice  = ( '1' === $updated ) ? __( '设置已保存。', 'footprintmap' ) : '';
?>
<div class="wrap">
	<h1><?php esc_html_e( '足迹地图 — 地图设置', 'footprintmap' ); ?></h1>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="notice notice-info">
		<p><?php esc_html_e( '本插件使用高德地图（AMap）。请前往高德开放平台创建「Web端(JS API)」应用并申请 Key，同时设置好对应的「安全密钥」。', 'footprintmap' ); ?></p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'footprintmap_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="amap_key"><?php esc_html_e( 'JS API Key', 'footprintmap' ); ?></label>
				</th>
				<td>
					<input type="text" id="amap_key" name="amap_key" class="regular-text" value="<?php echo esc_attr( $saved['key'] ); ?>" />
					<p class="description"><?php esc_html_e( '高德「Web端(JS API)」应用的 Key（形如 e3xxxxxxxxxxxxxxxxxxxxxxxxxxx0b9）。', 'footprintmap' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="amap_jscode"><?php esc_html_e( '安全密钥', 'footprintmap' ); ?></label>
				</th>
				<td>
					<input type="text" id="amap_jscode" name="amap_jscode" class="regular-text" value="<?php echo esc_attr( $saved['jscode'] ); ?>" />
					<p class="description"><?php esc_html_e( 'JS API 的「安全密钥」(jscode)，与 Key 配套使用，用于鉴权。', 'footprintmap' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cluster_distance"><?php esc_html_e( '聚合距离（像素）', 'footprintmap' ); ?></label>
				</th>
				<td>
					<input type="number" id="cluster_distance" name="cluster_distance" class="small-text" value="<?php echo esc_attr( $saved['cluster_distance'] ); ?>" min="10" max="200" />
					<p class="description"><?php esc_html_e( '地图上两点在屏幕距离小于该值(px)时会被合并为一个红色聚合圆点。数值越大越容易聚合。', 'footprintmap' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="post_tag"><?php esc_html_e( '关联文章标签名', 'footprintmap' ); ?></label>
				</th>
				<td>
					<input type="text" id="post_tag" name="post_tag" class="regular-text" value="<?php echo esc_attr( $saved['post_tag'] ); ?>" />
					<p class="description"><?php esc_html_e( '后台设置地点「关联文章」时，下拉框允许仅显示带指定标签的文章。输入多个标签用英文逗号分隔（如 旅行,攻略），标签名需与文章标签完全一致（不做子串模糊匹配），命中任一标签即列出。留空则显示全部文章。', 'footprintmap' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="default_image"><?php esc_html_e( '默认点位图片', 'footprintmap' ); ?></label>
				</th>
				<td>
					<?php
					$default_image_id  = isset( $saved['default_image'] ) ? absint( $saved['default_image'] ) : 0;
					$default_image_url = $default_image_id ? wp_get_attachment_image_url( $default_image_id, 'medium' ) : '';
					?>
					<div class="footprintmap-media-picker">
						<div class="footprintmap-media-preview" id="footprintmap-media-preview" style="<?php echo $default_image_url ? '' : 'display:none;'; ?>">
							<?php if ( $default_image_url ) : ?>
								<img src="<?php echo esc_url( $default_image_url ); ?>" alt="" />
							<?php endif; ?>
						</div>
						<input type="hidden" id="default_image" name="default_image" value="<?php echo esc_attr( $default_image_id ); ?>" />
						<div class="footprintmap-media-actions">
							<button type="button" class="button" id="footprintmap-select-image"><?php esc_html_e( '从媒体库选择图片', 'footprintmap' ); ?></button>
							<button type="button" class="button" id="footprintmap-remove-image" <?php echo $default_image_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( '移除', 'footprintmap' ); ?></button>
						</div>
					</div>
					<p class="description"><?php esc_html_e( '前台地图上「没有关联文章」的坐标点，默认显示为红色小圆点。在此选择一张默认图片后，这些点也会以圆形封面图样式呈现，与有关联文章的坐标点观感一致。', 'footprintmap' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( '如何放置地图', 'footprintmap' ); ?></h2>
	<p>
		<?php esc_html_e( '在任意页面/文章的编辑器中使用短代码：', 'footprintmap' ); ?>
		<code>[footprintmap]</code>
	</p>
</div>
