<?php
/**
 * Plugin Name: SimAbove Checkout Email OTP Verification
 * Description: OTP email verification on WooCommerce checkout with admin panel, logs, analytics, fake-email checks, country/IP filtering, and checkout-triggered OTP modal.
 * Version: 4.8.0
 * Author: SimAbove
 */

if (!defined('ABSPATH')) exit;

class SimAbove_Checkout_Email_OTP_Verification_V48 {
    const NONCE_SEND = 'simabove_send_checkout_otp_v48';
    const NONCE_VERIFY = 'simabove_verify_checkout_otp_v48';
    const OPTION_KEY = 'simabove_checkout_otp_settings_v48';
    const LOG_TABLE = 'simabove_otp_logs_v48';

    private $defaults = array(
        'enabled' => 1,
        'skip_logged_in_users' => 1,
        'gateway_ids' => 'stripe,woo_stripe,paysera,checkoutcom',
        'otp_expiry_minutes' => 10,
        'resend_cooldown_seconds' => 60,
        'max_attempts' => 5,
        'otp_length' => 6,
        'email_subject' => 'Your SimAbove verification code',
        'email_heading' => 'SimAbove',
        'email_intro' => 'Use the code below to verify your email address and complete your order.',
        'email_footer' => 'International eSIM for travel - SimAbove',
        'verify_modal_title' => 'Verify your email address',
        'verify_modal_text' => 'We have to verify your email address before moving forward. We sent a verification code to your email address. Please enter it below to continue.',
        'block_disposable_domains' => 1,
        'block_gibberish_emails' => 1,
        'allowed_country_codes' => '',
        'blocked_country_codes' => '',
        'logo_url' => '',
        'email_bg_color' => '#456cc8',
        'email_card_bg' => '#f3f7ff',
        'tr_lv_verify_modal_title' => 'Apstipriniet savu e-pasta adresi',
        'tr_lv_verify_modal_text' => 'Mēs nosūtījām verifikācijas kodu uz Jūsu e-pasta adresi. Lūdzu, ievadiet to zemāk, lai turpinātu.',
        'tr_lv_email_subject' => 'Jūsu SimAbove verifikācijas kods',
        'tr_lv_email_heading' => 'SimAbove',
        'tr_lv_email_intro' => 'Izmantojiet zemāk esošo kodu, lai apstiprinātu savu e-pasta adresi un pabeigtu pasūtījumu.',
        'tr_lv_email_footer' => 'Starptautisks eSIM ceļošanai - SimAbove',
        'tr_et_verify_modal_title' => 'Kinnitage oma e-posti aadress',
        'tr_et_verify_modal_text' => 'Saatsime kinnituskoodi teie e-posti aadressile. Palun sisestage see allpool, et jätkata.',
        'tr_et_email_subject' => 'Teie SimAbove kinnituskood',
        'tr_et_email_heading' => 'SimAbove',
        'tr_et_email_intro' => 'Kasutage allolevat koodi oma e-posti aadressi kinnitamiseks ja tellimuse lõpetamiseks.',
        'tr_et_email_footer' => 'Rahvusvaheline eSIM reisimiseks - SimAbove',
        'tr_ru_verify_modal_title' => 'Подтвердите ваш адрес электронной почты',
        'tr_ru_verify_modal_text' => 'Мы отправили код подтверждения на ваш адрес электронной почты. Пожалуйста, введите его ниже, чтобы продолжить.',
        'tr_ru_email_subject' => 'Ваш код подтверждения SimAbove',
        'tr_ru_email_heading' => 'SimAbove',
        'tr_ru_email_intro' => 'Используйте код ниже, чтобы подтвердить ваш адрес электронной почты и завершить заказ.',
        'tr_ru_email_footer' => 'Международный eSIM для путешествий - SimAbove',
        'tr_lt_verify_modal_title' => 'Patvirtinkite savo el. pašto adresą',
        'tr_lt_verify_modal_text' => 'Išsiuntėme patvirtinimo kodą į jūsų el. paštą. Įveskite jį žemiau, kad galėtumėte tęsti.',
        'tr_lt_email_subject' => 'Jūsų SimAbove patvirtinimo kodas',
        'tr_lt_email_heading' => 'SimAbove',
        'tr_lt_email_intro' => 'Naudokite žemiau esantį kodą, kad patvirtintumėte savo el. pašto adresą ir užbaigtumėte užsakymą.',
        'tr_lt_email_footer' => 'Tarptautinis eSIM kelionėms - SimAbove',
        'tr_es_verify_modal_title' => 'Verifica tu dirección de correo electrónico',
        'tr_es_verify_modal_text' => 'Hemos enviado un código de verificación a tu correo electrónico. Introdúcelo a continuación para continuar.',
        'tr_es_email_subject' => 'Tu código de verificación de SimAbove',
        'tr_es_email_heading' => 'SimAbove',
        'tr_es_email_intro' => 'Usa el siguiente código para verificar tu dirección de correo electrónico y completar tu pedido.',
        'tr_es_email_footer' => 'eSIM internacional para viajar - SimAbove',
    );

    private $disposable = array('mailinator.com','guerrillamail.com','10minutemail.com','tempmail.com','yopmail.com','trashmail.com','sharklasers.com','dispostable.com','fakeinbox.com','emailondeck.com');

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            email VARCHAR(190) NOT NULL,
            ip_address VARCHAR(64) NULL,
            country_code VARCHAR(8) NULL,
            action_type VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            detail_text TEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY email (email)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function __construct() {
        add_action('admin_menu', array($this,'admin_menu'));
        add_action('admin_init', array($this,'register_settings'));
        add_action('wp_enqueue_scripts', array($this,'enqueue_assets'));
        add_action('woocommerce_after_checkout_form', array($this,'render_ui'));
        add_action('wp_ajax_simabove_send_checkout_otp_v48', array($this,'ajax_send_otp'));
        add_action('wp_ajax_nopriv_simabove_send_checkout_otp_v48', array($this,'ajax_send_otp'));
        add_action('wp_ajax_simabove_verify_checkout_otp_v48', array($this,'ajax_verify_otp'));
        add_action('wp_ajax_nopriv_simabove_verify_checkout_otp_v48', array($this,'ajax_verify_otp'));
        add_action('woocommerce_after_checkout_validation', array($this,'validate_before_checkout'), 20, 2);
    }

    private function settings() {
        $saved = get_option(self::OPTION_KEY, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), $this->defaults);
    }

    private function enabled_for_user() {
        $s = $this->localized_settings();
        if (empty($s['enabled'])) return false;
        if (!empty($s['skip_logged_in_users']) && is_user_logged_in()) return false;
        return true;
    }

    private function gateway_ids() {
        return array_values(array_filter(array_map('trim', explode(',', $this->settings()['gateway_ids']))));
    }

    private function ip() {
        foreach (array('HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR') as $k) {
            if (!empty($_SERVER[$k])) {
                $p = explode(',', sanitize_text_field(wp_unslash($_SERVER[$k])));
                $ip = trim($p[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '';
    }

    private function country() {
        foreach (array('HTTP_CF_IPCOUNTRY','GEOIP_COUNTRY_CODE') as $k) {
            if (!empty($_SERVER[$k])) return strtoupper(sanitize_text_field(wp_unslash($_SERVER[$k])));
        }
        return '';
    }

    private function log_event($email, $action, $status, $detail='') {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::LOG_TABLE, array(
            'created_at' => current_time('mysql'),
            'email' => sanitize_email($email),
            'ip_address' => $this->ip(),
            'country_code' => $this->country(),
            'action_type' => sanitize_text_field($action),
            'status' => sanitize_text_field($status),
            'detail_text' => sanitize_textarea_field($detail),
        ));
    }

    private function maybe_gibberish($email) {
        $local = strtolower(substr($email, 0, strpos($email, '@')));
        if (!$local) return true;
        if (preg_match('/^\d+$/', $local)) return true;
        if (preg_match('/(.)\1{4,}/', $local)) return true;
        preg_match_all('/[aeiou]/', $local, $m);
        if (strlen($local) >= 8 && count($m[0]) <= 1) return true;
        return false;
    }

    private function block_reason($email) {
        $s = $this->settings();
        $domain = strtolower(substr(strrchr($email, "@"), 1));
        if (!empty($s['block_disposable_domains']) && in_array($domain, $this->disposable, true)) return 'Disposable email domain blocked';
        if (!empty($s['block_gibberish_emails']) && $this->maybe_gibberish($email)) return 'Suspicious or fake-looking email blocked';
        $country = $this->country();
        $allowed = array_values(array_filter(array_map('trim', explode(',', strtoupper($s['allowed_country_codes'])))));
        $blocked = array_values(array_filter(array_map('trim', explode(',', strtoupper($s['blocked_country_codes'])))));
        if ($country) {
            if (!empty($allowed) && !in_array($country, $allowed, true)) return 'Country not allowed';
            if (!empty($blocked) && in_array($country, $blocked, true)) return 'Country blocked';
        }
        return '';
    }

    public function admin_menu() {
        add_submenu_page('woocommerce','SimAbove OTP Verification','SimAbove OTP','manage_woocommerce','simabove-otp-verification',array($this,'admin_page'));
    }

    public function register_settings() {
        register_setting('simabove_otp_group', self::OPTION_KEY, array($this,'sanitize_settings'));
    }

    public function sanitize_settings($in) {
        $out = $this->defaults;
        $out['enabled'] = !empty($in['enabled']) ? 1 : 0;
        $out['skip_logged_in_users'] = !empty($in['skip_logged_in_users']) ? 1 : 0;
        $out['gateway_ids'] = sanitize_text_field($in['gateway_ids'] ?? $out['gateway_ids']);
        $out['otp_expiry_minutes'] = max(1, min(60, intval($in['otp_expiry_minutes'] ?? 10)));
        $out['resend_cooldown_seconds'] = max(10, min(600, intval($in['resend_cooldown_seconds'] ?? 60)));
        $out['max_attempts'] = max(1, min(20, intval($in['max_attempts'] ?? 5)));
        $out['otp_length'] = max(4, min(8, intval($in['otp_length'] ?? 6)));
        foreach (array('email_subject','email_heading','email_footer','verify_modal_title','verify_modal_text','allowed_country_codes','blocked_country_codes','logo_url','email_bg_color','email_card_bg','tr_lv_verify_modal_title','tr_lv_verify_modal_text','tr_lv_email_subject','tr_lv_email_heading','tr_lv_email_footer','tr_et_verify_modal_title','tr_et_verify_modal_text','tr_et_email_subject','tr_et_email_heading','tr_et_email_footer','tr_ru_verify_modal_title','tr_ru_verify_modal_text','tr_ru_email_subject','tr_ru_email_heading','tr_ru_email_footer','tr_lt_verify_modal_title','tr_lt_verify_modal_text','tr_lt_email_subject','tr_lt_email_heading','tr_lt_email_footer','tr_es_verify_modal_title','tr_es_verify_modal_text','tr_es_email_subject','tr_es_email_heading','tr_es_email_footer') as $f) {
            $out[$f] = sanitize_text_field($in[$f] ?? $out[$f]);
        }
        $out['email_intro'] = sanitize_textarea_field($in['email_intro'] ?? $out['email_intro']);
        $out['tr_lv_email_intro'] = sanitize_textarea_field($in['tr_lv_email_intro'] ?? $out['tr_lv_email_intro']);
        $out['tr_et_email_intro'] = sanitize_textarea_field($in['tr_et_email_intro'] ?? $out['tr_et_email_intro']);
        $out['tr_ru_email_intro'] = sanitize_textarea_field($in['tr_ru_email_intro'] ?? $out['tr_ru_email_intro']);
        $out['tr_lt_email_intro'] = sanitize_textarea_field($in['tr_lt_email_intro'] ?? $out['tr_lt_email_intro']);
        $out['tr_es_email_intro'] = sanitize_textarea_field($in['tr_es_email_intro'] ?? $out['tr_es_email_intro']);
        $out['block_disposable_domains'] = !empty($in['block_disposable_domains']) ? 1 : 0;
        $out['block_gibberish_emails'] = !empty($in['block_gibberish_emails']) ? 1 : 0;
        return $out;
    }


    private function current_lang() {
        $lang = '';
        if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) $lang = ICL_LANGUAGE_CODE;
        if (function_exists('apply_filters')) {
            $wpml_lang = apply_filters('wpml_current_language', null);
            if (!empty($wpml_lang)) $lang = $wpml_lang;
        }
        if (!$lang && function_exists('determine_locale')) {
            $locale = determine_locale();
            if ($locale) $lang = strtolower(substr($locale, 0, 2));
        }
        return strtolower($lang ?: 'en');
    }

    private function localized_settings() {
        $s = $this->settings();
        $lang = $this->current_lang();
        $map = array('lv','et','ru','lt','es');
        if (in_array($lang, $map, true)) {
            foreach (array('verify_modal_title','verify_modal_text','email_subject','email_heading','email_intro','email_footer') as $k) {
                $tk = 'tr_' . $lang . '_' . $k;
                if (!empty($s[$tk])) $s[$k] = $s[$tk];
            }
        }
        return $s;
    }

    public function admin_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $sent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE action_type='send' AND status='success'");
        $verified = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE action_type='verify' AND status='success'");
        $blocked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='blocked'");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='failed'");
        $recent = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 50");
        ?>
        <div class="wrap">
            <h1>SimAbove OTP Verification</h1>
            <style>
                .simabove-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 20px}
                .simabove-tab-btn{padding:10px 14px;border:1px solid #d0d7de;background:#fff;border-radius:8px;cursor:pointer;text-decoration:none;color:#1f2937}
                .simabove-tab-btn.active{background:#456cc8;color:#fff;border-color:#456cc8}
                .simabove-tab-panel{display:none}
                .simabove-tab-panel.active{display:block}
                .simabove-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-top:12px}
            </style>

            <div class="simabove-tabs">
                <a href="#" class="simabove-tab-btn active" data-tab="general">General</a>
                <a href="#" class="simabove-tab-btn" data-tab="design">Design</a>
                <a href="#" class="simabove-tab-btn" data-tab="languages">Languages</a>
                <a href="#" class="simabove-tab-btn" data-tab="analytics">Analytics</a>
                <a href="#" class="simabove-tab-btn" data-tab="logs">Logs</a>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('simabove_otp_group'); ?>

                <div class="simabove-tab-panel active" id="simabove-tab-general">
                    <div class="simabove-card">
                        <h2>General</h2>
                        <table class="form-table" role="presentation">
                            <tr><th>Enable OTP</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked($s['enabled'], 1); ?>> Enable on checkout</label></td></tr>
                            <tr><th>Skip logged-in users</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[skip_logged_in_users]" value="1" <?php checked($s['skip_logged_in_users'], 1); ?>> Do not require OTP for logged-in users</label></td></tr>
                            <tr><th>Payment method IDs</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[gateway_ids]" value="<?php echo esc_attr($s['gateway_ids']); ?>"></td></tr>
                            <tr><th>OTP length</th><td><input type="number" min="4" max="8" name="<?php echo esc_attr(self::OPTION_KEY); ?>[otp_length]" value="<?php echo esc_attr($s['otp_length']); ?>"></td></tr>
                            <tr><th>OTP expiry (minutes)</th><td><input type="number" min="1" max="60" name="<?php echo esc_attr(self::OPTION_KEY); ?>[otp_expiry_minutes]" value="<?php echo esc_attr($s['otp_expiry_minutes']); ?>"></td></tr>
                            <tr><th>Resend cooldown (seconds)</th><td><input type="number" min="10" max="600" name="<?php echo esc_attr(self::OPTION_KEY); ?>[resend_cooldown_seconds]" value="<?php echo esc_attr($s['resend_cooldown_seconds']); ?>"></td></tr>
                            <tr><th>Max attempts</th><td><input type="number" min="1" max="20" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_attempts]" value="<?php echo esc_attr($s['max_attempts']); ?>"></td></tr>
                            <tr><th>Block disposable domains</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[block_disposable_domains]" value="1" <?php checked($s['block_disposable_domains'], 1); ?>> Yes</label></td></tr>
                            <tr><th>Block fake-looking emails</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[block_gibberish_emails]" value="1" <?php checked($s['block_gibberish_emails'], 1); ?>> Yes</label></td></tr>
                            <tr><th>Allowed country codes</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allowed_country_codes]" value="<?php echo esc_attr($s['allowed_country_codes']); ?>"></td></tr>
                            <tr><th>Blocked country codes</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[blocked_country_codes]" value="<?php echo esc_attr($s['blocked_country_codes']); ?>"></td></tr>
                        </table>
                    </div>
                </div>

                <div class="simabove-tab-panel" id="simabove-tab-design">
                    <div class="simabove-card">
                        <h2>Design</h2>
                        <table class="form-table" role="presentation">
                            <tr><th>Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_subject]" value="<?php echo esc_attr($s['email_subject']); ?>"></td></tr>
                            <tr><th>Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_heading]" value="<?php echo esc_attr($s['email_heading']); ?>"></td></tr>
                            <tr><th>Email intro</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_intro]"><?php echo esc_textarea($s['email_intro']); ?></textarea></td></tr>
                            <tr><th>Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_footer]" value="<?php echo esc_attr($s['email_footer']); ?>"></td></tr>
                            <tr><th>Logo URL</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[logo_url]" value="<?php echo esc_attr($s['logo_url']); ?>"></td></tr>
                            <tr><th>Email header color</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_bg_color]" value="<?php echo esc_attr($s['email_bg_color']); ?>"></td></tr>
                            <tr><th>Email code box bg</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_card_bg]" value="<?php echo esc_attr($s['email_card_bg']); ?>"></td></tr>
                            <tr><th>Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[verify_modal_title]" value="<?php echo esc_attr($s['verify_modal_title']); ?>"></td></tr>
                            <tr><th>Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[verify_modal_text]" value="<?php echo esc_attr($s['verify_modal_text']); ?>"></td></tr>
                        </table>
                    </div>
                </div>

                <div class="simabove-tab-panel" id="simabove-tab-languages">
                    <div class="simabove-card">
                        <h2>Languages</h2>
                        <p>Fill WPML language-specific fields. If empty, default values are used.</p>

                        <h3>Latvian (lv)</h3>
                        <table class="form-table" role="presentation">
                            <tr><th>LV Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_email_subject]" value="<?php echo esc_attr($s['tr_lv_email_subject']); ?>"></td></tr>
                            <tr><th>LV Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_email_heading]" value="<?php echo esc_attr($s['tr_lv_email_heading']); ?>"></td></tr>
                            <tr><th>LV Email intro</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_email_intro]"><?php echo esc_textarea($s['tr_lv_email_intro']); ?></textarea></td></tr>
                            <tr><th>LV Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_email_footer]" value="<?php echo esc_attr($s['tr_lv_email_footer']); ?>"></td></tr>
                            <tr><th>LV Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_verify_modal_title]" value="<?php echo esc_attr($s['tr_lv_verify_modal_title']); ?>"></td></tr>
                            <tr><th>LV Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lv_verify_modal_text]" value="<?php echo esc_attr($s['tr_lv_verify_modal_text']); ?>"></td></tr>
                        </table>

                        <h3>Estonian (et)</h3>
                        <table class="form-table" role="presentation">
                            <tr><th>ET Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_email_subject]" value="<?php echo esc_attr($s['tr_et_email_subject']); ?>"></td></tr>
                            <tr><th>ET Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_email_heading]" value="<?php echo esc_attr($s['tr_et_email_heading']); ?>"></td></tr>
                            <tr><th>ET Email intro</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_email_intro]"><?php echo esc_textarea($s['tr_et_email_intro']); ?></textarea></td></tr>
                            <tr><th>ET Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_email_footer]" value="<?php echo esc_attr($s['tr_et_email_footer']); ?>"></td></tr>
                            <tr><th>ET Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_verify_modal_title]" value="<?php echo esc_attr($s['tr_et_verify_modal_title']); ?>"></td></tr>
                            <tr><th>ET Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_et_verify_modal_text]" value="<?php echo esc_attr($s['tr_et_verify_modal_text']); ?>"></td></tr>
                        </table>

                        <h3>Russian (ru)</h3>
                        <table class="form-table" role="presentation">
                            <tr><th>RU Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_email_subject]" value="<?php echo esc_attr($s['tr_ru_email_subject']); ?>"></td></tr>
                            <tr><th>RU Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_email_heading]" value="<?php echo esc_attr($s['tr_ru_email_heading']); ?>"></td></tr>
                            <tr><th>RU Email intro</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_email_intro]"><?php echo esc_textarea($s['tr_ru_email_intro']); ?></textarea></td></tr>
                            <tr><th>RU Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_email_footer]" value="<?php echo esc_attr($s['tr_ru_email_footer']); ?>"></td></tr>
                            <tr><th>RU Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_verify_modal_title]" value="<?php echo esc_attr($s['tr_ru_verify_modal_title']); ?>"></td></tr>
                            <tr><th>RU Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_ru_verify_modal_text]" value="<?php echo esc_attr($s['tr_ru_verify_modal_text']); ?>"></td></tr>
                        </table>

                        <h3>Lithuanian (lt)</h3>
                        <table class="form-table" role="presentation">
                            <tr><th>LT Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_email_subject]" value="<?php echo esc_attr($s['tr_lt_email_subject']); ?>"></td></tr>
                            <tr><th>LT Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_email_heading]" value="<?php echo esc_attr($s['tr_lt_email_heading']); ?>"></td></tr>
                            <tr><th>LT Email intro</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_email_intro]"><?php echo esc_textarea($s['tr_lt_email_intro']); ?></textarea></td></tr>
                            <tr><th>LT Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_email_footer]" value="<?php echo esc_attr($s['tr_lt_email_footer']); ?>"></td></tr>
                            <tr><th>LT Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_verify_modal_title]" value="<?php echo esc_attr($s['tr_lt_verify_modal_title']); ?>"></td></tr>
                            <tr><th>LT Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_lt_verify_modal_text]" value="<?php echo esc_attr($s['tr_lt_verify_modal_text']); ?>"></td></tr>
                        </table>

                        <h3>Spanish (es)</h3>
                        <table class="form-table" role="presentation">
                            <tr><th>ES Email subject</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_email_subject]" value="<?php echo esc_attr($s['tr_es_email_subject']); ?>"></td></tr>
                            <tr><th>ES Email heading</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_email_heading]" value="<?php echo esc_attr($s['tr_es_email_heading']); ?>"></td></tr>
                            <tr><th>ES Email intro</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_email_intro]"><?php echo esc_textarea($s['tr_es_email_intro']); ?></textarea></td></tr>
                            <tr><th>ES Email footer</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_email_footer]" value="<?php echo esc_attr($s['tr_es_email_footer']); ?>"></td></tr>
                            <tr><th>ES Modal title</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_verify_modal_title]" value="<?php echo esc_attr($s['tr_es_verify_modal_title']); ?>"></td></tr>
                            <tr><th>ES Modal text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[tr_es_verify_modal_text]" value="<?php echo esc_attr($s['tr_es_verify_modal_text']); ?>"></td></tr>
                        </table>
                    </div>
                </div>

                <div class="simabove-tab-panel" id="simabove-tab-analytics">
                    <div class="simabove-card">
                        <h2>Analytics</h2>
                        <table class="widefat" style="max-width:900px"><thead><tr><th>Codes sent</th><th>Verified</th><th>Blocked</th><th>Failed</th></tr></thead><tbody><tr><td><?php echo esc_html($sent); ?></td><td><?php echo esc_html($verified); ?></td><td><?php echo esc_html($blocked); ?></td><td><?php echo esc_html($failed); ?></td></tr></tbody></table>
                    </div>
                </div>

                <div class="simabove-tab-panel" id="simabove-tab-logs">
                    <div class="simabove-card">
                        <h2>Recent logs</h2>
                        <table class="widefat striped"><thead><tr><th>Time</th><th>Email</th><th>IP</th><th>Country</th><th>Action</th><th>Status</th><th>Detail</th></tr></thead><tbody>
                        <?php if ($recent): foreach ($recent as $row): ?>
                            <tr><td><?php echo esc_html($row->created_at); ?></td><td><?php echo esc_html($row->email); ?></td><td><?php echo esc_html($row->ip_address); ?></td><td><?php echo esc_html($row->country_code); ?></td><td><?php echo esc_html($row->action_type); ?></td><td><?php echo esc_html($row->status); ?></td><td><?php echo esc_html($row->detail_text); ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7">No logs yet.</td></tr>
                        <?php endif; ?>
                        </tbody></table>
                    </div>
                </div>

                <?php submit_button('Save settings'); ?>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function(){
                const buttons = document.querySelectorAll('.simabove-tab-btn');
                const panels = document.querySelectorAll('.simabove-tab-panel');
                buttons.forEach(btn => {
                    btn.addEventListener('click', function(e){
                        e.preventDefault();
                        buttons.forEach(b => b.classList.remove('active'));
                        panels.forEach(p => p.classList.remove('active'));
                        btn.classList.add('active');
                        const panel = document.getElementById('simabove-tab-' + btn.dataset.tab);
                        if (panel) panel.classList.add('active');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function enqueue_assets() {
        if (!$this->enabled_for_user()) return;
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) return;
        $s = $this->localized_settings();
        wp_enqueue_script('jquery');
        wp_register_style('simabove-checkout-otp-style-v48', false);
        wp_enqueue_style('simabove-checkout-otp-style-v48');
        $css = '.woocommerce-checkout, .woocommerce-checkout button, .woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea, #simabove-otp-modal, #simabove-otp-modal * { font-family: Inter, Arial, sans-serif; } #simabove-otp-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99998} #simabove-otp-modal{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(92vw,430px);background:#fff;border-radius:18px;padding:24px;box-shadow:0 25px 60px rgba(0,0,0,.2);z-index:99999} #simabove-otp-brand{font-size:14px;font-weight:700;color:#456cc8;margin:0 0 8px;letter-spacing:.02em;text-transform:uppercase} #simabove-otp-modal h3{margin:0 0 10px;font-size:22px;line-height:1.2} #simabove-otp-modal p{margin:0 0 14px;color:#475569} #simabove-otp-timer{font-size:14px;font-weight:600;color:#456cc8;margin:0 0 12px} #simabove-otp-input{width:100%;padding:12px 14px;font-size:20px;letter-spacing:5px;border:1px solid #cbd5e1;border-radius:12px;box-sizing:border-box;text-align:center} #simabove-otp-modal-actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap} #simabove-otp-modal-actions .button.alt{background:#0f172a;border-color:#0f172a;color:#fff} #simabove-otp-modal-message{margin-top:10px;font-size:13px} #simabove-resend-timer{font-size:12px;color:#64748b;margin-top:10px}';
        wp_add_inline_style('simabove-checkout-otp-style-v48', $css);
        wp_register_script('simabove-checkout-otp-script-v48', false, array('jquery'), '4.1.0', true);
        wp_enqueue_script('simabove-checkout-otp-script-v48');
        wp_localize_script('simabove-checkout-otp-script-v48', 'SimAboveCheckoutOtpV48', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'send_nonce' => wp_create_nonce(self::NONCE_SEND),
            'verify_nonce' => wp_create_nonce(self::NONCE_VERIFY),
            'card_gateways' => $this->gateway_ids(),
            'cooldown_seconds' => intval($s['resend_cooldown_seconds']),
            'otp_length' => intval($s['otp_length']),
            'expiry_minutes' => intval($s['otp_expiry_minutes']),
            'modal_title' => $s['verify_modal_title'],
            'modal_text' => $s['verify_modal_text'],
            'brand' => $s['email_heading'],
            'texts' => array(
                'verified' => __('Email verified.', 'simabove'),
                'need_email' => __('Please enter a valid email address first.', 'simabove'),
                'invalid_code' => __('Invalid code.', 'simabove'),
                'verifying' => __('Verifying...', 'simabove'),
                'seconds_left' => __('seconds until resend', 'simabove'),
                'timer_prefix' => __('Code expires in:', 'simabove'),
                'expired' => __('Verification code expired. Please resend a new code.', 'simabove'),
            ),
        ));
        $js = <<<'JS'
jQuery(function($){
 let verifiedEmail='', resendInterval=null, resendRemaining=0, expiryInterval=null, expiryRemaining=0, sendingOtp=false;

 function normalizeEmail(e){return (e||'').trim().toLowerCase();}
 function isValidEmail(e){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);}
 function getSelectedPaymentMethod(){return $('input[name="payment_method"]:checked').val()||'';}
 function isCardPaymentSelected(){return SimAboveCheckoutOtpV48.card_gateways.includes(getSelectedPaymentMethod());}
 function currentCheckoutEmail(){return normalizeEmail($('#billing_email').val());}
 function closeModal(){ $('#simabove-otp-modal-backdrop, #simabove-otp-modal').hide(); }
 function formatTime(sec){ const m=Math.floor(sec/60); const s=sec%60; return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); }
 function setModalMessage(msg,color){ $('#simabove-otp-modal-message').text(msg||'').css('color', color||'#475569'); }

 function startExpiryTimer(){
   expiryRemaining=(parseInt(SimAboveCheckoutOtpV48.expiry_minutes,10)||10)*60;
   $('#simabove-otp-timer').text(SimAboveCheckoutOtpV48.texts.timer_prefix+' '+formatTime(expiryRemaining));
   if(expiryInterval) clearInterval(expiryInterval);
   expiryInterval=setInterval(function(){
     expiryRemaining--;
     if(expiryRemaining<=0){
       clearInterval(expiryInterval); expiryInterval=null;
       $('#simabove-otp-timer').text(SimAboveCheckoutOtpV48.texts.expired);
     } else {
       $('#simabove-otp-timer').text(SimAboveCheckoutOtpV48.texts.timer_prefix+' '+formatTime(expiryRemaining));
     }
   },1000);
 }

 function openModal(){
   $('#simabove-otp-title').text(SimAboveCheckoutOtpV48.modal_title);
   $('#simabove-otp-text').text(SimAboveCheckoutOtpV48.modal_text);
   $('#simabove-otp-brand').text(SimAboveCheckoutOtpV48.brand);
   $('#simabove-otp-modal-backdrop, #simabove-otp-modal').show();
   $('#simabove-otp-input').val('').focus();
   setModalMessage('Sending code...','#456cc8');
   startExpiryTimer();
 }

 function startCooldown(){
   resendRemaining=parseInt(SimAboveCheckoutOtpV48.cooldown_seconds,10)||60;
   $('#simabove-resend-otp').prop('disabled',true);
   $('#simabove-resend-timer').text(resendRemaining+' '+SimAboveCheckoutOtpV48.texts.seconds_left);
   if(resendInterval) clearInterval(resendInterval);
   resendInterval=setInterval(function(){
     resendRemaining--;
     if(resendRemaining<=0){
       clearInterval(resendInterval); resendInterval=null;
       $('#simabove-resend-otp').prop('disabled',false); $('#simabove-resend-timer').text('');
     } else {
       $('#simabove-resend-timer').text(resendRemaining+' '+SimAboveCheckoutOtpV48.texts.seconds_left);
     }
   },1000);
 }

 function sendOtp(showModalFirst){
   const email=currentCheckoutEmail();
   if(!isValidEmail(email)){ alert(SimAboveCheckoutOtpV48.texts.need_email); return; }
   if(showModalFirst) openModal();
   sendingOtp = true;
   $.post(SimAboveCheckoutOtpV48.ajax_url,{action:'simabove_send_checkout_otp_v48',nonce:SimAboveCheckoutOtpV48.send_nonce,email:email})
   .done(function(resp){
     sendingOtp = false;
     if(resp&&resp.success){
       setModalMessage(resp.data.message||'Verification code sent to your email.','#456cc8');
       startCooldown();
     } else {
       setModalMessage((resp&&resp.data&&resp.data.message)?resp.data.message:'Could not send code.','#b91c1c');
     }
   })
   .fail(function(xhr){
     sendingOtp = false;
     let msg='Could not send code. Please try again.';
     if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message) msg=xhr.responseJSON.data.message;
     setModalMessage(msg,'#b91c1c');
   });
 }

 $(document).on('input','#billing_email',function(){
   if(verifiedEmail && verifiedEmail !== currentCheckoutEmail()){
     verifiedEmail = '';
   }
 });

 $(document).on('click','#simabove-otp-close, #simabove-otp-modal-backdrop',function(e){ e.preventDefault(); closeModal(); });
 $(document).on('click','#simabove-resend-otp',function(e){ e.preventDefault(); sendOtp(false); });

 $(document).on('click','#simabove-verify-otp',function(e){
   e.preventDefault();
   if(expiryRemaining<=0){ setModalMessage(SimAboveCheckoutOtpV48.texts.expired,'#b91c1c'); return; }
   const code=($('#simabove-otp-input').val()||'').trim(), email=currentCheckoutEmail(), needed=parseInt(SimAboveCheckoutOtpV48.otp_length,10)||6;
   if(!code||code.length!==needed){ setModalMessage(SimAboveCheckoutOtpV48.texts.invalid_code,'#b91c1c'); return; }
   setModalMessage(SimAboveCheckoutOtpV48.texts.verifying,'#456cc8');
   $.post(SimAboveCheckoutOtpV48.ajax_url,{action:'simabove_verify_checkout_otp_v48',nonce:SimAboveCheckoutOtpV48.verify_nonce,email:email,code:code})
   .done(function(resp){
     if(resp&&resp.success){
       verifiedEmail=email;
       setModalMessage(resp.data.message||SimAboveCheckoutOtpV48.texts.verified,'#15803d');
       setTimeout(function(){
         closeModal();
         $('form.checkout').trigger('submit');
       },300);
     } else {
       setModalMessage((resp&&resp.data&&resp.data.message)?resp.data.message:SimAboveCheckoutOtpV48.texts.invalid_code,'#b91c1c');
     }
   })
   .fail(function(xhr){
     let msg='Verification failed. Please try again.';
     if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message) msg=xhr.responseJSON.data.message;
     setModalMessage(msg,'#b91c1c');
   });
 });

 $('form.checkout').on('checkout_place_order', function(){
   if(!isCardPaymentSelected()) return true;
   const email=currentCheckoutEmail();
   // Already verified - allow order through
   if(verifiedEmail && verifiedEmail===email) return true;
   // Block order, then trigger OTP flow
   if(!isValidEmail(email)){ alert(SimAboveCheckoutOtpV48.texts.need_email); return false; }
   if($('#simabove-otp-modal').is(':visible') || sendingOtp) return false;
   sendOtp(true);
   return false;
 });
});
JS;
        wp_add_inline_script('simabove-checkout-otp-script-v48', $js);
    }

    public function render_ui() {
        if (!$this->enabled_for_user()) return;
        if (!is_checkout() || is_order_received_page()) return;
        ?>
        <div id="simabove-otp-modal-backdrop"></div>
        <div id="simabove-otp-modal" role="dialog" aria-modal="true" aria-labelledby="simabove-otp-title">
            <div id="simabove-otp-brand"><?php echo esc_html($this->localized_settings()['email_heading']); ?></div>
            <h3 id="simabove-otp-title"><?php echo esc_html($this->localized_settings()['verify_modal_title']); ?></h3>
            <p id="simabove-otp-text"><?php echo esc_html($this->localized_settings()['verify_modal_text']); ?></p>
            <div id="simabove-otp-timer"></div>
            <input type="text" id="simabove-otp-input" maxlength="<?php echo esc_attr(intval($this->localized_settings()['otp_length'])); ?>" inputmode="numeric" autocomplete="one-time-code" />
            <div id="simabove-otp-modal-actions">
                <button type="button" class="button alt" id="simabove-verify-otp"><?php echo esc_html__('Verify email', 'simabove'); ?></button>
                <button type="button" class="button" id="simabove-resend-otp"><?php echo esc_html__('Resend code', 'simabove'); ?></button>
                <button type="button" class="button" id="simabove-otp-close"><?php echo esc_html__('Close', 'simabove'); ?></button>
            </div>
            <div id="simabove-resend-timer"></div>
            <div id="simabove-otp-modal-message"></div>
        </div>
        <?php
    }

    private function get_session_value($key) {
        if (function_exists('WC') && WC()->session) return WC()->session->get($key);
        return get_transient($key);
    }

    private function set_session_value($key, $value, $ttl = null) {
        if (function_exists('WC') && WC()->session) WC()->session->set($key, $value);
        else set_transient($key, $value, $ttl ? $ttl : HOUR_IN_SECONDS);
    }

    private function session_key($email) { return 'simabove_checkout_otp_v41_' . md5(strtolower(trim($email))); }
    private function attempts_key($email) { return 'simabove_checkout_otp_attempts_v41_' . md5(strtolower(trim($email))); }
    private function cooldown_key($email) { return 'simabove_checkout_otp_cooldown_v41_' . md5(strtolower(trim($email))); }

    private function generate_code($length) {
        $min = intval(str_pad('1', $length, '0'));
        $max = intval(str_repeat('9', $length));
        return (string) wp_rand($min, $max);
    }

    private function store_otp($email, $code, $ttl) {
        $this->set_session_value($this->session_key($email), array('code'=>$code,'expires_at'=>time()+$ttl,'verified'=>false), $ttl);
        $this->set_session_value($this->attempts_key($email), 0, $ttl);
    }

    private function otp_data($email) {
        $data = $this->get_session_value($this->session_key($email));
        return is_array($data) ? $data : null;
    }

    private function set_verified($email, $ttl) {
        $data = $this->otp_data($email);
        if (!$data) return;
        $data['verified'] = true;
        $this->set_session_value($this->session_key($email), $data, $ttl);
        $this->set_session_value('simabove_verified_checkout_email_v41', strtolower(trim($email)), $ttl);
    }

    private function is_verified($email) {
        return strtolower(trim($email)) === $this->get_session_value('simabove_verified_checkout_email_v41');
    }

    public function ajax_send_otp() {
        check_ajax_referer(self::NONCE_SEND, 'nonce');
        $s = $this->localized_settings();
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$email || !is_email($email)) wp_send_json_error(array('message' => __('Please enter a valid email address.', 'simabove')), 400);

        $reason = $this->block_reason($email);
        if ($reason) { $this->log_event($email,'send','blocked',$reason); wp_send_json_error(array('message' => __($reason,'simabove')), 403); }

        $until = (int) $this->get_session_value($this->cooldown_key($email));
        if ($until && time() < $until) {
            $msg = sprintf(__('Please wait %d seconds before requesting a new code.', 'simabove'), max(0, $until-time()));
            $this->log_event($email,'send','failed',$msg);
            wp_send_json_error(array('message' => $msg), 429);
        }

        $ttl = intval($s['otp_expiry_minutes']) * MINUTE_IN_SECONDS;
        $code = $this->generate_code(intval($s['otp_length']));
        $this->store_otp($email, $code, $ttl);
        $this->set_session_value($this->cooldown_key($email), time() + intval($s['resend_cooldown_seconds']), intval($s['resend_cooldown_seconds']));

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($email, $s['email_subject'], $this->email_template($code, $s), $headers);
        if (!$sent) { $this->log_event($email,'send','failed','wp_mail failed'); wp_send_json_error(array('message' => __('The verification email could not be sent.', 'simabove')), 500); }

        $this->log_event($email,'send','success','OTP sent');
        wp_send_json_success(array('message' => __('Verification code sent to your email.', 'simabove')));
    }

    public function ajax_verify_otp() {
        check_ajax_referer(self::NONCE_VERIFY, 'nonce');
        $s = $this->settings();
        $ttl = intval($s['otp_expiry_minutes']) * MINUTE_IN_SECONDS;
        $len = intval($s['otp_length']);
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';

        if (!$email || !is_email($email)) wp_send_json_error(array('message' => __('Invalid email address.', 'simabove')), 400);
        if (!preg_match('/^\d{' . $len . '}$/', $code)) { $this->log_event($email,'verify','failed','Invalid code format'); wp_send_json_error(array('message' => __('Invalid code format.', 'simabove')), 400); }

        $data = $this->otp_data($email);
        if (!$data) { $this->log_event($email,'verify','failed','No OTP found'); wp_send_json_error(array('message' => __('No verification code found. Please request a new one.', 'simabove')), 400); }
        if (time() > (int) $data['expires_at']) { $this->log_event($email,'verify','failed','Expired OTP'); wp_send_json_error(array('message' => __('This code has expired. Please request a new one.', 'simabove')), 400); }

        $attempts = (int) $this->get_session_value($this->attempts_key($email));
        if ($attempts >= intval($s['max_attempts'])) { $this->log_event($email,'verify','blocked','Too many attempts'); wp_send_json_error(array('message' => __('Too many incorrect attempts. Please request a new code.', 'simabove')), 429); }

        if ((string) $data['code'] !== (string) $code) {
            $this->set_session_value($this->attempts_key($email), $attempts+1, $ttl);
            $this->log_event($email,'verify','failed','Incorrect code');
            wp_send_json_error(array('message' => __('Incorrect verification code.', 'simabove')), 400);
        }

        $this->set_verified($email, $ttl);
        $this->log_event($email,'verify','success','OTP verified');
        wp_send_json_success(array('message' => __('Email verified successfully.', 'simabove')));
    }

    public function validate_before_checkout($data, $errors) {
        if (!$this->enabled_for_user()) return;
        $payment = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
        if (!in_array($payment, $this->gateway_ids(), true)) return;
        $email = isset($data['billing_email']) ? sanitize_email($data['billing_email']) : '';
        if (!$email || !is_email($email)) return;
        $reason = $this->block_reason($email);
        if ($reason) { $errors->add('simabove_email_blocked', __($reason,'simabove')); return; }
        if (!$this->is_verified($email)) $errors->add('simabove_email_not_verified', __('Please verify your email address before paying by card.', 'simabove'));
    }

    private function email_template($code, $s) {
        $code = esc_html($code);
        $heading = esc_html($s['email_heading']);
        $intro = esc_html($s['email_intro']);
        $footer = esc_html($s['email_footer']);
        $mins = intval($s['otp_expiry_minutes']);
        $logo = !empty($s['logo_url']) ? '<div style="margin-bottom:14px;"><img src="' . esc_url($s['logo_url']) . '" alt="' . $heading . '" style="max-height:48px;width:auto;"></div>' : '';
        $header_bg = !empty($s['email_bg_color']) ? esc_attr($s['email_bg_color']) : '#456cc8';
        $card_bg = !empty($s['email_card_bg']) ? esc_attr($s['email_card_bg']) : '#f3f7ff';
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f6f9;font-family:Inter,Arial,sans-serif;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f9;"><tr><td align="center" style="padding:40px 20px;"><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden;"><tr><td style="padding:28px 32px;background:' . $header_bg . ';color:#fff;font-size:24px;font-weight:700;text-align:center;">' . $logo . $heading . '</td></tr><tr><td style="padding:36px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">'.$intro.'</p><div style="margin:24px 0;padding:22px;border:2px solid ' . $header_bg . ';border-radius:14px;background:' . $card_bg . ';text-align:center;"><div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:' . $header_bg . ';font-weight:700;margin-bottom:8px;">Verification Code</div><div style="font-size:36px;line-height:1.2;font-weight:700;color:' . $header_bg . ';">'.$code.'</div></div><p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#5f6b7a;">This code expires in '.$mins.' minutes.</p><p style="margin:0;font-size:14px;line-height:1.6;color:#5f6b7a;">If you did not request this code, you can ignore this email.</p></td></tr><tr><td style="padding:24px 32px;background:#f4f6f9;border-top:1px solid #e6e9ef;text-align:center;font-size:12px;color:#64748b;">'.$footer.'</td></tr></table></td></tr></table></body></html>';
    }
}

register_activation_hook(__FILE__, array('SimAbove_Checkout_Email_OTP_Verification_V48', 'activate'));
new SimAbove_Checkout_Email_OTP_Verification_V48();
