<?php
/**
 * Plugin Name:       Footprint Map 足迹地图
 * Plugin URI:        https://github.com/aermisty/footprintmap
 * Description:       在后台标记去过的地点（地图点选获取经纬度、自定义名称、关联多篇文章），在前台以高德地图展示足迹点位。
 * Version:           1.0.0
 * Author:            Aermisty
 * Text Domain:       footprintmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'FOOTPRINTMAP_VERSION', '1.0.0' );
define( 'FOOTPRINTMAP_FILE', __FILE__ );
define( 'FOOTPRINTMAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOOTPRINTMAP_URL', plugin_dir_url( __FILE__ ) );

/**
 * 主插件类（单例）。
 */
final class FootprintMap {

	/**
	 * 单例实例。
	 *
	 * @var FootprintMap|null
	 */
	private static $instance = null;

	/**
	 * 地点数据表名（含前缀）。
	 *
	 * @var string
	 */
	private $table;

	/**
	 * 获取单例实例。
	 *
	 * @return FootprintMap
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 构造：建表前缀、挂载激活钩子与前后台各类 action/shortcode。
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'footprintmap_locations';

		register_activation_hook( FOOTPRINTMAP_FILE, array( $this, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// 后台钩子。
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_footprintmap_save_location', array( $this, 'ajax_save_location' ) );
		add_action( 'wp_ajax_footprintmap_delete_location', array( $this, 'ajax_delete_location' ) );
		add_action( 'wp_ajax_footprintmap_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_footprintmap_get_locations', array( $this, 'ajax_get_locations' ) );

		// 导入 / 导出（走 admin-post 的页面表单提交）。
		add_action( 'admin_post_footprintmap_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_footprintmap_import_csv', array( $this, 'handle_import_csv' ) );

		// 前台钩子。
		add_shortcode( 'footprintmap', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

		// 插件列表页的“地图设置”链接。
		add_filter( 'plugin_action_links_' . plugin_basename( FOOTPRINTMAP_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/* ================================================================
	 * 激活 / 安装
	 * ================================================================ */

	/**
	 * 激活钩子：创建地点数据表并写入默认设置。
	 */
	public function activate() {
		global $wpdb;
		$table   = $this->table;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			city VARCHAR(255) NOT NULL DEFAULT '',
			province VARCHAR(255) NOT NULL DEFAULT '',
			country VARCHAR(255) NOT NULL DEFAULT '',
			lat DECIMAL(10,7) NOT NULL DEFAULT 0,
			lng DECIMAL(10,7) NOT NULL DEFAULT 0,
			post_ids TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_latlng (lat, lng)
		) {$charset};";

		dbDelta( $sql );

		// 写入默认设置（首次激活）。
		add_option( 'footprintmap_settings', array(
			'key'   => '',
			'jscode' => '',
			'cluster_distance' => 50,
			'post_tag' => '', // 默认留空：后台“关联文章”列出全部文章，不按标签过滤。
			'default_image' => 0, // 无关联文章点的默认点位图片。
		) );
	}

	/**
	 * 加载语言包（当前文案直接内嵌中文，此方法为后续多语言预留）。
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'footprintmap', false, dirname( plugin_basename( FOOTPRINTMAP_FILE ) ) . '/languages' );
	}

	/* ================================================================
	 * 设置工具
	 * ================================================================ */

	/**
	 * 读取插件设置（缺失项以默认值补齐）。
	 *
	 * @return array 设置数组。
	 */
	public function get_settings() {
		$defaults = array(
			'key'      => '',
			'jscode'   => '',
			'cluster_distance' => 50,
			'post_tag' => '', // 默认留空：列出全部文章；填标签名才按标签过滤。
			'default_image' => 0, // 无关联文章点的默认点位图片（附件 ID，0=未设置）。
		);
		$saved = get_option( 'footprintmap_settings', array() );
		return wp_parse_args( (array) $saved, $defaults );
	}

	/**
	 * 返回地点数据表名。
	 *
	 * @return string 表名。
	 */
	public function table() {
		return $this->table;
	}

	/* ================================================================
	 * 后台菜单与页面渲染
	 * ================================================================ */

	/**
	 * 注册后台顶级菜单（地点管理）与其子页（地图设置）。
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( '足迹地图', 'footprintmap' ),
			__( '足迹地图', 'footprintmap' ),
			'manage_options',
			'footprintmap',
			array( $this, 'render_map_admin_page' ),
			'dashicons-location-alt',
			26
		);
		add_submenu_page(
			'footprintmap',
			__( '地点管理', 'footprintmap' ),
			__( '地点管理', 'footprintmap' ),
			'manage_options',
			'footprintmap',
			array( $this, 'render_map_admin_page' )
		);
		add_submenu_page(
			'footprintmap',
			__( '地图设置', 'footprintmap' ),
			__( '地图设置', 'footprintmap' ),
			'manage_options',
			'footprintmap-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * 在插件列表页的操作列中前置「地图设置」链接。
	 *
	 * @param array $links 原有操作链接。
	 * @return array 追加后的操作链接。
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . admin_url( 'admin.php?page=footprintmap-settings' ) . '">' . __( '地图设置', 'footprintmap' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * 渲染地点管理页（模板文件）。
	 */
	public function render_map_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require FOOTPRINTMAP_DIR . 'admin/map-admin-page.php';
	}

	/**
	 * 渲染地图设置页（模板文件）。
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require FOOTPRINTMAP_DIR . 'admin/settings-page.php';
	}

	/* ================================================================
	 * 后台静态资源加载
	 * ================================================================ */

	/**
	 * 仅在本插件页面加载后台资源（地图页 + 设置页）。
	 *
	 * @param string $hook 当前后台页面 hook（实际按 $_GET['page'] 判断）。
	 */
	public function enqueue_admin_assets( $hook ) {
		// 用 $_GET['page'] 判断本插件页面，避免 hook 名因菜单结构差异而不匹配。
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'footprintmap' !== $page && 'footprintmap-settings' !== $page ) {
			return;
		}
		$settings = $this->get_settings();
		$is_map_page = ( 'footprintmap' === $page );

		if ( empty( $settings['key'] ) && $is_map_page ) {
			// 未配置 Key：页面内会显示提示，暂不需要地图资源。
			return;
		}

		// 媒体库：地图页与设置页都需要（设置页「默认点位图片」选择器依赖 wp.media）。
		wp_enqueue_media();

		if ( $is_map_page ) {
			// 高德地图 JS。注意：不再带 callback= 参数 —— 高德 JsApi 若先于定义该回调的
			// admin-map.js 执行，会因找不到 callback 而报“Can not find callback”并中断加载，
			// 使 AMap 处于半就绪状态（仅部分对象存在），进而让轮询误判就绪而抛错。
			// 故与前台一致：纯加载 JsApi，由 admin-map.js 轮询 AMap 及其所需插件全就绪后再建图。
			$map_js = '//webapi.amap.com/maps?v=2.0&key=' . urlencode( $settings['key'] ) . '&plugin=AMap.Scale,AMap.DistrictSearch,AMap.AutoComplete,AMap.Geocoder,AMap.GeometryUtil';
			wp_enqueue_script( 'footprintmap-admin-amap', $map_js, array(), FOOTPRINTMAP_VERSION, true );
			// 安全配置须在 AMap JS 执行前注入。
			if ( ! empty( $settings['jscode'] ) ) {
				wp_add_inline_script( 'footprintmap-admin-amap', 'window._AMapSecurityConfig = { securityJsCode: ' . wp_json_encode( $settings['jscode'] ) . ' };', 'before' );
			}
			wp_enqueue_script( 'footprintmap-admin-map', FOOTPRINTMAP_URL . 'admin/js/admin-map.js', array( 'jquery' ), FOOTPRINTMAP_VERSION, true );
		} else {
			// 设置页：媒体选择器脚本。不依赖 media-editor handle（避免因依赖不满足被静默跳过），
			// 脚本内部自行轮询 window.wp.media 就绪后再绑定（与 AMap 轮询模式一致）。
			// wp_enqueue_media() 已在上方调用，提供 wp.media 所需的脚本与数据。
			wp_enqueue_script(
				'footprintmap-media-picker',
				FOOTPRINTMAP_URL . 'admin/js/media-picker.js',
				array(),
				FOOTPRINTMAP_VERSION,
				true
			);
			wp_localize_script( 'footprintmap-media-picker', 'FootprintMapMediaPicker', array(
				'title'  => __( '选择默认点位图片', 'footprintmap' ),
				'button' => __( '使用这张图片', 'footprintmap' ),
			) );
		}

		wp_enqueue_style( 'footprintmap-admin', FOOTPRINTMAP_URL . 'admin/css/admin.css', array(), FOOTPRINTMAP_VERSION );

		$data = array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'footprintmap_admin' ),
			'hasKey'      => ! empty( $settings['key'] ),
			'isMapPage'   => $is_map_page,
			'cluster'     => absint( $settings['cluster_distance'] ),
			'postTag'     => isset( $settings['post_tag'] ) ? trim( (string) $settings['post_tag'] ) : '',
			'posts'       => $is_map_page ? $this->get_tagged_posts() : array(),
			'i18n'        => array(
				'pleaseClick' => __( '请在地图上点击选择地点（可直接拖动地图再点选）', 'footprintmap' ),
				'searchPlaceholder' => __( '搜索地点/城市后点选…', 'footprintmap' ),
				'save' => __( '保存标记', 'footprintmap' ),
				'delete' => __( '删除', 'footprintmap' ),
				'confirmDelete' => __( '确定删除该地点吗？', 'footprintmap' ),
				'inputName' => __( '请输入地点名称', 'footprintmap' ),
				'saved' => __( '已保存', 'footprintmap' ),
				'error' => __( '保存失败', 'footprintmap' ),
				'deleted' => __( '已删除', 'footprintmap' ),
				'deleteError' => __( '删除失败，请重试（若持续失败请刷新页面）', 'footprintmap' ),
				'noArticles' => __( '该点暂无关联文章', 'footprintmap' ),
				'selectPost' => __( '— 选择要关联的文章 —', 'footprintmap' ),
				'noTagPosts' => __( '没有可关联的文章', 'footprintmap' ),
				'tagHint'    => __( '仅显示带标签「%s」的文章。可在「地图设置 → 关联文章标签名」修改显示范围。', 'footprintmap' ),
				'allPostsHint'=> __( '当前列出全部文章（关联文章标签名已留空）。', 'footprintmap' ),
				'filterAll'  => __( '全部', 'footprintmap' ),
				'noMatch'    => __( '没有符合筛选条件的地点。', 'footprintmap' ),
				'prev'       => __( '上一页', 'footprintmap' ),
				'next'       => __( '下一页', 'footprintmap' ),
				'pageInfo'   => __( '%1$d / %2$d', 'footprintmap' ),
				'total'      => __( '共 %d 个地点', 'footprintmap' ),
				'clusterTitle' => __( '该区域有 %d 个地点，点击选择编辑', 'footprintmap' ),
			),
		);
		wp_localize_script( 'footprintmap-admin-map', 'FootprintMapAdmin', $data );
	}

	/* ================================================================
	 * AJAX 处理
	 * ================================================================ */

	/**
	 * 保存地点（id>0 更新，否则新增）。
	 */
	public function ajax_save_location() {
		check_ajax_referer( 'footprintmap_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'footprintmap' ) ) );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$lat    = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : 0;
		$lng    = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : 0;
		$city   = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
		$province = isset( $_POST['province'] ) ? sanitize_text_field( wp_unslash( $_POST['province'] ) ) : '';
		$country  = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

		$post_ids = array();
		if ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
			foreach ( (array) wp_unslash( $_POST['post_ids'] ) as $pid ) {
				$pid = absint( $pid );
				if ( $pid > 0 ) {
					$post_ids[] = $pid;
				}
			}
		}
		$post_ids = array_values( array_unique( $post_ids ) );

		if ( empty( $name ) || empty( $lat ) || empty( $lng ) ) {
			wp_send_json_error( array( 'message' => __( '地点名称、经纬度不能为空', 'footprintmap' ) ) );
		}

		// 经纬度范围校验：纬度 [-90,90]、经度 [-180,180]。越界直接拒绝，避免脏数据
		// 破坏前台/后台的聚类、区划着色与反地理编码。
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			wp_send_json_error( array( 'message' => __( '经纬度超出有效范围（纬度 -90~90，经度 -180~180）。', 'footprintmap' ) ) );
		}

		global $wpdb;
		$data = array(
			'name'      => $name,
			'city'      => $city,
			'province'  => $province,
			'country'   => $country,
			'lat'       => $lat,
			'lng'       => $lng,
			'post_ids'  => maybe_serialize( $post_ids ),
			'created_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s' );

		if ( $id ) {
			$wpdb->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$new_id = $id;
		} else {
			$wpdb->insert( $this->table, $data, $formats );
			$new_id = (int) $wpdb->insert_id;
		}

		// 地点数据变化 → 清除前台缓存，保证前台即时反映。
		$this->bust_front_cache();

		wp_send_json_success( array(
			'id'   => $new_id,
			'data' => $this->get_location_row( $new_id ),
		) );
	}

	/**
	 * 删除一个地点。
	 */
	public function ajax_delete_location() {
		check_ajax_referer( 'footprintmap_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error();
		}
		global $wpdb;
		$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
		$this->bust_front_cache();
		wp_send_json_success();
	}

	/**
	 * 取回全部地点（供后台表格/地图使用）。
	 */
	public function ajax_get_locations() {
		check_ajax_referer( 'footprintmap_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( $this->get_all_locations() );
	}

	/**
	 * 按关键词搜索文章（下拉候选）。
	 */
	public function ajax_search_posts() {
		check_ajax_referer( 'footprintmap_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$term_len = function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term );
		if ( $term_len < 1 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			's'              => $term,
		);
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $p ) {
			$thumb = get_the_post_thumbnail_url( $p->ID, 'thumbnail' );
			$items[] = array(
				'id'     => $p->ID,
				'title'  => get_the_title( $p ),
				'thumb'  => $thumb ? $thumb : '',
			);
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/* ================================================================
	 * CSV 导入 / 导出
	 * ================================================================ */

	/**
	 * CSV 表头（键为规范字段名，值为可翻译的「友好列名」）。
	 *
	 * @return array 字段名 => 表头文案。
	 */
	public function csv_headers() {
		return array(
			'name'     => __( 'name(地点名称)', 'footprintmap' ),
			'city'     => __( 'city(城市)', 'footprintmap' ),
			'province' => __( 'province(省份)', 'footprintmap' ),
			'country'  => __( 'country(国家)', 'footprintmap' ),
			'lat'      => __( 'lat(纬度)', 'footprintmap' ),
			'lng'      => __( 'lng(经度)', 'footprintmap' ),
			'post_ids' => __( 'post_ids(关联文章ID，分号分隔)', 'footprintmap' ),
		);
	}

	/**
	 * 防公式注入（CSV Injection）：以 = + - @ 或 Tab/CR 开头的字段在 Excel 打开时
	 * 会被当作公式解析，导出时给这类字段加 "'" 前缀转义。
	 *
	 * @param string $value 字段值。
	 * @return string
	 */
	private function csv_safe( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * csv_safe 的逆操作：导入时还原被转义的字段（保证“导出→再导入”内容不变）。
	 *
	 * @param string $value 字段值。
	 * @return string
	 */
	private function csv_unsafe( $value ) {
		$value = (string) $value;
		if ( 0 === strpos( $value, "'" ) && preg_match( '/^[=+\-@\t\r]/', substr( $value, 1 ) ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * 导出全部地点为 CSV（UTF-8 带 BOM，供下载）。
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'footprintmap' ) );
		}
		check_admin_referer( 'footprintmap_export_csv' );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, name, city, province, country, lat, lng, post_ids FROM {$this->table} ORDER BY id ASC", ARRAY_A );

		// 列顺序（固定，便于回导）。
		$headers = $this->csv_headers();

		$out = fopen( 'php://temp', 'r+' );
		// 表头行：使用友好列名（含英文键与中文说明），便于识别字段。
		fputcsv( $out, array_values( $headers ) );
		// 数据行：post_ids 以分号连接。fputcsv 自动处理引号/逗号/换行。
		// 文本字段经 csv_safe 防公式注入转义（Excel 打开以 =+-@ 开头的字段不会被执行）。
		foreach ( $rows as $r ) {
			$post_ids = maybe_unserialize( $r['post_ids'] );
			if ( ! is_array( $post_ids ) ) {
				$post_ids = array();
			}
			$line = array(
				$this->csv_safe( $r['name'] ),
				$this->csv_safe( $r['city'] ),
				$this->csv_safe( $r['province'] ),
				$this->csv_safe( $r['country'] ),
				$r['lat'],
				$r['lng'],
				implode( ';', array_map( 'intval', $post_ids ) ),
			);
			fputcsv( $out, $line );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );

		// 编码：默认 UTF-8 带 BOM，保证 Windows Excel 直接打开不乱码。
		$bom = "\xEF\xBB\xBF";
		$filename = 'footprintmap-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $bom . $csv;
		exit;
	}

	/**
	 * 导入上传的 CSV：覆盖更新（先清空旧点，再按文件重建）。
	 */
	public function handle_import_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'footprintmap' ) );
		}
		check_admin_referer( 'footprintmap_import_csv' );

		$page_url = admin_url( 'admin.php?page=footprintmap' );
		$redirect = function( $msg ) use ( $page_url ) {
			wp_safe_redirect( add_query_arg( 'footprintmap_msg', $msg, $page_url ) );
			exit;
		};

		if ( empty( $_FILES['footprintmap_csv']['tmp_name'] ) ) {
			$redirect( 'no-file' );
		}

		$tmp   = $_FILES['footprintmap_csv']['tmp_name']; // phpcs:ignore
		$error = (int) $_FILES['footprintmap_csv']['error'];
		if ( UPLOAD_ERR_OK !== $error ) {
			$redirect( 'upload-error' );
		}

		// 文件体积上限：避免整文件读入内存拖垮 PHP（超限直接拒绝）。
		$max_bytes = 5 * 1024 * 1024; // 5 MB
		if ( (int) $_FILES['footprintmap_csv']['size'] > $max_bytes ) {
			$redirect( 'too-large' );
		}

		$raw = file_get_contents( $tmp ); // phpcs:ignore
		if ( false === $raw ) {
			$redirect( 'read-error' );
		}

		// 编码归一：移除 UTF-8 BOM；若为 UTF-16(LE/BE) 转 UTF-8（Windows Excel 另存为可能的编码）。
		$raw = $this->normalize_encoding( $raw );

		// 用内存流交给 str_getcsv 逐行解析（兼容引号/换行字段）。
		$lines = $this->parse_csv( $raw );
		if ( count( $lines ) < 2 ) {
			$redirect( 'empty' );
		}
		// 行数上限：防止超大型 CSV 一次逐行 insert 造成超时/锁表（含表头，取上限+1 行以内）。
		$max_rows = 5000 + 1; // 允许 ~5000 条数据行
		if ( count( $lines ) > $max_rows ) {
			$redirect( 'too-many-rows' );
		}

		// 定位表头：兼容含/不含 BOM、首行是否为表头。寻找包含 lng/lat 的行作为表头。
		$header_idx = -1;
		$canon = array(); // canonical field -> col index
		foreach ( $lines as $i => $line ) {
			$joined = strtolower( implode( ' ', $line ) );
			if ( preg_match( '/(lng|longitude|经)/', $joined ) && preg_match( '/(lat|latitude|纬)/', $joined ) ) {
				$header_idx = $i;
				foreach ( $line as $k => $col ) {
					$f = $this->canonical_field( $col );
					if ( $f ) {
						$canon[ $f ] = $k;
					}
				}
				break;
			}
		}

		global $wpdb;
		$added   = 0;
		$skipped = 0;
		$errors  = array();

		// 导入语义：始终「覆盖更新」——先清空全部现有点位，再按 CSV 行序重建，
		// 使表严格等于文件内容。不再需要导入模式与 CSV 的 id 列：
		// id 由数据库自增自动分配（CSV 不含 id，用户也不可见，无需手动编排）。
		$deleted   = 0;
		$data_line_count = count( $lines ) - ( $header_idx >= 0 ? 1 : 0 );
		if ( $data_line_count < 1 ) {
			set_transient( 'footprintmap_import_result', array(
				'added'   => 0,
				'deleted' => 0,
				'skipped' => 0,
				'errors'  => array( __( '导入：CSV 中没有可导入的数据行，已中止，未做任何更改。', 'footprintmap' ) ),
			), 60 );
			$redirect( 'imported' );
		}

		// 覆盖更新不可撤销：先清空旧数据，再按 CSV 重建。
		$old_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		$wipe      = $wpdb->query( "DELETE FROM {$this->table}" );
		// wipe 为受影响行数（0=原表本就为空）；false=语句执行失败。
		if ( false === $wipe ) {
			set_transient( 'footprintmap_import_result', array(
				'added'   => 0,
				'deleted' => 0,
				'skipped' => 0,
				'errors'  => array( __( '导入：清空旧数据失败（请检查表权限），已中止，未做任何更改。', 'footprintmap' ) ),
			), 60 );
			$redirect( 'imported' );
		}
		$deleted = $old_count;

		// 逐数据行（跳过表头）。id 交由数据库自增分配，无需在此指定。
		for ( $i = 0; $i < count( $lines ); $i++ ) {
			if ( $i === $header_idx ) {
				continue;
			}
			$row = $lines[ $i ];
			// 空行跳过。
			if ( count( array_filter( $row, 'strlen' ) ) === 0 ) {
				continue;
			}

			$rec = array(
				'name'     => '',
				'city'     => '',
				'province' => '',
				'country'  => '',
				'lat'      => '',
				'lng'      => '',
				'post_ids' => '',
			);

			if ( $header_idx >= 0 ) {
				// 按规范化字段取列。
				$bykey = array();
				foreach ( $canon as $field => $idx ) {
					$bykey[ $field ] = isset( $row[ $idx ] ) ? $row[ $idx ] : '';
				}
				// 说明：CSV 不含 id 列（即便旧文件带了 id 列也会被这里忽略，id 由数据库自增分配）。
				$rec['name']     = isset( $bykey['name'] ) ? $bykey['name'] : '';
				$rec['city']     = isset( $bykey['city'] ) ? $bykey['city'] : '';
				$rec['province'] = isset( $bykey['province'] ) ? $bykey['province'] : '';
				$rec['country']  = isset( $bykey['country'] ) ? $bykey['country'] : '';
				$rec['lat']      = $this->first_nonempty( array( isset( $bykey['lat'] ) ? $bykey['lat'] : '', isset( $bykey['latitude'] ) ? $bykey['latitude'] : '' ) );
				$rec['lng']      = $this->first_nonempty( array( isset( $bykey['lng'] ) ? $bykey['lng'] : '', isset( $bykey['longitude'] ) ? $bykey['longitude'] : '' ) );
				$rec['post_ids'] = isset( $bykey['post_ids'] ) ? $bykey['post_ids'] : '';
			} else {
				// 固定顺序回退：name,city,province,country,lat,lng,post_ids
				$rec['name']     = isset( $row[0] ) ? $row[0] : '';
				$rec['city']     = isset( $row[1] ) ? $row[1] : '';
				$rec['province'] = isset( $row[2] ) ? $row[2] : '';
				$rec['country']  = isset( $row[3] ) ? $row[3] : '';
				$rec['lat']      = isset( $row[4] ) ? $row[4] : '';
				$rec['lng']      = isset( $row[5] ) ? $row[5] : '';
				$rec['post_ids'] = isset( $row[6] ) ? $row[6] : '';
			}

			// 还原导出时的公式注入转义前缀（csv_unsafe 是 csv_safe 的逆操作）。
			$rec['name']     = $this->csv_unsafe( trim( $rec['name'] ) );
			$rec['city']     = $this->csv_unsafe( trim( $rec['city'] ) );
			$rec['province'] = $this->csv_unsafe( trim( $rec['province'] ) );
			$rec['country']  = $this->csv_unsafe( trim( $rec['country'] ) );
			$rec['name'] = sanitize_text_field( $rec['name'] );
			$lat = (float) str_replace( ',', '.', $rec['lat'] );
			$lng = (float) str_replace( ',', '.', $rec['lng'] );

			if ( '' === $rec['name'] || ! $lat || ! $lng ) {
				$skipped++;
				$errors[] = sprintf( __( '第 %d 行缺少名称或坐标，已跳过。', 'footprintmap' ), $i + 1 );
				continue;
			}
			// 坐标范围校验：越界的行视为无效跳过，避免脏坐标进入数据库。
			if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
				$skipped++;
				$errors[] = sprintf( __( '第 %d 行坐标超出有效范围（纬度 -90~90，经度 -180~180），已跳过。', 'footprintmap' ), $i + 1 );
				continue;
			}

			$post_ids = $this->parse_post_ids( $rec['post_ids'] );

			$field_data = array(
				'name'       => $rec['name'],
				'city'       => sanitize_text_field( $rec['city'] ),
				'province'   => sanitize_text_field( $rec['province'] ),
				'country'    => sanitize_text_field( $rec['country'] ),
				'lat'        => $lat,
				'lng'        => $lng,
				'post_ids'   => maybe_serialize( $post_ids ),
				'created_at' => current_time( 'mysql' ),
			);
			$field_fmt = array( '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s' );
			$ok = $wpdb->insert( $this->table, $field_data, $field_fmt );
			if ( $ok ) {
				$added++;
			} else {
				$skipped++;
				$errors[] = sprintf( __( '第 %d 行写入失败。', 'footprintmap' ), $i + 1 );
			}
		}

		// 导入后整表已重建 → 清除前台缓存。
		$this->bust_front_cache();

		// 记导入汇总，带回落提示。
		set_transient( 'footprintmap_import_result', array(
			'added'   => $added,
			'deleted' => $deleted,
			'skipped' => $skipped,
			'errors'  => $errors,
		), 60 );

		$redirect( 'imported' );
	}

	/**
	 * 将文件字节串统一转为 UTF-8：去除 UTF-8 BOM、解码 UTF-16，
	 * 若检测到非法 UTF-8 则尝试按 GB18030 转码。
	 *
	 * @param string $raw 原始字节。
	 * @return string 归一后的 UTF-8 文本。
	 */
	private function normalize_encoding( $raw ) {
		// 去 UTF-8 BOM。
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		// UTF-16 LE BOM。
		if ( 0 === strpos( $raw, "\xFF\xFE" ) && function_exists( 'mb_convert_encoding' ) ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
			return $raw;
		}
		// UTF-16 BE BOM。
		if ( 0 === strpos( $raw, "\xFE\xFF" ) && function_exists( 'mb_convert_encoding' ) ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
			return $raw;
		}
		// 若是 GBK/GB18030（旧版 Excel 另存）且检测到非法 UTF-8，则转码。
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$converted = @mb_convert_encoding( $raw, 'UTF-8', 'GB18030' );
			if ( $converted ) {
				return $converted;
			}
		}
		return $raw;
	}

	/**
	 * 把 CSV 文本解析为行数组（归一换行、忽略空行）。
	 *
	 * @param string $raw CSV 内容。
	 * @return array 行数组，每行为字段数组。
	 */
	private function parse_csv( $raw ) {
		$rows = array();
		$raw  = str_replace( "\r\n", "\n", $raw );
		$raw  = str_replace( "\r", "\n", $raw );
		$lines = explode( "\n", $raw );
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			// 注意：str_getcsv 无法处理字段内嵌换行，但通常坐标 CSV 无内嵌换行。这里对含引号的字段使用状态机式安全处理兜底。
			$parsed = $this->split_csv_line( $line );
			if ( $parsed ) {
				$rows[] = $parsed;
			}
		}
		return $rows;
	}

	/**
	 * 按引号/转义引号/逗号状态机切分单个 CSV 逻辑行。
	 *
	 * @param string $line 单个 CSV 逻辑行。
	 * @return array 字段数组（已 trim）。
	 */
	private function split_csv_line( $line ) {
		$fields = array();
		$len    = strlen( $line );
		$i      = 0;
		$cur    = '';
		$in_q   = false;
		while ( $i < $len ) {
			$ch = $line[ $i ];
			if ( $in_q ) {
				if ( '"' === $ch ) {
					if ( $i + 1 < $len && '"' === $line[ $i + 1 ] ) {
						$cur .= '"';
						$i++;
					} else {
						$in_q = false;
					}
				} else {
					$cur .= $ch;
				}
			} else {
				if ( '"' === $ch ) {
					$in_q = true;
				} elseif ( ',' === $ch ) {
					$fields[] = $cur;
					$cur      = '';
				} else {
					$cur .= $ch;
				}
			}
			$i++;
		}
		$fields[] = $cur;
		return array_map( 'trim', $fields );
	}

	/**
	 * 把 CSV 表头单元格映射为规范字段名。
	 * 兼容本插件导出的友好表头（如 “name(地点名称)”）及常见同义词。
	 *
	 * @param string $col 表头单元格文本。
	 * @return string 规范字段名，无法识别返回 ''。
	 */
	private function canonical_field( $col ) {
		$col = trim( (string) $col );
		// 去掉友好标题中的括号注释部分，取规范键。如 "name(地点名称)" -> "name"。
		$plain = strtolower( preg_replace( '/[（(].*?[)）]/u', '', $col ) );
		$plain = preg_replace( '/[^a-z0-9]/', '', $plain );

		$map = array(
			'id'            => 'id',
			'name'          => 'name',
			'city'          => 'city',
			'province'      => 'province',
			'country'       => 'country',
			'countryen'     => 'country',
			'lat'           => 'lat',
			'latitude'      => 'lat',
			'纬度'          => 'lat',
			'lng'           => 'lng',
			'lon'           => 'lng',
			'long'          => 'lng',
			'longitude'     => 'lng',
			'经度'          => 'lng',
			'postids'       => 'post_ids',
			'posts'         => 'post_ids',
			'postid'        => 'post_ids',
		);
		if ( isset( $map[ $plain ] ) ) {
			return $map[ $plain ];
		}
		// 中文列名直接匹配。
		$zh = array( '名称' => 'name', '地点' => 'name', '城市' => 'city', '省份' => 'province', '省' => 'province', '国家' => 'country', '纬度' => 'lat', '经度' => 'lng', '关联文章' => 'post_ids', '文章id' => 'post_ids' );
		foreach ( $zh as $k => $v ) {
			if ( false !== strpos( $col, $k ) ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * 取若干值中首个非空者（用于 lat/lng 的候选回退列）。
	 *
	 * @param array $vals 候选值数组。
	 * @return string 首个非空值，均为空返回 ''。
	 */
	private function first_nonempty( $vals ) {
		foreach ( $vals as $v ) {
			$v = trim( (string) $v );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * 解析 post_ids 字段文本（兼容分号/逗号/顿号/空格等分隔）为去重后的 id 数组。
	 *
	 * @param string $raw 原始字段文本。
	 * @return array 正整数 id 数组。
	 */
	private function parse_post_ids( $raw ) {
		$raw  = str_replace( array( '，', ';', '；', ',', '、' ), ' ', (string) $raw );
		$ids  = preg_split( '/\s+/', trim( $raw ) );
		$out  = array();
		foreach ( (array) $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ================================================================
	 * 数据读取
	 * ================================================================ */

	/**
	 * 取单条地点，并附带关联文章汇总（posts）与首图（featured）。
	 *
	 * @param int $id 地点 id。
	 * @return array|null 地点数组，不存在返回 null。
	 */
	public function get_location_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['post_ids']  = maybe_unserialize( $row['post_ids'] );
		$row['posts']     = $this->get_posts_summary( $row['post_ids'] );
		$row['featured']  = $this->get_featured_image( $row['post_ids'] );
		$row['lat']       = (float) $row['lat'];
		$row['lng']       = (float) $row['lng'];
		return $row;
	}

	/**
	 * 前台地点数据缓存键（transient）。键不含地点内容变化信息，靠下方显式失效 + 短 TTL 兜底，
	 * 兼顾“后台改动即时生效”与“文章标题/缩略图等外部改动最终一致”。
	 */
	private function front_cache_key() {
		return 'footprintmap_front_locs';
	}

	/**
	 * 取前台需要的地点数据（含关联文章汇总），带 transient 缓存。
	 *
	 * 前台每次渲染含 [footprintmap] 的页面都要整份地点+关联文章数据；地点与文章较多时
	 * get_all_locations() 的一次 WP_Query(post__in) 与缩略图预热仍偏重。用 10 分钟 TTL 缓存
	 * 组装结果，地点在后台增删改/导入时会显式失效，文章标题等外部改动至多 10 分钟后反映。
	 *
	 * @return array
	 */
	private function get_front_locations_cached() {
		$cached = get_transient( $this->front_cache_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = $this->get_all_locations();
		// 空表不缓存：避免缓存一个“永空”的结果，使新加地点能立刻显示。
		if ( ! empty( $data ) ) {
			set_transient( $this->front_cache_key(), $data, 10 * MINUTE_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * 地点数据变化时清除前台缓存（新增/更新/删除/导入后调用，保证前台即时刷新）。
	 */
	private function bust_front_cache() {
		delete_transient( $this->front_cache_key() );
	}

	/**
	 * 取回全部地点，并批量附加关联文章汇总与特色图。
	 *
	 * 性能说明：全部点位的关联文章数据（标题/链接/缩略图）用一次 WP_Query +
	 * update_post_thumbnail_cache() 批量取得，避免逐点逐篇的 N+1 查询
	 * （原先每个 get_post_status/get_the_title/get_permalink/
	 * get_the_post_thumbnail_url 都独立触发元数据查询，点位多时数百次起步）。
	 *
	 * @return array 地点数组（含 posts / featured 字段）。
	 */
	public function get_all_locations() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY id ASC", ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		// 1) 收集全部关联文章 id（去重）。
		$all_post_ids = array();
		foreach ( $rows as $row ) {
			$ids = maybe_unserialize( $row['post_ids'] );
			if ( is_array( $ids ) ) {
				foreach ( $ids as $pid ) {
					$pid = (int) $pid;
					if ( $pid > 0 ) {
						$all_post_ids[ $pid ] = true;
					}
				}
			}
		}

		// 2) 一次批量取已发布文章 + 预热特色图缓存
		//    （update_post_thumbnail_cache 让随后的 get_the_post_thumbnail_url 命中缓存，零额外查询）。
		$posts_map = array();
		if ( $all_post_ids ) {
			$query = new WP_Query( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post__in'       => array_keys( $all_post_ids ),
				'posts_per_page' => count( $all_post_ids ),
				'orderby'        => 'post__in',
				'no_found_rows'  => true,
			) );
			update_post_thumbnail_cache( $query );
			foreach ( $query->posts as $p ) {
				$posts_map[ $p->ID ] = array(
					'title'    => get_the_title( $p ),
					'link'     => get_permalink( $p ),
					'thumb'    => get_the_post_thumbnail_url( $p, 'medium' ),
					'featured' => get_the_post_thumbnail_url( $p, 'large' ),
				);
			}
		}

		// 3) 逐点组装（语义与旧实现一致：posts 仅列已发布文章、顺序按关联 id；
		//    featured 取「第一篇有特色图文章」的 large 图）。
		$out = array();
		foreach ( $rows as $row ) {
			$ids = maybe_unserialize( $row['post_ids'] );
			$ids = is_array( $ids ) ? array_values( array_filter( $ids ) ) : array();

			$posts    = array();
			$featured = '';
			foreach ( $ids as $pid ) {
				$pid = (int) $pid;
				if ( ! isset( $posts_map[ $pid ] ) ) {
					continue; // 文章不存在或未发布（旧实现经 get_post_status 同样过滤）。
				}
				$pm = $posts_map[ $pid ];
				$posts[] = array(
					'id'    => $pid,
					'title' => $pm['title'],
					'link'  => $pm['link'],
					'thumb' => $pm['thumb'],
				);
				if ( '' === $featured && $pm['featured'] ) {
					$featured = $pm['featured'];
				}
			}

			$row['post_ids'] = $ids;
			$row['posts']    = $posts;
			$row['featured'] = $featured;
			$row['id']       = (int) $row['id']; // 必须转 int，否则前台严格相等匹配不到
			$row['lat']      = (float) $row['lat'];
			$row['lng']      = (float) $row['lng'];
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * 由关联文章 id 组装前台展示用摘要列表（仅已发布文章，带缩略图）。
	 *
	 * @param array $ids 文章 id 数组。
	 * @return array 每项含 id/title/link/thumb。
	 */
	private function get_posts_summary( $ids ) {
		$ids = array_values( array_filter( (array) $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$items = array();
		foreach ( $ids as $pid ) {
			if ( 'publish' !== get_post_status( $pid ) ) {
				continue;
			}
			$items[] = array(
				'id'    => (int) $pid,
				'title' => get_the_title( $pid ),
				'link'  => get_permalink( $pid ),
				'thumb' => get_the_post_thumbnail_url( $pid, 'medium' ),
			);
		}
		return $items;
	}

	/**
	 * 取关联文章中第一张特色图（large）的 URL。
	 *
	 * @param array $ids 文章 id 数组。
	 * @return string 图片 URL，无则空串。
	 */
	private function get_featured_image( $ids ) {
		$ids = array_values( array_filter( (array) $ids ) );
		if ( empty( $ids ) ) {
			return '';
		}
		foreach ( $ids as $pid ) {
			$url = get_the_post_thumbnail_url( $pid, 'large' );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * 供后台「关联文章」下拉的候选文章列表。
	 *
	 * 依据设置里的「关联文章标签名」post_tag 过滤：
	 *  - 为空 → 返回全部已发布文章（不限标签）；
	 *  - 可填多个标签，用半角英文逗号分隔（如 “旅行,攻略”），命中其中任意一个标签的文章都会列出。
	 *
	 * @return array 每项含 id/title/thumb 的文章列表。
	 */
	public function get_tagged_posts() {
		$settings = $this->get_settings();
		$raw      = isset( $settings['post_tag'] ) ? trim( (string) $settings['post_tag'] ) : '';

		$items      = array();
		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( '' !== $raw ) {
			// 拆分多标签（半角英文逗号分隔），忽略空白片段。
			$parts = preg_split( '/,/', $raw );
			$parts = array_values( array_filter( array_map(
				function ( $p ) {
					return trim( (string) $p );
				},
				$parts
			), function ( $p ) {
				return '' !== $p;
			} ) );

			if ( ! empty( $parts ) ) {
				$terms = get_terms( array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				) );

				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					// 收集名称或别名(slug)中包含任一标签片段的词条 id（跨片段取并集 → OR 语义）。
					$needles  = array();
					foreach ( $parts as $part ) {
						$needles[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
					}
					$term_ids = array();
					foreach ( $terms as $t ) {
						$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t->name . ' ' . $t->slug, 'UTF-8' ) : strtolower( $t->name . ' ' . $t->slug );
						foreach ( $needles as $needle ) {
							if ( '' !== $needle && false !== strpos( $hay, $needle ) ) {
								$term_ids[] = (int) $t->term_id;
								break;
							}
						}
					}
					if ( ! empty( $term_ids ) ) {
						$query_args['tax_query'] = array(
							array(
								'taxonomy' => 'post_tag',
								'field'    => 'term_id',
								'terms'    => array_values( array_unique( $term_ids ) ),
							),
						);
					} else {
						// 有配置标签但站点里没有任何词条命中：返回空即可。
						return $items;
					}
				} else {
					return $items;
				}
			}
			// parts 全空（纯逗号/空白）按“未填标签”处理 → 走下方全部文章分支。
		}

		$query = new WP_Query( $query_args );

		foreach ( $query->posts as $p ) {
			$thumb = get_the_post_thumbnail_url( $p->ID, 'thumbnail' );
			$items[] = array(
				'id'    => (int) $p->ID,
				'title' => get_the_title( $p ),
				'thumb' => $thumb ? $thumb : '',
			);
		}
		return $items;
	}

	/* ================================================================
	 * 前台资源加载与短代码
	 * ================================================================ */

	/**
	 * 页面含 [footprintmap] 短代码时才加载前台资源。
	 */
	public function enqueue_public_assets() {
		global $post;
		if ( $post && ( has_shortcode( $post->post_content, 'footprintmap' )
			|| false !== strpos( $post->post_content, '[footprintmap' ) ) ) {
			$this->enqueue_public_front_assets();
		}
	}

	/**
	 * 实际加载前台脚本/样式（短代码渲染时也会调用作兜底）。
	 * 需已配置高德 Key，否则直接返回。
	 */
	private function enqueue_public_front_assets() {
		if ( did_action( 'wp_enqueue_scripts' ) && ! wp_script_is( 'footprintmap-front', 'registered' ) ) {
			$settings = $this->get_settings();
			if ( empty( $settings['key'] ) ) {
				return;
			}
			$locations = $this->get_front_locations_cached();

			// 边界数据按需加载（省界 ~553KB、世界边界 ~183KB，没必要恒定全载）：
			// 与前台 colorRegions 的判定顺序一致——任一点带省名则可能需要省界着色；
			// 任一点为国外（country 非空且非“中国”）或 country/province 皆空（需按经纬度
			// 推断归属）则需要世界边界。数据无需二次获取：localize 复用 $locations。
			$need_provinces = false;
			$need_world     = false;
			foreach ( $locations as $loc ) {
				$province = isset( $loc['province'] ) ? trim( (string) $loc['province'] ) : '';
				$country  = isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
				if ( '' !== $province ) {
					$need_provinces = true;
				}
				if ( ( '' !== $country && '中国' !== $country ) || ( '' === $country && '' === $province ) ) {
					$need_world = true;
				}
			}

			// 注意：不再使用 callback=... 触发初始化，以免高德脚本先于 footprintmap.js
			// 加载完成时回调未定义而报 “Can not find callback”。footprintmap.js 会自行
			// 轮询 window.AMap 就绪后再建图（见 public/js/footprintmap.js 的 startWhenReady）。
			$map_js = '//webapi.amap.com/maps?v=2.0&key=' . urlencode( $settings['key'] );
			wp_register_script( 'footprintmap-front-amap', $map_js, array(), FOOTPRINTMAP_VERSION, true );
			if ( ! empty( $settings['jscode'] ) ) {
				wp_add_inline_script( 'footprintmap-front-amap', 'window._AMapSecurityConfig = { securityJsCode: ' . wp_json_encode( $settings['jscode'] ) . ' };', 'before' );
			}
			$front_deps = array( 'footprintmap-front-amap' );
			// 世界国家边界（国外点位着色/归属推断）。国内点按“省级行政区”着色，数据在 footprintmap-provinces.js。
			if ( $need_world ) {
				wp_register_script( 'footprintmap-world', FOOTPRINTMAP_URL . 'public/js/footprintmap-world.js', array(), FOOTPRINTMAP_VERSION, true );
				$front_deps[] = 'footprintmap-world';
			}
			// 中国省级行政区边界（插件内置共享文件）：前台按“足迹所在省”填绿（含港澳台与南海诸岛归属），本地读取、零高德请求。
			if ( $need_provinces && file_exists( FOOTPRINTMAP_DIR . 'public/js/footprintmap-provinces.js' ) ) {
				wp_register_script( 'footprintmap-provinces', FOOTPRINTMAP_URL . 'public/js/footprintmap-provinces.js', array(), FOOTPRINTMAP_VERSION, true );
				$front_deps[] = 'footprintmap-provinces';
			}
			wp_register_script( 'footprintmap-front', FOOTPRINTMAP_URL . 'public/js/footprintmap.js', $front_deps, FOOTPRINTMAP_VERSION, true );
			$default_image_id = isset( $settings['default_image'] ) ? absint( $settings['default_image'] ) : 0;
			$default_image    = $default_image_id ? wp_get_attachment_image_url( $default_image_id, 'medium' ) : '';
			wp_localize_script( 'footprintmap-front', 'FootprintMapData', array(
				'locations' => $locations,
				'cluster'   => absint( $settings['cluster_distance'] ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'defaultImage' => $default_image,
			) );
			wp_enqueue_script( 'footprintmap-front-amap' );
			if ( $need_world ) {
				wp_enqueue_script( 'footprintmap-world' );
			}
			if ( wp_script_is( 'footprintmap-provinces', 'registered' ) ) {
				wp_enqueue_script( 'footprintmap-provinces' );
			}
			wp_enqueue_script( 'footprintmap-front' );
			wp_enqueue_style( 'footprintmap-front', FOOTPRINTMAP_URL . 'public/css/footprintmap.css', array(), FOOTPRINTMAP_VERSION );
		}
	}

	/**
	 * 渲染 [footprintmap] 短代码：输出容器、计数与 noscript 提示，并兜底入队资源。
	 *
	 * @return string 输出的 HTML。
	 */
	public function render_shortcode() {
		$settings = $this->get_settings();
		if ( empty( $settings['key'] ) ) {
			return '<p class="footprintmap-error">' . esc_html__( '请在后台「足迹地图 → 地图设置」中填写高德 Web服务 JS key。', 'footprintmap' ) . '</p>';
		}

		// 在此立即注册资源，保证短代码出现时一定会加载。
		if ( ! wp_script_is( 'footprintmap-front', 'registered' ) ) {
			$this->enqueue_public_front_assets();
		}
		// 强制入队实际已注册的脚本（即使 footer 已打印也能补上）；
		// 边界数据脚本按需注册，见 enqueue_public_front_assets。
		foreach ( array( 'footprintmap-front-amap', 'footprintmap-world', 'footprintmap-provinces', 'footprintmap-front' ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}

		ob_start();
		?>
		<div class="footprintmap-wrap">
			<div id="footprintmap-container" class="footprintmap-container"></div>
			<div class="footprintmap-status">
			<?php
			// 轻量计数：只取地点总数，不重复执行 get_all_locations() 的全量组装。
			global $wpdb;
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
			// 数字用 <b> 高亮（配合 .footprintmap-status b 样式）；先整体转义译文，
			// 再插入已转义的 <b>数字</b>，保证标签合法且数据安全。
			printf(
				/* translators: %s: 已标记地点数量（HTML 强调） */
				esc_html__( '已去过 %s 个地点', 'footprintmap' ),
				'<b>' . esc_html( $count ) . '</b>'
			);
			?>
			</div>
			<noscript><p class="footprintmap-error"><?php esc_html_e( '请启用 JavaScript 以查看足迹地图。', 'footprintmap' ); ?></p></noscript>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * 启动入口：返回主插件单例。
 */
function footprintmap() {
	return FootprintMap::instance();
}
footprintmap();
