<?php
/*
Plugin Name: Smart Media Manager
Plugin URI: https://github.com/sibche2013/Smart-Media-Manager
Description: جعبه‌ابزار کامل بهینه‌سازی تصاویر وردپرس: تبدیل خودکار به WebP/AVIF، کنترل سایز و کیفیت برای افزایش سرعت سایت، همراه با گالری لایتباکس حرفه‌ای (Fancybox).
Version: 3.2
Author: امین ارجمند
Author URI: https://aminarjmand.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: smart-media-manager
Requires at least: 5.0
Requires PHP: 7.4
*/

namespace SmartMediaManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    /**
     * نسخه افزونه
     */
    public const VERSION = '3.2';

    /**
     * نمونه تکین (Singleton)
     */
    private static ?Plugin $instance = null;

    /**
     * کلیدهای تنظیمات Options
     */
    public const OPTION_ENABLE_ISQM      = 'cfm_enable_isqm';
    public const OPTION_ENABLE_FANCY     = 'cfm_enable_fancybox';
    public const OPTION_ENABLE_FALLBACK  = 'cfm_enable_fallback_full';
    public const OPTION_ISQM_JPEG        = 'cfm_isqm_jpeg_quality';
    public const OPTION_ISQM_PNG         = 'cfm_isqm_png_quality';
    public const OPTION_DISABLED_SIZES   = 'cfm_disabled_image_sizes';
    public const OPTION_DISABLE_GUTEN    = 'cfm_disable_gutenberg';
    public const OPTION_CONVERT_FORMAT   = 'cfm_convert_format';
    public const OPTION_CONVERT_QUALITY  = 'cfm_convert_quality';

    /**
     * دریافت نمونه یکتا
     */
    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس و ثبت هوک‌ها
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * ثبت کلیه اکشن‌ها و فیلترهای وردپرس
     */
    private function init_hooks(): void {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        
        // کش سایز تصاویر (جلوگیری از خطای تعداد آرگومان در PHP 8)
        add_action( 'after_switch_theme', [ $this, 'clear_image_sizes_transient' ] );
        add_action( 'activated_plugin', [ $this, 'clear_image_sizes_transient' ], 10, 2 );
        add_action( 'deactivated_plugin', [ $this, 'clear_image_sizes_transient' ], 10, 2 );

        // بهینه‌سازی و فیلترهای کیفیت و تبدیل
        add_filter( 'wp_handle_upload', [ $this, 'handle_image_conversion' ] );
        add_filter( 'intermediate_image_sizes_advanced', [ $this, 'filter_disabled_image_sizes' ] );
        add_filter( 'jpeg_quality', [ $this, 'filter_jpeg_quality' ] );
        add_filter( 'wp_editor_set_quality', [ $this, 'filter_global_editor_quality' ], 10, 2 );

        // مدیریت گوتنبرگ
        add_filter( 'use_block_editor_for_post', [ $this, 'toggle_block_editor' ], 10 );
        add_filter( 'use_widgets_block_editor', [ $this, 'toggle_block_editor' ] );

        // قابلیت Fallback سایز تصاویر
        add_filter( 'image_downsize', [ $this, 'handle_image_fallback' ], 10, 3 );

        // لود اسکریپت‌ها و استایل‌ها
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // سفارشی‌سازی صفحه افزونه‌ها (اکشن‌بار و ردیف متادیتا)
        $plugin_file = plugin_basename( __FILE__ );
        add_filter( "plugin_action_links_{$plugin_file}", [ $this, 'add_action_links' ] );
        add_filter( 'plugin_row_meta', [ $this, 'custom_plugin_row_meta' ], 10, 2 );
        add_action( 'admin_head-plugins.php', [ $this, 'render_plugins_page_styles' ] );
    }

    /**
     * افزودن صفحه تنظیمات به منوی رسانه
     */
    public function register_admin_menu(): void {
        add_submenu_page(
            'upload.php',
            __( 'Smart Media Manager', 'smart-media-manager' ),
            __( 'Smart Media Manager', 'smart-media-manager' ),
            'manage_options',
            'cfm_settings_page',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * لینک رنگی صفحه تنظیمات کنار دکمه غیرفعال‌سازی در لیست افزونه‌ها
     */
    public function add_action_links( array $links ): array {
        $settings_url = admin_url( 'upload.php?page=cfm_settings_page' );
        $settings_link = sprintf(
            '<a href="%s" class="cfm-action-btn-settings">%s</a>',
            esc_url( $settings_url ),
            esc_html__( 'تنظیمات', 'smart-media-manager' )
        );

        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * استایل‌های اختصاصی صفحه مدیریت افزونه‌ها
     */
    public function render_plugins_page_styles(): void {
        ?>
        <style>
            .cfm-action-btn-settings {
                display: inline-block !important;
                background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
                color: #ffffff !important;
                font-weight: bold !important;
                padding: 2px 10px !important;
                border-radius: 4px !important;
                text-decoration: none !important;
                font-size: 11px !important;
                line-height: 1.6 !important;
                box-shadow: 0 1px 3px rgba(37, 99, 235, 0.3) !important;
                transition: all 0.2s ease-in-out !important;
            }
            .cfm-action-btn-settings:hover {
                background: linear-gradient(135deg, #1d4ed8, #1e40af) !important;
                color: #ffffff !important;
                transform: translateY(-1px) !important;
                box-shadow: 0 3px 6px rgba(37, 99, 235, 0.4) !important;
            }
            .cfm-version-badge {
                display: inline-block;
                background-color: #6366f1 !important;
                color: #ffffff !important;
                padding: 2px 8px;
                border-radius: 12px;
                font-weight: bold;
                font-size: 11px;
                box-shadow: 0 2px 4px rgba(99, 102, 241, 0.2);
                margin-left: 5px;
            }
            .cfm-meta-btn {
                display: inline-block !important;
                padding: 3px 10px !important;
                border-radius: 4px !important;
                color: #ffffff !important;
                font-weight: 600 !important;
                text-decoration: none !important;
                font-size: 11px !important;
                line-height: 1.5 !important;
                transition: all 0.2s ease-in-out !important;
                box-shadow: 0 1px 3px rgba(0,0,0,0.12) !important;
                margin-top: 4px;
            }
            .cfm-meta-btn:hover {
                opacity: 0.9 !important;
                color: #ffffff !important;
                transform: translateY(-1px) !important;
            }
            .cfm-btn-blue  { background-color: #2563eb !important; }
            .cfm-btn-green { background-color: #059669 !important; }
            .cfm-btn-red   { background-color: #dc2626 !important; }
        </style>
        <?php
    }

    /**
     * استایل‌دهی و لینک‌های متا در صفحه افزونه‌ها
     */
    public function custom_plugin_row_meta( array $plugin_meta, string $plugin_file ): array {
        if ( plugin_basename( __FILE__ ) === $plugin_file ) {
            if ( isset( $plugin_meta[0] ) ) {
                $plugin_meta[0] = '<span class="cfm-version-badge">' . esc_html( $plugin_meta[0] ) . '</span>';
            }
            if ( isset( $plugin_meta[1] ) ) unset( $plugin_meta[1] );
            if ( isset( $plugin_meta[2] ) ) unset( $plugin_meta[2] );

            $plugin_meta = array_values( $plugin_meta );

            // افزودن rel="noopener noreferrer" جهت امنیت تب‌های جدید
            $plugin_meta[] = '<a href="https://aminarjmand.com" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-blue">بدست امین ارجمند</a>';
            $plugin_meta[] = '<a href="https://aminarjmand.com" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-green">دیدن خانهٔ افزونه</a>';
            $plugin_meta[] = '<a href="https://aminarjmand.com" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-red">سایت سازنده</a>';
        }
        return $plugin_meta;
    }

    /**
     * رندر و مدیریت ذخیره صفحه تنظیمات
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $supports_webp = wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
        $supports_avif = wp_image_editor_supports( [ 'mime_type' => 'image/avif' ] );
        $has_imagick   = extension_loaded( 'imagick' ) && class_exists( '\Imagick', false );

        if ( isset( $_POST['cfm_settings_submit'] ) ) {
            check_admin_referer( 'cfm_settings_nonce', 'cfm_settings_nonce_field' );

            update_option( self::OPTION_ENABLE_ISQM, ! empty( $_POST['enable_isqm'] ) ? 1 : 0, 'no' );
            update_option( self::OPTION_ENABLE_FANCY, ! empty( $_POST['enable_fancybox'] ) ? 1 : 0, 'yes' );
            update_option( self::OPTION_ENABLE_FALLBACK, ! empty( $_POST['enable_fallback'] ) ? 1 : 0, 'yes' );

            if ( ! empty( $_POST['enable_isqm'] ) ) {
                $disabled_sizes = ! empty( $_POST['disabled_image_sizes'] ) ? array_map( 'sanitize_key', (array) $_POST['disabled_image_sizes'] ) : [];
                update_option( self::OPTION_DISABLED_SIZES, $disabled_sizes, 'no' );

                update_option( self::OPTION_ISQM_JPEG, ! empty( $_POST['cfm_jpeg_100'] ) ? 100 : 90, 'no' );
                update_option( self::OPTION_ISQM_PNG,  ! empty( $_POST['cfm_png_100'] ) ? 100 : 90, 'no' );

                $convert_format = isset( $_POST['cfm_convert_format'] ) ? sanitize_text_field( wp_unslash( $_POST['cfm_convert_format'] ) ) : 'none';
                if ( ( 'webp' === $convert_format && ! $supports_webp ) || ( 'avif' === $convert_format && ! $supports_avif ) ) {
                    $convert_format = 'none';
                }
                if ( ! in_array( $convert_format, [ 'none', 'webp', 'avif' ], true ) ) {
                    $convert_format = 'none';
                }
                update_option( self::OPTION_CONVERT_FORMAT, $convert_format, 'no' );

                $convert_quality = isset( $_POST['cfm_convert_quality'] ) ? absint( $_POST['cfm_convert_quality'] ) : 80;
                $convert_quality = max( 1, min( 100, $convert_quality ) );
                update_option( self::OPTION_CONVERT_QUALITY, $convert_quality, 'no' );

                $disable_guten = ! empty( $_POST['cfm_disable_gutenberg'] ) ? 1 : 0;
                update_option( self::OPTION_DISABLE_GUTEN, $disable_guten, 'no' );
                update_option( 'classic-editor-replace', $disable_guten ? 'classic' : false );
            }

            delete_transient( 'cfm_all_image_sizes' );
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        }

        $enable_isqm      = get_option( self::OPTION_ENABLE_ISQM, 0 );
        $enable_fancy     = get_option( self::OPTION_ENABLE_FANCY, 0 );
        $enable_fallback  = get_option( self::OPTION_ENABLE_FALLBACK, 0 );
        $image_sizes      = $this->get_all_image_sizes();
        $disabled_sizes   = get_option( self::OPTION_DISABLED_SIZES, [] );
        $jpeg_quality     = absint( get_option( self::OPTION_ISQM_JPEG, 90 ) );
        $png_quality      = absint( get_option( self::OPTION_ISQM_PNG, 90 ) );
        $convert_format   = get_option( self::OPTION_CONVERT_FORMAT, 'none' );
        $convert_quality  = absint( get_option( self::OPTION_CONVERT_QUALITY, 80 ) );
        $disable_guten    = get_option( self::OPTION_DISABLE_GUTEN, 0 );
        ?>

        <style>
            .cfm-wrap .postbox { border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            .cfm-wrap .hndle { border-bottom: 1px solid #f0f0f1; background: #fafafa; border-radius: 8px 8px 0 0; }
            .cfm-wrap h2.hndle span { font-weight: 600; color: #1d2327; font-size: 15px; }
            .cfm-wrap .inside { padding: 20px; }
            .cfm-toggle { display: inline-flex; align-items: center; cursor: pointer; gap: 12px; font-weight: 600; margin-bottom: 15px; }
            .cfm-toggle input { display: none; }
            .cfm-toggle-switch { position: relative; width: 44px; height: 24px; background: #cbd5e1; border-radius: 24px; transition: 0.3s; flex-shrink: 0; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1); }
            .cfm-toggle-switch::after { content: ''; position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; transition: 0.3s; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
            .cfm-toggle input:checked ~ .cfm-toggle-switch { background: #2271b1; }
            .cfm-toggle input:checked ~ .cfm-toggle-switch::after { transform: translateX(-20px); }
            .cfm-grid-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; background: #f6f7f7; padding: 15px; border: 1px solid #dcdcde; border-radius: 6px; margin-top: 10px; list-style: none; }
            .cfm-grid-list li { margin: 0; display: flex; align-items: center; }
            .cfm-grid-list label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
            .cfm-grid-list small { color: #646970; }
            .cfm-help-note { color: #505a62; font-size: 13px; margin-top: 5px; margin-bottom: 15px; line-height: 1.6; }
            .cfm-divider { border: 0; height: 1px; background: #f0f0f1; margin: 20px 0; }
            #cfm-isqm-settings { transition: opacity 0.3s ease; }
            .cfm-select-format { width: 100%; max-width: 480px; padding: 5px; border-radius: 4px; border-color: #8c8f94; }
            .cfm-format-group { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 8px; }
            .cfm-quality-input-wrapper { display: flex; align-items: center; gap: 6px; font-weight: 500; font-size: 13px; }
            .cfm-quality-input { width: 75px !important; text-align: center; font-weight: bold; border-radius: 4px; }

            /* استایل باکس رنگی نکته کیفیت */
            .cfm-highlight-tip {
                display: flex;
                align-items: center;
                gap: 8px;
                background: #fefce8;
                border: 1px solid #fef08a;
                border-right: 4px solid #eab308;
                border-radius: 6px;
                padding: 10px 14px;
                margin: 10px 0;
                color: #713f12;
                font-size: 13px;
                line-height: 1.6;
            }
            .cfm-highlight-tip strong { color: #854d0e; }

            /* استایل هشدار نبود اکستنشن Imagick */
            .cfm-imagick-warning {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                background: #fff1f2;
                border: 1px solid #fecdd3;
                border-right: 4px solid #e11d48;
                border-radius: 6px;
                padding: 12px 14px;
                margin: 12px 0 15px 0;
                color: #9f1239;
                font-size: 13px;
                line-height: 1.6;
            }
            .cfm-imagick-warning strong { color: #881337; }
        </style>

        <div class="wrap cfm-wrap">
            <h1 class="wp-heading-inline">مدیریت هوشمند رسانه</h1>
            <hr class="wp-header-end">

            <form method="post" action="">
                <?php wp_nonce_field( 'cfm_settings_nonce', 'cfm_settings_nonce_field' ); ?>
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">

                            <div class="postbox">
                                <h2 class="hndle"><span>پیکربندی ماژول‌های اصلی</span></h2>
                                <div class="inside">
                                    <label class="cfm-toggle">
                                        <input type="checkbox" name="enable_isqm" value="1" <?php checked( $enable_isqm, 1 ); ?> />
                                        <div class="cfm-toggle-switch"></div>
                                        فعالسازی مدیریت اندازه، فرمت و کیفیت تصاویر
                                    </label>

                                    <label class="cfm-toggle">
                                        <input type="checkbox" name="enable_fancybox" value="1" <?php checked( $enable_fancy, 1 ); ?> />
                                        <div class="cfm-toggle-switch"></div>
                                        فعالسازی گالری پیشرفته Fancybox
                                    </label>

                                    <hr class="cfm-divider" />

                                    <div>
                                        <label class="cfm-toggle">
                                            <input type="checkbox" name="enable_fallback" value="1" <?php checked( $enable_fallback, 1 ); ?> />
                                            <div class="cfm-toggle-switch"></div>
                                            جایگزینی خودکار سایز اصلی (Fallback)
                                        </label>
                                        <p class="cfm-help-note">در صورتی که سایز درخواستی یک تصویر در هاست موجود نباشد، این سیستم به طور خودکار تصویر اصلی را جایگزین می‌کند تا از شکسته شدن تصاویر سایت جلوگیری شود.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="postbox" id="cfm-isqm-settings" style="<?php echo $enable_isqm ? 'display:block;' : 'display:none; opacity:0;'; ?>">
                                <h2 class="hndle"><span>تنظیمات پیشرفته تصاویر و ویرایشگر</span></h2>
                                <div class="inside">
                                    <h4>تبدیل فرمت تصاویر (کاهش حجم تا ۶۰٪):</h4>
                                    <div class="cfm-format-group">
                                        <select name="cfm_convert_format" id="cfm_convert_format" class="cfm-select-format">
                                            <option value="none" <?php selected( $convert_format, 'none' ); ?>>بدون تغییر (نگه‌داشتن فرمت آپلودی)</option>

                                            <?php if ( $supports_webp ) : ?>
                                                <option value="webp" <?php selected( $convert_format, 'webp' ); ?>>✅ تبدیل به WebP (توصیه شده - سریع و بهینه)</option>
                                            <?php else : ?>
                                                <option value="webp" disabled>❌ تبدیل به WebP (سرور شما از این فرمت پشتیبانی نمی‌کند)</option>
                                            <?php endif; ?>

                                            <?php if ( $supports_avif ) : ?>
                                                <option value="avif" <?php selected( $convert_format, 'avif' ); ?>>✅ تبدیل به AVIF (نهایت فشردگی کیفیت)</option>
                                            <?php else : ?>
                                                <option value="avif" disabled>❌ تبدیل به AVIF (سرور شما از این فرمت پشتیبانی نمی‌کند)</option>
                                            <?php endif; ?>
                                        </select>

                                        <div class="cfm-quality-input-wrapper" id="cfm-quality-wrapper" style="<?php echo in_array( $convert_format, [ 'webp', 'avif' ], true ) ? 'display:flex;' : 'display:none;'; ?>">
                                            <label for="cfm_convert_quality">درصد کیفیت خروجی:</label>
                                            <input type="number" id="cfm_convert_quality" name="cfm_convert_quality" class="cfm-quality-input" min="1" max="100" step="1" value="<?php echo esc_attr( $convert_quality ); ?>" />
                                            <span>%</span>
                                        </div>
                                    </div>

                                    <p class="cfm-help-note" style="margin-bottom: 8px;">
                                        تصاویر JPEG و PNG جدید در لحظه آپلود، همراه با تمامی سایزها به صورت خودکار به فرمت انتخاب شده تبدیل می‌شوند.
                                    </p>

                                    <div class="cfm-highlight-tip">
                                        <span style="font-size: 16px;">💡</span>
                                        <div>
                                            <strong>نکته کیفیت:</strong> بهتر است برای کاهش حجم مناسب‌تر عددی بین ۷۰ الی ۱۰۰ درصد انتخاب شود (پیش‌فرض ۸۰٪ است).
                                        </div>
                                    </div>

                                    <?php if ( ! $has_imagick ) : ?>
                                        <div class="cfm-imagick-warning">
                                            <span style="font-size: 16px;">⚠️</span>
                                            <div>
                                                <strong>هشدار مهم: ماژول PHP Imagick روی سرور شما غیرفعال است!</strong><br>
                                                افزونه/اکستنشن <code>Imagick</code> یا <code>ImageMagick</code> روی هاست فعال نیست. جهت بهینه‌سازی استاندارد و تبدیل صحیح تصاویر با بالاترین کیفیت، لطفاً به پشتیبانی هاستینگ خود پیام دهید تا این ماژول را برای نسخه PHP شما فعال کنند.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <hr class="cfm-divider" />

                                    <h4>غیرفعال‌سازی برش‌های خودکار وردپرس:</h4>
                                    <p class="cfm-help-note">سایزهایی که تیک می‌زنید دیگر توسط وردپرس تولید نخواهند شد تا در فضای هاست صرفه‌جویی شود.</p>
                                    <ul class="cfm-grid-list">
                                        <?php foreach ( $image_sizes as $name => $meta ) : ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="disabled_image_sizes[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $disabled_sizes, true ) ); ?> /> 
                                                    <?php echo esc_html( $name ); ?> 
                                                    <small>(<?php echo esc_html( $meta['width'] . 'x' . $meta['height'] ); ?>)</small>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <hr class="cfm-divider" />

                                    <label style="display:block; margin-bottom:10px; cursor:pointer;">
                                        <input type="checkbox" name="cfm_jpeg_100" value="1" <?php checked( $jpeg_quality, 100 ); ?> /> تنظیم کیفیت تصاویر JPEG روی ۱۰۰٪ (بدون افت کیفیت هنگام برش)
                                    </label>
                                    <label style="display:block; margin-bottom:10px; cursor:pointer;">
                                        <input type="checkbox" name="cfm_png_100" value="1" <?php checked( $png_quality, 100 ); ?> /> تنظیم کیفیت خروجی ویرایشگر تصاویر وردپرس روی ۱۰۰٪
                                    </label>

                                    <hr class="cfm-divider" />

                                    <label class="cfm-toggle">
                                        <input type="checkbox" name="cfm_disable_gutenberg" value="1" <?php checked( $disable_guten, 1 ); ?> />
                                        <div class="cfm-toggle-switch"></div>
                                        غیرفعال کردن ویرایشگر گوتنبرگ (استفاده از کلاسیک)
                                    </label>
                                </div>
                            </div>

                            <p class="submit">
                                <input type="submit" name="cfm_settings_submit" class="button button-primary button-large" value="ذخیره تغییرات نهایی" />
                            </p>
                        </div>

                        <div id="postbox-container-1" class="postbox-container">
                            <div class="postbox">
                                <h2 class="hndle"><span>راهنمای سریع</span></h2>
                                <div class="inside">
                                    <p><strong>پشتیبانی سرور شما:</strong><br>
                                        <span style="display:inline-block; margin-top:5px; padding:3px 8px; border-radius:3px; font-size:12px; background: <?php echo $has_imagick ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">Imagick: <?php echo $has_imagick ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                        <span style="display:inline-block; margin-top:5px; padding:3px 8px; border-radius:3px; font-size:12px; background: <?php echo $supports_webp ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">WebP: <?php echo $supports_webp ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                        <span style="display:inline-block; margin-top:5px; padding:3px 8px; border-radius:3px; font-size:12px; background: <?php echo $supports_avif ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">AVIF: <?php echo $supports_avif ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                    </p>
                                    <hr class="cfm-divider">
                                    <p><strong>تبدیل فرمت نسل جدید:</strong><br><span class="cfm-help-note">WebP و AVIF جدیدترین استانداردهای وب هستند. AVIF تا ۲۰٪ فشرده‌تر از WebP است اما منابع بیشتری از سرور درگیر می‌کند.</span></p>
                                    <hr class="cfm-divider">
                                    <p><strong>Fancybox:</strong><br><span class="cfm-help-note">تمام گالری‌های وردپرس و المنتور پس از فعال‌سازی، به صورت خودکار شناسایی و در قالب اسلایدر حرفه‌ای نمایش داده می‌شوند.</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var isqmBox = document.querySelector('input[name="enable_isqm"]');
            var isqmSection = document.getElementById('cfm-isqm-settings');
            var formatSelect = document.getElementById('cfm_convert_format');
            var qualityWrapper = document.getElementById('cfm-quality-wrapper');

            if(isqmBox && isqmSection) {
                isqmBox.addEventListener('change', function() {
                    if(this.checked) {
                        isqmSection.style.display = 'block';
                        setTimeout(function() { isqmSection.style.opacity = '1'; }, 10);
                    } else {
                        isqmSection.style.opacity = '0';
                        setTimeout(function() { isqmSection.style.display = 'none'; }, 300);
                    }
                });
                isqmSection.style.opacity = isqmBox.checked ? '1' : '0';
            }

            if(formatSelect && qualityWrapper) {
                formatSelect.addEventListener('change', function() {
                    if(this.value === 'webp' || this.value === 'avif') {
                        qualityWrapper.style.display = 'flex';
                    } else {
                        qualityWrapper.style.display = 'none';
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * استخراج ابعاد تصاویر و ذخیره در Transient
     */
    public function get_all_image_sizes(): array {
        $cached_sizes = get_transient( 'cfm_all_image_sizes' );
        if ( false !== $cached_sizes ) {
            return $cached_sizes;
        }

        global $_wp_additional_image_sizes;
        $sizes = [];
        foreach ( get_intermediate_image_sizes() as $s ) {
            $sizes[$s]['width']  = (int) get_option( "{$s}_size_w" );
            $sizes[$s]['height'] = (int) get_option( "{$s}_size_h" );
        }
        if ( isset( $_wp_additional_image_sizes ) && is_array( $_wp_additional_image_sizes ) ) {
            foreach ( $_wp_additional_image_sizes as $s => $meta ) {
                $sizes[$s]['width']  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
                $sizes[$s]['height'] = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
            }
        }

        set_transient( 'cfm_all_image_sizes', $sizes, DAY_IN_SECONDS );
        return $sizes;
    }

    /**
     * پاک کردن کش به صورت ایمن با پذیرش آرگومان‌های احتمالی از هوک‌ها
     */
    public function clear_image_sizes_transient( ...$args ): void {
        delete_transient( 'cfm_all_image_sizes' );
    }

    /**
     * پیدا کردن نام یکتا در دایرکتوری آپلود بدون هیچ پیشوند یا ابعاد اضافه
     * مثال: 123.avif و در صورت تکراری بودن 123-1.avif و ...
     */
    private function get_unique_converted_filepath( string $dir, string $filename_without_ext, string $target_ext ): string {
        $candidate_name = "{$filename_without_ext}.{$target_ext}";
        $candidate_path = $dir . '/' . $candidate_name;

        if ( ! file_exists( $candidate_path ) ) {
            return $candidate_path;
        }

        $counter = 1;
        while ( file_exists( $dir . '/' . "{$filename_without_ext}-{$counter}.{$target_ext}" ) ) {
            $counter++;
        }

        return $dir . '/' . "{$filename_without_ext}-{$counter}.{$target_ext}";
    }

    /**
     * تبدیل فرمت تصاویر هنگام آپلود
     */
    public function handle_image_conversion( array $upload ): array {
        if ( ! get_option( self::OPTION_ENABLE_ISQM ) ) return $upload;

        $format = get_option( self::OPTION_CONVERT_FORMAT, 'none' );
        if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) return $upload;
        if ( empty( $upload['type'] ) || ! in_array( $upload['type'], [ 'image/jpeg', 'image/png' ], true ) ) return $upload;

        wp_raise_memory_limit( 'image' );

        $editor = wp_get_image_editor( $upload['file'] );
        if ( is_wp_error( $editor ) ) return $upload;

        $target_mime = 'image/' . $format;
        if ( ! $editor->supports_mime_type( $target_mime ) ) return $upload;

        // تنظیم و تحمیل کیفیت مورد نظر
        $quality = absint( get_option( self::OPTION_CONVERT_QUALITY, 80 ) );
        $editor->set_quality( $quality );

        // اگر از Imagick استفاده می‌شود، اطمینان از اعمال فشرده‌سازی AVIF/WebP
        if ( method_exists( $editor, 'get_image' ) ) {
            try {
                $imagick = $editor->get_image();
                if ( $imagick instanceof \Imagick ) {
                    $imagick->setImageCompressionQuality( $quality );
                }
            } catch ( \Throwable $e ) {
                // اگر مشکلی بود به فشرده‌سازی پیش‌فرض ادامه بده
            }
        }

        // استخراج نام و مسیر بدون ابعاد اضافی
        $file_path_info = pathinfo( $upload['file'] );
        $dirname        = $file_path_info['dirname'];
        $clean_basename = $file_path_info['filename']; // فقط نام بدون پسوند قبلی (مثلاً 123)

        // تولید نام مقصد دقیق (123.avif یا 123-1.avif در صورت وجود)
        $new_dest_path = $this->get_unique_converted_filepath( $dirname, $clean_basename, $format );

        $saved_image = $editor->save( $new_dest_path, $target_mime );

        if ( is_wp_error( $saved_image ) ) return $upload;

        // حذف امن فایل قبلی (مثلاً jpg یا png اصلی) با استفاده از تابع وردپرس (استاندارد مخزن)
        if ( $saved_image['path'] !== $upload['file'] ) {
            wp_delete_file( $upload['file'] );
        }

        // جایگزینی اطلاعات فایل در هوک آپلود وردپرس
        $saved_basename = wp_basename( $saved_image['path'] );
        $upload['file'] = $saved_image['path'];
        $upload['url']  = dirname( $upload['url'] ) . '/' . $saved_basename;
        $upload['type'] = $target_mime;

        return $upload;
    }

    /**
     * فیلتر سایزهای غیرفعال‌شده
     */
    public function filter_disabled_image_sizes( array $sizes ): array {
        if ( ! get_option( self::OPTION_ENABLE_ISQM ) ) return $sizes;
        $disabled = get_option( self::OPTION_DISABLED_SIZES, [] );
        if ( is_array( $disabled ) && ! empty( $disabled ) ) {
            foreach ( $disabled as $s ) {
                unset( $sizes[$s] );
            }
        }
        return $sizes;
    }

    public function filter_jpeg_quality( int $quality ): int { 
        return get_option( self::OPTION_ENABLE_ISQM ) ? absint( get_option( self::OPTION_ISQM_JPEG, $quality ) ) : $quality; 
    }

    /**
     * اعمال کیفیت برای تمام فشرده‌سازی‌های ویرایشگر وردپرس (PNG / WebP / AVIF)
     */
    public function filter_global_editor_quality( int $quality, string $mime_type = '' ): int {
        if ( ! get_option( self::OPTION_ENABLE_ISQM ) ) {
            return $quality;
        }

        $format = get_option( self::OPTION_CONVERT_FORMAT, 'none' );

        if ( in_array( $mime_type, [ 'image/webp', 'image/avif' ], true ) || in_array( $format, [ 'webp', 'avif' ], true ) ) {
            return absint( get_option( self::OPTION_CONVERT_QUALITY, 80 ) );
        }

        if ( 'image/png' === $mime_type ) {
            return absint( get_option( self::OPTION_ISQM_PNG, $quality ) );
        }

        return $quality;
    }

    public function toggle_block_editor( bool $use ): bool {
        return ( get_option( self::OPTION_ENABLE_ISQM ) && get_option( self::OPTION_DISABLE_GUTEN ) ) ? false : $use;
    }

    public function handle_image_fallback( $return, $attachment_id, $size ) {
        if ( is_admin() || $return || 'full' === $size || ! is_string( $size ) ) return $return;
        if ( ! get_option( self::OPTION_ENABLE_FALLBACK ) ) return $return;

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! isset( $meta['sizes'][$size] ) ) {
            $img_url = wp_get_attachment_url( $attachment_id );
            if ( $img_url ) {
                $width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
                $height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
                return [ $img_url, $width, $height, false ];
            }
        }
        return $return;
    }

    public function enqueue_frontend_assets(): void {
        if ( ! get_option( self::OPTION_ENABLE_FANCY ) ) return;

        wp_enqueue_style( 'cfm-fancybox-css', plugin_dir_url( __FILE__ ) . 'fancybox/fancybox.css', [], self::VERSION );
        wp_enqueue_script( 'cfm-fancybox-js', plugin_dir_url( __FILE__ ) . 'fancybox/fancybox.umd.js', [ 'jquery' ], self::VERSION, true );

        $inline_js = <<<'JS'
        jQuery(document).ready(function($){
            function setupFancybox() {
                var imgRegex = /\.(jpg|jpeg|png|webp|avif|gif|bmp)($|\?)/i;
                $('.gallery, .wp-block-gallery, .elementor-image-gallery').each(function(i, gallery){
                    var groupName = 'gallery-group-' + (i + 1);
                    $(gallery).find('a[href]').each(function(){
                        if (imgRegex.test(this.href)) {
                            this.setAttribute('data-fancybox', groupName);
                        }
                    });
                });
                document.querySelectorAll('a[href]:not([data-fancybox])').forEach(function(link){
                    if (imgRegex.test(link.href)) {
                        link.setAttribute('data-fancybox', 'single-images');
                    }
                });
                if (typeof Fancybox !== 'undefined') {
                    Fancybox.bind('[data-fancybox]', {
                        infinite: true, keyboard: true, rtl: true,
                        Toolbar: { display: { left: ['infobar'], middle: [], right: ['zoom', 'slideshow', 'fullscreen', 'download', 'thumbs', 'close'] } },
                        Thumbs: { autoStart: true, type: 'classic' },
                        Html: { videoAutoplay: true },
                        l10n: { CLOSE: 'بستن', NEXT: 'بعدی', PREV: 'قبلی', MODAL: 'این پنجره را می‌توان با کلید ESC بست', ERROR: 'خطایی رخ داده است', IMAGE_ERROR: 'تصویر یافت نشد', DOWNLOAD: 'دانلود', FULLSCREEN: 'تمام صفحه', THUMBS: 'بندانگشتی‌ها', ZOOM: 'بزرگنمایی' }
                    });
                }
            }
            setupFancybox();
        });
        JS;

        wp_add_inline_script( 'cfm-fancybox-js', $inline_js );
    }
}

// راه‌اندازی و اجرای افزونه
Plugin::instance();