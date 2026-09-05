<?php
/*
Plugin Name: OptiPulse: Media & Database Optimizer
Plugin URI: https://demo.aminarjmand.com/optipulse-media-database-optimizer/
Description: جعبه‌ابزار کامل بهینه‌سازی تصاویر و دیتابیس وردپرس: تبدیل خودکار به WebP/AVIF، کنترل سایز و کیفیت، گالری لایتباکس (Fancybox) و پاک‌سازی جداول دیتابیس.
Version: 4.5
Author: امین ارجمند
Author URI: https://mohandeseit.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: optipulse-media-database-optimizer
Requires at least: 5.0
Requires PHP: 7.4
*/

namespace OptiPulseMediaDatabaseOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    public const VERSION = '4.5';

    private static ?Plugin $instance = null;

    public const OPTION_ENABLE_ISQM      = 'cfm_enable_isqm';
    public const OPTION_ENABLE_FANCY     = 'cfm_enable_fancybox';
    public const OPTION_ENABLE_FALLBACK  = 'cfm_enable_fallback_full';
    public const OPTION_ISQM_JPEG        = 'cfm_isqm_jpeg_quality';
    public const OPTION_ISQM_PNG         = 'cfm_isqm_png_quality';
    public const OPTION_DISABLED_SIZES   = 'cfm_disabled_image_sizes';
    public const OPTION_DISABLE_GUTEN    = 'cfm_disable_gutenberg';
    public const OPTION_CONVERT_FORMAT   = 'cfm_convert_format';
    public const OPTION_CONVERT_QUALITY  = 'cfm_convert_quality';
    public const OPTION_AS_RETENTION     = 'cfm_as_retention_days';

    private static array $fallback_memory_cache = [];

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_action( 'after_switch_theme', [ $this, 'clear_image_sizes_transient' ] );
        add_action( 'activated_plugin', [ $this, 'clear_image_sizes_transient' ], 10, 2 );
        add_action( 'deactivated_plugin', [ $this, 'clear_image_sizes_transient' ], 10, 2 );

        // هندلرهای AJAX
        add_action( 'wp_ajax_cfm_save_media_settings', [ $this, 'ajax_save_media_settings' ] );
        add_action( 'wp_ajax_cfm_save_as_retention', [ $this, 'ajax_save_as_retention' ] );
        add_action( 'wp_ajax_cfm_execute_db_action', [ $this, 'ajax_execute_db_action' ] );

        // فیلترهای بهینه‌سازی تصویر
        add_filter( 'wp_handle_upload', [ $this, 'handle_image_conversion' ] );
        add_filter( 'intermediate_image_sizes_advanced', [ $this, 'filter_disabled_image_sizes' ] );
        add_filter( 'jpeg_quality', [ $this, 'filter_jpeg_quality' ] );
        add_filter( 'wp_editor_set_quality', [ $this, 'filter_global_editor_quality' ], 10, 2 );

        // مدیریت ویرایشگر گوتنبرگ
        add_filter( 'use_block_editor_for_post', [ $this, 'toggle_block_editor' ], 10 );
        add_filter( 'use_widgets_block_editor', [ $this, 'toggle_block_editor' ] );

        // جایگزینی تصویر در صورت فقدان سایز
        add_filter( 'image_downsize', [ $this, 'handle_image_fallback' ], 10, 3 );

        // استایل و اسکریپت سمت کاربر
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        $plugin_file = plugin_basename( __FILE__ );
        add_filter( "plugin_action_links_{$plugin_file}", [ $this, 'add_action_links' ] );
        add_filter( 'plugin_row_meta', [ $this, 'custom_plugin_row_meta' ], 10, 2 );

        // کنترل دوره نگهداری Action Scheduler با فیلتر بومی
        add_filter( 'action_scheduler_retention_period', function( $period ) {
            $days = absint( get_option( self::OPTION_AS_RETENTION, 30 ) );
            return ( $days > 0 ) ? ( $days * DAY_IN_SECONDS ) : $period;
        } );
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'OptiPulse: Media & Database Optimizer', 'optipulse-media-database-optimizer' ),
            __( 'بهینه‌ساز هوشمند', 'optipulse-media-database-optimizer' ),
            'manage_options',
            'cfm_settings_page',
            [ $this, 'render_settings_page' ],
            'dashicons-performance',
            62
        );
    }

    public function add_action_links( array $links ): array {
        $settings_url  = admin_url( 'admin.php?page=cfm_settings_page' );
        $settings_link = sprintf(
            '<a href="%s" class="cfm-action-btn-settings">%s</a>',
            esc_url( $settings_url ),
            esc_html__( 'تنظیمات', 'optipulse-media-database-optimizer' )
        );

        array_unshift( $links, $settings_link );
        return $links;
    }

    public function custom_plugin_row_meta( array $plugin_meta, string $plugin_file ): array {
        if ( plugin_basename( __FILE__ ) === $plugin_file ) {
            if ( isset( $plugin_meta[0] ) ) {
                $plugin_meta[0] = '<span class="cfm-version-badge">' . esc_html( $plugin_meta[0] ) . '</span>';
            }
            if ( isset( $plugin_meta[1] ) ) unset( $plugin_meta[1] );
            if ( isset( $plugin_meta[2] ) ) unset( $plugin_meta[2] );

            $plugin_meta = array_values( $plugin_meta );

            $plugin_meta[] = '<a href="https://aminarjmand.com" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-blue">بدست امین ارجمند</a>';
            $plugin_meta[] = '<a href="https://demo.aminarjmand.com/Smart-Media-Database-Optimizer/" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-green">دیدن خانهٔ افزونه</a>';
            $plugin_meta[] = '<a href="https://mohandeseit.com" target="_blank" rel="noopener noreferrer" class="cfm-meta-btn cfm-btn-red">سایت سازنده</a>';
        }
        return $plugin_meta;
    }

    public function enqueue_admin_assets( string $hook_suffix ): void {
        if ( 'plugins.php' === $hook_suffix ) {
            wp_register_style( 'cfm-plugins-inline-css', false, [], self::VERSION );
            wp_enqueue_style( 'cfm-plugins-inline-css' );
            $plugins_css = "
                .cfm-action-btn-settings { display:inline-block !important; background:linear-gradient(135deg, #2563eb, #1d4ed8) !important; color:#ffffff !important; font-weight:bold !important; padding:2px 10px !important; border-radius:4px !important; text-decoration:none !important; font-size:11px !important; line-height:1.6 !important; box-shadow:0 1px 3px rgba(37,99,235,0.3) !important; }
                .cfm-action-btn-settings:hover { background:linear-gradient(135deg, #1d4ed8, #1e40af) !important; color:#ffffff !important; transform:translateY(-1px) !important; }
                .cfm-version-badge { display:inline-block; background-color:#6366f1 !important; color:#ffffff !important; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:11px; box-shadow:0 2px 4px rgba(99,102,241,0.2); margin-left:5px; }
                .cfm-meta-btn { display:inline-block !important; padding:3px 10px !important; border-radius:4px !important; color:#ffffff !important; font-weight:600 !important; text-decoration:none !important; font-size:11px !important; line-height:1.5 !important; box-shadow:0 1px 3px rgba(0,0,0,0.12) !important; margin-top:4px; }
                .cfm-meta-btn:hover { opacity:0.9 !important; color:#ffffff !important; transform:translateY(-1px) !important; }
                .cfm-btn-blue { background-color:#2563eb !important; }
                .cfm-btn-green { background-color:#059669 !important; }
                .cfm-btn-red { background-color:#dc2626 !important; }
            ";
            wp_add_inline_style( 'cfm-plugins-inline-css', $plugins_css );
        }

        if ( 'toplevel_page_cfm_settings_page' !== $hook_suffix ) {
            return;
        }

        wp_register_style( 'cfm-admin-settings-css', false, [], self::VERSION );
        wp_enqueue_style( 'cfm-admin-settings-css' );

        $admin_css = "
            .cfm-wrap { max-width: 1400px; margin-top: 15px; }
            .cfm-wrap .postbox { border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 24px; }
            .cfm-wrap .hndle { border-bottom: 1px solid #f1f5f9; background: #f8fafc; padding: 14px 18px; }
            .cfm-wrap h2.hndle span { font-weight: 700; color: #0f172a; font-size: 15px; display: flex; align-items: center; gap: 8px; }
            .cfm-wrap .inside { padding: 24px; }
            .cfm-toggle-block { margin-bottom: 18px; }
            .cfm-toggle { display: inline-flex; align-items: center; cursor: pointer; gap: 12px; font-weight: 600; user-select: none; }
            .cfm-toggle input { display: none; }
            .cfm-toggle-switch { position: relative; width: 44px; height: 24px; background: #cbd5e1; border-radius: 24px; transition: background 0.25s ease; flex-shrink: 0; }
            .cfm-toggle-switch::after { content: ''; position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; transition: transform 0.25s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
            .cfm-toggle input:checked ~ .cfm-toggle-switch { background: #2563eb; }
            .cfm-toggle input:checked ~ .cfm-toggle-switch::after { transform: translateX(-20px); }
            .cfm-grid-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; background: #f8fafc; padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 10px; list-style: none; }
            .cfm-grid-list li { margin: 0; display: flex; align-items: center; }
            .cfm-grid-list label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
            .cfm-grid-list small { color: #64748b; direction: ltr; display: inline-block; }
            .cfm-help-note { color: #64748b; font-size: 13px; margin-top: 5px; margin-bottom: 12px; line-height: 1.7; }
            .cfm-divider { border: 0; height: 1px; background: #f1f5f9; margin: 22px 0; }
            #cfm-isqm-settings { transition: opacity 0.3s ease; }
            .cfm-select-format { width: 100%; max-width: 480px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; }
            .cfm-format-group { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-bottom: 12px; }
            .cfm-quality-input-wrapper { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 13px; }
            .cfm-quality-input { width: 80px !important; text-align: center; font-weight: 700; border-radius: 6px !important; padding: 6px !important; border: 1px solid #cbd5e1 !important; }
            .cfm-highlight-tip { display: flex; align-items: center; gap: 10px; background: #fefce8; border: 1px solid #fef08a; border-right: 4px solid #eab308; border-radius: 8px; padding: 12px 16px; margin: 14px 0; color: #854d0e; font-size: 13px; line-height: 1.6; }
            .cfm-imagick-warning { display: flex; align-items: flex-start; gap: 10px; background: #fff1f2; border: 1px solid #fecdd3; border-right: 4px solid #e11d48; border-radius: 8px; padding: 12px 16px; margin: 14px 0; color: #9f1239; font-size: 13px; line-height: 1.6; }
            .cfm-db-alert { background: #fef2f2; border: 1px solid #fecaca; border-right: 5px solid #dc2626; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; color: #7f1d1d; }
            .cfm-db-alert h3 { margin: 0 0 6px 0; color: #991b1b; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
            .cfm-db-alert p { margin: 0; font-size: 13px; line-height: 1.8; color: #991b1b; }
            .cfm-db-card-item { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 14px; transition: border-color 0.2s ease; }
            .cfm-db-card-item:hover { border-color: #cbd5e1; }
            .cfm-db-card-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; }
            .cfm-db-card-desc { flex: 1; }
            .cfm-db-card-desc h4 { margin: 0 0 6px 0; font-size: 14px; color: #0f172a; font-weight: 600; }
            .cfm-db-card-desc p { margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.6; }
            .cfm-db-card-action { flex-shrink: 0; }
            .cfm-db-card-action .button { padding: 4px 14px !important; border-radius: 6px !important; font-size: 12.5px !important; height: 36px !important; line-height: 26px !important; }
            .cfm-table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 12px; border-radius: 8px; border: 1px solid #e2e8f0; }
            .cfm-table-stats { width: 100%; border-collapse: collapse; font-size: 12px; background: #ffffff; }
            .cfm-table-stats th, .cfm-table-stats td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; text-align: right; }
            .cfm-table-stats th { background: #f8fafc; color: #475569; font-weight: 600; }
            .cfm-table-stats tr:last-child td { border-bottom: none; }
            .cfm-badge-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
            .cfm-badge-good { background: #dcfce7; color: #166534; }
            .cfm-badge-danger { background: #fee2e2; color: #991b1b; }
            .cfm-card-response { margin-top: 14px; padding: 10px 14px; border-radius: 6px; font-size: 12px; display: none; line-height: 1.6; }
            .cfm-card-response.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
            .cfm-card-response.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
            .cfm-card-response.loading { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
            #cfm-media-ajax-notice { margin-top: 15px; display: none; border-radius: 6px; }
            .cfm-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); z-index: 999999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.2s ease; }
            .cfm-modal-overlay.active { opacity: 1; visibility: visible; }
            .cfm-modal-box { background: #ffffff; border-radius: 12px; width: 90%; max-width: 440px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); transform: scale(0.95); transition: transform 0.2s ease; direction: rtl; }
            .cfm-modal-overlay.active .cfm-modal-box { transform: scale(1); }
            .cfm-modal-title { margin: 0 0 10px 0; font-size: 16px; color: #0f172a; font-weight: 700; display: flex; align-items: center; gap: 8px; }
            .cfm-modal-text { font-size: 13px; color: #475569; line-height: 1.7; margin-bottom: 20px; }
            .cfm-modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
            @media screen and (max-width: 782px) {
                .cfm-wrap { padding-left: 10px; padding-right: 10px; }
                .cfm-db-card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
                .cfm-db-card-action { width: 100%; }
                .cfm-db-card-action .button { width: 100%; text-align: center; }
                .cfm-format-group { flex-direction: column; align-items: stretch; }
                .cfm-select-format { max-width: 100%; }
                .cfm-quality-input-wrapper { margin-top: 6px; }
                .cfm-wrap .inside { padding: 16px; }
            }
        ";
        wp_add_inline_style( 'cfm-admin-settings-css', $admin_css );

        wp_register_script( 'cfm-admin-settings-js', false, [], self::VERSION, true );
        wp_enqueue_script( 'cfm-admin-settings-js' );

        $admin_js = <<<'JS'
        document.addEventListener('DOMContentLoaded', function() {
            var isqmBox = document.querySelector('input[name="enable_isqm"]');
            var isqmSection = document.getElementById('cfm-isqm-settings');
            var formatSelect = document.getElementById('cfm_convert_format');
            var qualityWrapper = document.getElementById('cfm-quality-wrapper');

            if (isqmBox && isqmSection) {
                isqmBox.addEventListener('change', function() {
                    if (this.checked) {
                        isqmSection.style.display = 'block';
                        setTimeout(function() { isqmSection.style.opacity = '1'; }, 10);
                    } else {
                        isqmSection.style.opacity = '0';
                        setTimeout(function() { isqmSection.style.display = 'none'; }, 300);
                    }
                });
                isqmSection.style.opacity = isqmBox.checked ? '1' : '0';
            }

            if (formatSelect && qualityWrapper) {
                formatSelect.addEventListener('change', function() {
                    if (this.value === 'webp' || this.value === 'avif') {
                        qualityWrapper.style.display = 'flex';
                    } else {
                        qualityWrapper.style.display = 'none';
                    }
                });
            }

            var mediaForm = document.getElementById('cfm-media-settings-form');
            var mediaSaveBtn = document.getElementById('cfm-save-media-btn');
            var mediaNotice = document.getElementById('cfm-media-ajax-notice');

            if (mediaForm) {
                mediaForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    mediaSaveBtn.disabled = true;
                    mediaSaveBtn.innerText = 'در حال ذخیره...';
                    mediaNotice.style.display = 'none';

                    var formData = new FormData(mediaForm);
                    formData.append('action', 'cfm_save_media_settings');

                    fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        mediaSaveBtn.disabled = false;
                        mediaSaveBtn.innerText = 'ذخیره تنظیمات';
                        mediaNotice.style.display = 'block';
                        if (data.success) {
                            mediaNotice.className = 'notice notice-success is-dismissible';
                            mediaNotice.innerHTML = '<p>' + data.data.message + '</p>';
                            setTimeout(function() {
                                mediaNotice.style.transition = 'opacity 0.5s ease';
                                mediaNotice.style.opacity = '0';
                                setTimeout(function() { mediaNotice.style.display = 'none'; mediaNotice.style.opacity = '1'; }, 500);
                            }, 4000);
                        } else {
                            mediaNotice.className = 'notice notice-error is-dismissible';
                            mediaNotice.innerHTML = '<p>' + data.data.message + '</p>';
                        }
                    })
                    .catch(function() {
                        mediaSaveBtn.disabled = false;
                        mediaSaveBtn.innerText = 'ذخیره تنظیمات';
                        mediaNotice.style.display = 'block';
                        mediaNotice.className = 'notice notice-error is-dismissible';
                        mediaNotice.innerHTML = '<p>خطا در برقراری ارتباط با سرور.</p>';
                    });
                });
            }

            var asSaveBtn = document.getElementById('cfm-save-as-btn');
            var asInput = document.getElementById('cfm_as_retention_input');
            var asResponse = document.getElementById('cfm-as-card-response');
            var asNonce = window.cfmAdminVars ? window.cfmAdminVars.asNonce : '';

            if (asSaveBtn && asInput && asResponse) {
                asSaveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    asSaveBtn.disabled = true;
                    asResponse.style.display = 'block';
                    asResponse.className = 'cfm-card-response loading';
                    asResponse.innerText = 'در حال ذخیره دوره نگهداری...';

                    var params = new URLSearchParams();
                    params.append('action', 'cfm_save_as_retention');
                    params.append('days', asInput.value);
                    params.append('security', asNonce);

                    fetch(ajaxurl, { method: 'POST', body: params })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        asSaveBtn.disabled = false;
                        if (data.success) {
                            asResponse.className = 'cfm-card-response success';
                            asResponse.innerText = '✅ ' + data.data.message;
                            setTimeout(function() { asResponse.style.display = 'none'; }, 3500);
                        } else {
                            asResponse.className = 'cfm-card-response error';
                            asResponse.innerText = '❌ ' + data.data.message;
                        }
                    })
                    .catch(function() {
                        asSaveBtn.disabled = false;
                        asResponse.className = 'cfm-card-response error';
                        asResponse.innerText = '❌ خطا در ارسال درخواست به سرور.';
                    });
                });
            }

            var modal = document.getElementById('cfm-confirm-modal');
            var modalDesc = document.getElementById('cfm-modal-desc');
            var modalConfirm = document.getElementById('cfm-modal-confirm');
            var modalCancel = document.getElementById('cfm-modal-cancel');
            var pendingCallback = null;

            function openConfirmModal(text, callback) {
                modalDesc.innerText = text;
                pendingCallback = callback;
                modal.classList.add('active');
            }

            function closeModal() {
                modal.classList.remove('active');
                pendingCallback = null;
            }

            if (modalCancel && modalConfirm) {
                modalCancel.addEventListener('click', closeModal);
                modalConfirm.addEventListener('click', function() {
                    if (typeof pendingCallback === 'function') {
                        pendingCallback();
                    }
                    closeModal();
                });
            }

            var dbButtons = document.querySelectorAll('.cfm-ajax-db-btn');
            var dbNonce = window.cfmAdminVars ? window.cfmAdminVars.dbNonce : '';

            dbButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var confirmMsg = btn.getAttribute('data-confirm');
                    var action = btn.getAttribute('data-action');
                    var card = btn.closest('.cfm-db-card-item');
                    var responseBox = card.querySelector('.cfm-card-response');

                    function runAction() {
                        btn.disabled = true;
                        responseBox.style.display = 'block';
                        responseBox.className = 'cfm-card-response loading';
                        responseBox.innerText = '⏳ در حال اجرای عملیات پاک‌سازی دیتابیس...';

                        var params = new URLSearchParams();
                        params.append('action', 'cfm_execute_db_action');
                        params.append('db_action', action);
                        params.append('security', dbNonce);

                        fetch(ajaxurl, { method: 'POST', body: params })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            btn.disabled = false;
                            if (data.success) {
                                responseBox.className = 'cfm-card-response success';
                                responseBox.innerText = '✅ ' + data.data.message;
                            } else {
                                responseBox.className = 'cfm-card-response error';
                                responseBox.innerText = '❌ ' + data.data.message;
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            responseBox.className = 'cfm-card-response error';
                            responseBox.innerText = '❌ خطا در ارسال درخواست به سرور.';
                        });
                    }

                    if (confirmMsg) {
                        openConfirmModal(confirmMsg, runAction);
                    } else {
                        runAction();
                    }
                });
            });
        });
        JS;

        wp_add_inline_script( 'cfm-admin-settings-js', $admin_js );

        $inline_vars = sprintf(
            'window.cfmAdminVars = { dbNonce: %s, asNonce: %s };',
            wp_json_encode( wp_create_nonce( 'cfm_db_optimization_nonce' ) ),
            wp_json_encode( wp_create_nonce( 'cfm_as_retention_nonce' ) )
        );
        wp_add_inline_script( 'cfm-admin-settings-js', $inline_vars, 'before' );
    }

    public function ajax_save_as_retention(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی امنیتی غیرمجاز است.' ] );
        }

        check_ajax_referer( 'cfm_as_retention_nonce', 'security' );

        $retention_days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
        $retention_days = max( 1, min( 180, $retention_days ) );
        update_option( self::OPTION_AS_RETENTION, $retention_days, 'no' );

        wp_send_json_success( [ 'message' => "دوره نگهداری با موفقیت روی {$retention_days} روز تنظیم شد." ] );
    }

    public function ajax_execute_db_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی امنیتی غیرمجاز است.' ] );
        }

        check_ajax_referer( 'cfm_db_optimization_nonce', 'security' );

        wp_raise_memory_limit( 'admin' );

        global $wpdb;
        $action   = isset( $_POST['db_action'] ) ? sanitize_key( $_POST['db_action'] ) : '';
        $affected = 0;

        switch ( $action ) {
            case 'clean_revisions':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q1 = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q2 = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q3 = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
                $affected = (int) $q1 + (int) $q2 + (int) $q3;
                wp_send_json_success( [ 'message' => "رونوشت‌ها و زباله‌دان پاک‌سازی شدند. ردیف‌های حذف‌شده: {$affected}" ] );
                break;

            case 'clean_orphan_postmeta':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $affected = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL" );
                wp_send_json_success( [ 'message' => 'متادیتاهای بدون والد حذف شدند. تعداد: ' . (int) $affected ] );
                break;

            case 'clean_comments':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q1 = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam' OR comment_approved = 'trash'" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q2 = $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" );
                $affected = (int) $q1 + (int) $q2;
                wp_send_json_success( [ 'message' => "دیدگاه‌های هرزنامه و زباله‌دان پاک شدند. تعداد: {$affected}" ] );
                break;

            case 'clean_transients':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q1 = $wpdb->query( "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b WHERE a.option_name LIKE '%_transient_%' AND a.option_name NOT LIKE '%_transient_timeout_%' AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12)) AND b.option_value < UNIX_TIMESTAMP()" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q2 = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
                $affected = (int) $q1 + (int) $q2;
                wp_send_json_success( [ 'message' => "ترنزینت‌های منقضی حذف شدند. تعداد: {$affected}" ] );
                break;

            case 'clean_terms':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q1 = $wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $q2 = $wpdb->query( "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL" );
                $affected = (int) $q1 + (int) $q2;
                wp_send_json_success( [ 'message' => "ترم‌ها و دسته‌های بدون استفاده حذف شدند. تعداد: {$affected}" ] );
                break;

            case 'optimize_tables':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( "OPTIMIZE TABLE {$wpdb->posts}, {$wpdb->postmeta}, {$wpdb->comments}, {$wpdb->commentmeta}, {$wpdb->terms}, {$wpdb->term_taxonomy}, {$wpdb->term_relationships}, {$wpdb->options}" );
                wp_send_json_success( [ 'message' => '۸ جدول کلیدی وردپرس با موفقیت Defragment و بهینه‌سازی شدند.' ] );
                break;

            case 'clean_actionscheduler':
                $table_actions = $wpdb->prefix . 'actionscheduler_actions';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_actions ) ) === $table_actions ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $q1 = $wpdb->query( $wpdb->prepare( "DELETE l FROM {$wpdb->prefix}actionscheduler_logs l INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON l.action_id = a.action_id WHERE a.status IN (%s, %s, %s)", 'complete', 'failed', 'canceled' ) );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $q2 = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN (%s, %s, %s)", 'complete', 'failed', 'canceled' ) );

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query( "OPTIMIZE TABLE {$wpdb->prefix}actionscheduler_actions, {$wpdb->prefix}actionscheduler_logs" );

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_groups' ) ) === ( $wpdb->prefix . 'actionscheduler_groups' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->query( "OPTIMIZE TABLE {$wpdb->prefix}actionscheduler_groups" );
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_claims' ) ) === ( $wpdb->prefix . 'actionscheduler_claims' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->query( "OPTIMIZE TABLE {$wpdb->prefix}actionscheduler_claims" );
                    }
                    $affected = (int) $q1 + (int) $q2;
                    wp_send_json_success( [ 'message' => "لاگ‌ها و اکشن‌های تاریخ‌گذشته ووکامرس پاک شدند. تعداد: {$affected}" ] );
                } else {
                    wp_send_json_error( [ 'message' => 'جداول Action Scheduler در دیتابیس یافت نشدند.' ] );
                }
                break;

            default:
                wp_send_json_error( [ 'message' => 'دستور ارسالی نامعتبر است.' ] );
                break;
        }
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;

        $supports_webp = wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
        $supports_avif = wp_image_editor_supports( [ 'mime_type' => 'image/avif' ] );
        $has_imagick   = extension_loaded( 'imagick' ) && class_exists( '\Imagick', false );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $autoload_size_kb = (float) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) / 1024 FROM {$wpdb->options} WHERE autoload = 'yes' OR autoload = 'on'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_autoloads    = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS option_size_bytes FROM {$wpdb->options} WHERE autoload = 'yes' OR autoload = 'on' ORDER BY option_size_bytes DESC LIMIT 10" );

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
        $as_retention     = absint( get_option( self::OPTION_AS_RETENTION, 30 ) );
        ?>

        <div class="wrap cfm-wrap">
            <h1 class="wp-heading-inline">OptiPulse: بهینه‌ساز هوشمند رسانه و دیتابیس</h1>
            <hr class="wp-header-end">

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">

                        <form id="cfm-media-settings-form" method="post" action="">
                            <?php wp_nonce_field( 'cfm_settings_nonce', 'cfm_settings_nonce_field' ); ?>
                            <div class="postbox">
                                <h2 class="hndle"><span>📷 پیکربندی ماژول‌های اصلی رسانه</span></h2>
                                <div class="inside">

                                    <div class="cfm-toggle-block">
                                        <label class="cfm-toggle">
                                            <input type="checkbox" name="enable_isqm" value="1" <?php checked( $enable_isqm, 1 ); ?> />
                                            <div class="cfm-toggle-switch"></div>
                                            فعالسازی مدیریت اندازه، فرمت و کیفیت تصاویر
                                        </label>
                                        <p class="cfm-help-note">امکان تبدیل هوشمند فرمت تصاویر به WebP/AVIF، مسدود کردن برش‌های غیرضروری و تعیین کیفیت دقیق فشرده‌سازی برای کاهش حجم تصاویر را فراهم می‌کند.</p>
                                    </div>

                                    <hr class="cfm-divider" />

                                    <div class="cfm-toggle-block">
                                        <label class="cfm-toggle">
                                            <input type="checkbox" name="enable_fancybox" value="1" <?php checked( $enable_fancy, 1 ); ?> />
                                            <div class="cfm-toggle-switch"></div>
                                            فعالسازی گالری پیشرفته Fancybox
                                        </label>
                                        <p class="cfm-help-note">گالری‌های پیش‌فرض وردپرس و افزونه المنتور را به صورت خودکار شناسایی کرده و آن‌ها را در قالب یک اسلایدر لایت‌باکس مدرن، لمسی و تمام‌صفحه با قابلیت زوم و بندانگشتی نمایش می‌دهد.</p>
                                    </div>

                                    <hr class="cfm-divider" />

                                    <div>
                                        <label class="cfm-toggle">
                                            <input type="checkbox" name="enable_fallback" value="1" <?php checked( $enable_fallback, 1 ); ?> />
                                            <div class="cfm-toggle-switch"></div>
                                            جایگزینی خودکار سایز اصلی (Fallback)
                                        </label>
                                        <p class="cfm-help-note">در صورتی که سایز درخواستی یک تصویر در هاست موجود نباشد، تصویر اصلی جایگزین می‌شود تا از نمایش تصاویر شکسته (۴۰۴) جلوگیری شود.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="postbox" id="cfm-isqm-settings" style="<?php echo $enable_isqm ? 'display:block;' : 'display:none; opacity:0;'; ?>">
                                <h2 class="hndle"><span>⚙️ تنظیمات پیشرفته تصاویر و ویرایشگر</span></h2>
                                <div class="inside">
                                    <h4>تبدیل فرمت تصاویر (کاهش حجم تا ۶۰٪):</h4>
                                    <div class="cfm-format-group">
                                        <select name="cfm_convert_format" id="cfm_convert_format" class="cfm-select-format">
                                            <option value="none" <?php selected( $convert_format, 'none' ); ?>>بدون تغییر (نگه‌داشتن فرمت آپلودی)</option>

                                            <?php if ( $supports_webp ) : ?>
                                                <option value="webp" <?php selected( $convert_format, 'webp' ); ?>>✅ تبدیل به WebP (توصیه شده - سریع و سبک)</option>
                                            <?php else : ?>
                                                <option value="webp" disabled>❌ تبدیل به WebP (سرور شما پشتیبانی نمی‌کند)</option>
                                            <?php endif; ?>

                                            <?php if ( $supports_avif ) : ?>
                                                <option value="avif" <?php selected( $convert_format, 'avif' ); ?>>✅ تبدیل به AVIF (حداکثر فشردگی مدرن)</option>
                                            <?php else : ?>
                                                <option value="avif" disabled>❌ تبدیل به AVIF (سرور شما پشتیبانی نمی‌کند)</option>
                                            <?php endif; ?>
                                        </select>

                                        <div class="cfm-quality-input-wrapper" id="cfm-quality-wrapper" style="<?php echo in_array( $convert_format, [ 'webp', 'avif' ], true ) ? 'display:flex;' : 'display:none;'; ?>">
                                            <label for="cfm_convert_quality">درصد کیفیت خروجی:</label>
                                            <input type="number" id="cfm_convert_quality" name="cfm_convert_quality" class="cfm-quality-input" min="1" max="100" step="1" value="<?php echo esc_attr( $convert_quality ); ?>" />
                                            <span>%</span>
                                        </div>
                                    </div>

                                    <p class="cfm-help-note">تصاویر در هنگام آپلود همراه با تمام سایزهای خود به فرمت انتخابی تبدیل خواهند شد.</p>

                                    <div class="cfm-highlight-tip">
                                        <span class="dashicons dashicons-info" style="font-size:18px; line-height:1.2;"></span>
                                        <div><strong>نکته کیفیت:</strong> برای دستیابی به حجم بهینه و عدم افت وضوح، مقدار بین ۷۰ الی ۹۰ درصد پیشنهاد می‌شود (پیش‌فرض: ۸۰٪).</div>
                                    </div>

                                    <?php if ( ! $has_imagick ) : ?>
                                        <div class="cfm-imagick-warning">
                                            <span class="dashicons dashicons-warning" style="font-size:20px; line-height:1.3;"></span>
                                            <div>
                                                <strong>اکستنشن PHP Imagick روی هاست غیرفعال است!</strong><br>
                                                برای پردازش بدون نقص و عملکرد سریع‌تر، با پشتیبانی هاست تماس گرفته و درخواست فعال‌سازی افزونه <code>imagick</code> را ثبت کنید.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <hr class="cfm-divider" />

                                    <h4>غیرفعال‌سازی برش‌های خودکار وردپرس:</h4>
                                    <p class="cfm-help-note">سایزهایی که تیک می‌زنید توسط وردپرس تولید نخواهند شد تا در مصرف فضای هارد هاست صرفه‌جویی شود.</p>
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

                                    <label style="display:block; margin-bottom:12px; cursor:pointer;">
                                        <input type="checkbox" name="cfm_jpeg_100" value="1" <?php checked( $jpeg_quality, 100 ); ?> /> تنظیم کیفیت تصاویر JPEG روی ۱۰۰٪ (بدون افت کیفیت هنگام برش)
                                    </label>
                                    <label style="display:block; margin-bottom:12px; cursor:pointer;">
                                        <input type="checkbox" name="cfm_png_100" value="1" <?php checked( $png_quality, 100 ); ?> /> تنظیم کیفیت خروجی ویرایشگر تصاویر وردپرس روی ۱۰۰٪
                                    </label>

                                    <hr class="cfm-divider" />

                                    <label class="cfm-toggle" style="margin-bottom:0;">
                                        <input type="checkbox" name="cfm_disable_gutenberg" value="1" <?php checked( $disable_guten, 1 ); ?> />
                                        <div class="cfm-toggle-switch"></div>
                                        غیرفعال کردن ویرایشگر گوتنبرگ (استفاده دائمی از کلاسیک)
                                    </label>
                                </div>
                            </div>

                            <p class="submit" style="padding-top:0;">
                                <button type="submit" id="cfm-save-media-btn" class="button button-primary button-large" style="min-width:160px; height:40px; font-weight:600;">ذخیره تنظیمات</button>
                            </p>
                            <div id="cfm-media-ajax-notice"></div>
                        </form>

                        <div class="postbox">
                            <h2 class="hndle"><span>🗄️ پاک‌سازی و یکپارچه‌سازی دیتابیس (MySQL)</span></h2>
                            <div class="inside">

                                <div class="cfm-db-alert">
                                    <h3>⚠️ پیش‌نیاز حیاتی: لطفاً ابتدا نسخه پشتیبان تهیه کنید!</h3>
                                    <p>عملیات‌های زیر مستقیماً بر روی دیتابیس اعمال می‌شوند و رکوردهای حذف‌شده غیرقابل بازگشت هستند. پیش از اجرا، از سلامت بکاپ کامل پایگاه داده اطمینان حاصل نمایید.</p>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۱. حذف رونوشت‌ها و پیش‌نویس‌های خودکار</h4>
                                            <p>حذف رکوردهای بازبینی (revisions)، پیش‌نویس‌های خودکار رهاشده و زباله‌دان نوشته‌ها جهت تخلیه حجم جدول <code>posts</code>.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_revisions" data-confirm="تمام رونوشت‌ها، پیش‌نویس‌های خودکار و محتوای سطل زباله حذف خواهند شد. ادامه می‌دهید؟">پاک‌سازی رونوشت‌ها</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۲. پاک‌سازی متادیتاهای یتیم نوشته‌ها</h4>
                                            <p>حذف داده‌های اضافه در <code>postmeta</code> که نوشته والد آن‌ها سال‌هاست پاک شده اما ردپایشان باقی مانده است.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_orphan_postmeta" data-confirm="متادیتاهای بدون والد پست‌ها حذف شوند؟">حذف متای یتیم</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۳. پاک‌سازی نظرات هرزنامه، زباله‌دان و متادیتاها</h4>
                                            <p>حذف کامل دیدگاه‌های اسپم، نظرات موجود در سطل زباله و متادیتاهای بدون والد از جدول <code>comments</code>.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_comments" data-confirm="دیدگاه‌های اسپم و زباله‌دان به همراه متادیتاهای ناموجود حذف شوند؟">پاک‌سازی دیدگاه‌ها</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۴. پاک‌سازی داده‌های موقت منقضی‌شده (Transients)</h4>
                                            <p>حذف رکوردهای منقضی‌شده کش در جدول <code>options</code> که موعد اعتبارشان سپری شده است.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_transients">حذف ترنزینت‌ها</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۵. پاک‌سازی روابط و برچسب‌های بدون استفاده</h4>
                                            <p>حذف پیوندهای ترم‌هایی که نوشته مرتبط با آن‌ها حذف شده و همچنین دسته‌ها و برچسب‌های یتیم.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_terms" data-confirm="ترم‌ها و برچسب‌های بدون والد حذف شوند؟">پاک‌سازی ترم‌ها</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۶. یکپارچه‌سازی و نوسازی ایندکس‌ها (OPTIMIZE)</h4>
                                            <p>آزادسازی فضای خالی (Overhead/Fragmentation) در دیسک موتور InnoDB و بازسازی ایندکس‌های ۸ جدول کلیدی وردپرس.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-primary cfm-ajax-db-btn" data-action="optimize_tables">یکپارچه‌سازی جداول</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۷. پاک‌سازی لاگ‌های حجیم Action Scheduler ووکامرس</h4>
                                            <p>حذف اکشن‌ها و لاگ‌های با وضعیت Complete، Failed و Canceled در جداول ووکامرس برای بازگشت شتاب سیستم.</p>
                                        </div>
                                        <div class="cfm-db-card-action">
                                            <button type="button" class="button button-secondary cfm-ajax-db-btn" data-action="clean_actionscheduler" data-confirm="تمام لاگ‌ها و اکشن‌های تاریخ‌گذشته Action Scheduler پاک شوند؟">پاک‌سازی لاگ‌ها</button>
                                        </div>
                                    </div>
                                    <div class="cfm-card-response"></div>
                                </div>

                                <div class="cfm-db-card-item" style="background:#f8fafc;">
                                    <div class="cfm-db-card-header">
                                        <div class="cfm-db-card-desc">
                                            <h4>۸. دوره نگهداری خودکار لاگ‌های Action Scheduler (ووکامرس)</h4>
                                            <p>
                                                به‌صورت پیش‌فرض وردپرس و ووکامرس این لاگ‌ها را تا ۳۰ روز نگه می‌دارند. در صورت داشتن سایت شلوغ یا پربازدید، توصیه می‌شود این مقدار به عددی بین <strong>۲ الی ۵ روز</strong> کاهش یابد تا از سنگین شدن دیتابیس جلوگیری شود.
                                            </p>
                                        </div>
                                        <div class="cfm-db-card-action" style="display:flex; align-items:center; gap:8px;">
                                            <input type="number" id="cfm_as_retention_input" name="cfm_as_retention_days" min="1" max="180" value="<?php echo esc_attr( $as_retention ); ?>" style="width:70px; text-align:center; height:36px; border-radius:6px;" />
                                            <span style="font-size:12px; font-weight:600;">روز</span>
                                            <button type="button" id="cfm-save-as-btn" class="button button-secondary" style="height:36px;">ذخیره دوره</button>
                                        </div>
                                    </div>
                                    <div id="cfm-as-card-response" class="cfm-card-response"></div>
                                </div>

                                <hr class="cfm-divider" />

                                <h4>تحلیل زنده Autoload در جدول options:</h4>
                                <p class="cfm-help-note">
                                    مجموع حافظه اشغالی در هر لود صفحه: 
                                    <strong><?php echo esc_html( number_format( $autoload_size_kb, 2 ) ); ?> KB</strong>
                                    <?php if ( $autoload_size_kb > 800 ) : ?>
                                        <span class="cfm-badge-status cfm-badge-danger">بالا (نیازمند رسیدگی)</span>
                                    <?php else : ?>
                                        <span class="cfm-badge-status cfm-badge-good">استاندارد و بهینه</span>
                                    <?php endif; ?>
                                </p>

                                <div class="cfm-table-responsive">
                                    <table class="cfm-table-stats">
                                        <thead>
                                            <tr>
                                                <th>نام Option</th>
                                                <th>حجم (بایت)</th>
                                                <th>حجم تقریبی (KB)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ( ! empty( $top_autoloads ) ) : ?>
                                                <?php foreach ( $top_autoloads as $row ) : ?>
                                                    <tr>
                                                        <td><code><?php echo esc_html( $row->option_name ); ?></code></td>
                                                        <td><?php echo esc_html( number_format( (float) $row->option_size_bytes ) ); ?></td>
                                                        <td><?php echo esc_html( number_format( (float) $row->option_size_bytes / 1024, 2 ) ); ?> KB</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <tr>
                                                    <td colspan="3" style="text-align:center; color:#94a3b8;">رکوردی یافت نشد.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>

                    </div>

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span>💡 راهنمای سرور و ابزارها</span></h2>
                            <div class="inside">
                                <p><strong>وضعیت کتابخانه‌های هاست:</strong></p>
                                <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px;">
                                    <span style="padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; background: <?php echo $has_imagick ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">Imagick: <?php echo $has_imagick ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                    <span style="padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; background: <?php echo $supports_webp ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">WebP: <?php echo $supports_webp ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                    <span style="padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; background: <?php echo $supports_avif ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">AVIF: <?php echo $supports_avif ? 'فعال ✅' : 'غیرفعال ❌'; ?></span>
                                </div>
                                <hr class="cfm-divider">
                                <p><strong>دوره نگهداری Action Scheduler:</strong><br><span class="cfm-help-note">پیش‌فرض هسته ۳۰ روز است. با تنظیم فیلد درون بخش دیتابیس می‌توانید آن را به عدد دلخواه تغییر دهید.</span></p>
                                <hr class="cfm-divider">
                                <p><strong>گالری Fancybox:</strong><br><span class="cfm-help-note">گالری‌های پیش‌فرض وردپرس و افزونه المنتور بدون نیاز به کدنویسی به اسلایدر تمام‌صفحه و واکنش‌گرا مجهز می‌شوند.</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="cfm-confirm-modal" class="cfm-modal-overlay">
            <div class="cfm-modal-box">
                <h4 class="cfm-modal-title">⚠️ تأیید عملیات پاک‌سازی</h4>
                <p id="cfm-modal-desc" class="cfm-modal-text"></p>
                <div class="cfm-modal-actions">
                    <button type="button" id="cfm-modal-cancel" class="button button-secondary" style="height:34px;">انصراف</button>
                    <button type="button" id="cfm-modal-confirm" class="button button-primary" style="height:34px; background:#dc2626; border-color:#b91c1c;">بله، حذف شود</button>
                </div>
            </div>
        </div>
        <?php
    }

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

    public function clear_image_sizes_transient( ...$args ): void {
        delete_transient( 'cfm_all_image_sizes' );
    }

    private function get_unique_converted_filepath( string $dir, string $filename_without_ext, string $target_ext ): string {
        $candidate_name = "{$filename_without_ext}.{$target_ext}";
        $candidate_path = $dir . '/' . $candidate_name;

        clearstatcache( true, $candidate_path );

        if ( ! file_exists( $candidate_path ) ) {
            return $candidate_path;
        }

        $counter = 1;
        while ( $counter <= 50 ) {
            $test_path = $dir . '/' . "{$filename_without_ext}-{$counter}.{$target_ext}";
            clearstatcache( true, $test_path );
            if ( ! file_exists( $test_path ) ) {
                return $test_path;
            }
            $counter++;
        }

        return $dir . '/' . "{$filename_without_ext}-" . uniqid() . ".{$target_ext}";
    }

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

        $quality = absint( get_option( self::OPTION_CONVERT_QUALITY, 80 ) );
        $editor->set_quality( $quality );

        if ( method_exists( $editor, 'get_image' ) ) {
            try {
                $imagick = $editor->get_image();
                if ( $imagick instanceof \Imagick ) {
                    $imagick->setImageCompressionQuality( $quality );
                }
            } catch ( \Throwable $e ) {
                // ادامه بدون توقف
            }
        }

        $file_path_info = pathinfo( $upload['file'] );
        $dirname        = $file_path_info['dirname'];
        $clean_basename = $file_path_info['filename'];

        $new_dest_path = $this->get_unique_converted_filepath( $dirname, $clean_basename, $format );

        $saved_image = $editor->save( $new_dest_path, $target_mime );

        if ( is_wp_error( $saved_image ) ) return $upload;

        if ( $saved_image['path'] !== $upload['file'] ) {
            wp_delete_file( $upload['file'] );
        }

        $saved_basename = wp_basename( $saved_image['path'] );
        $upload['file'] = $saved_image['path'];
        $upload['url']  = dirname( $upload['url'] ) . '/' . $saved_basename;
        $upload['type'] = $target_mime;

        return $upload;
    }

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

        $attachment_id = (int) $attachment_id;

        if ( ! isset( self::$fallback_memory_cache[ $attachment_id ] ) ) {
            self::$fallback_memory_cache[ $attachment_id ] = [
                'meta' => wp_get_attachment_metadata( $attachment_id ),
                'url'  => wp_get_attachment_url( $attachment_id ),
            ];
        }

        $cached_data = self::$fallback_memory_cache[ $attachment_id ];
        $meta        = $cached_data['meta'];

        if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! isset( $meta['sizes'][ $size ] ) ) {
            $img_url = $cached_data['url'];
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

Plugin::instance();