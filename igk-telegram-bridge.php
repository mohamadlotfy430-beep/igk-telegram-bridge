<?php
/**
 * Plugin Name: IranGemKadeh Telegram Order Bridge
 * Description: Bridges order messages from the existing Telegram group to a work group and relays admin results back to the original order message.
 * Version: 1.2.1
 * Author: IranGemKadeh
 * Requires Plugins: woocommerce
 * Text Domain: igk-telegram-bridge
 */
if (!defined('ABSPATH')) exit;

class IGK_Telegram_Order_Bridge {
    const OPT = 'igk_tg_bridge_settings';
    const VERSION = '1.2.1';
    const REST_NS = 'igk-tg-bridge/v1';
    private static $instance = null;
    private $table;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'igk_tg_bridge_orders';
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            group1_chat_id varchar(64) NOT NULL DEFAULT '',
            group1_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            group2_chat_id varchar(64) NOT NULL DEFAULT '',
            group2_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_text longtext NULL,
            status varchar(32) NOT NULL DEFAULT 'capturing',
            dollar_amount varchar(40) NOT NULL DEFAULT '',
            telegram_update_id bigint(20) unsigned NOT NULL DEFAULT 0,
            result_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY group2_message_id (group2_message_id),
            KEY status (status)
        ) $charset;";
        dbDelta($sql);
        update_option('igk_tg_bridge_db_version', self::VERSION);
    }

    public function init() {
        add_filter('http_response', [$this, 'capture_existing_notifier_message'], 99, 3);
        add_action('admin_menu', [$this, 'admin_menu'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_webhook_route']);
        add_action('admin_post_igk_tg_bridge_test', [$this, 'handle_test']);
        add_action('admin_post_igk_tg_bridge_webhook', [$this, 'handle_webhook_setup']);
        add_action('admin_post_igk_tg_bridge_retry', [$this, 'handle_retry']);
        add_action('admin_post_igk_tg_bridge_retry_legacy', [$this, 'handle_retry_legacy']);
        // Upgrade the table when the plugin is updated without requiring deactivation.
        if (get_option('igk_tg_bridge_db_version') !== self::VERSION) $this->activate();
    }

    private function defaults() {
        return [
            'bot_token' => '',
            'group1_id' => '',
            'group2_id' => '',
            'complete_reaction' => '👍',
            'failed_reaction' => '👎',
            'excluded_statuses' => [],
            'secret' => wp_generate_password(40, false, false),
        ];
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPT, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('igk_tg_bridge_group', self::OPT, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $out = $this->settings();
        foreach (['bot_token', 'group1_id', 'group2_id'] as $key) {
            if (isset($input[$key])) $out[$key] = sanitize_text_field($input[$key]);
        }
        foreach (['complete_reaction', 'failed_reaction'] as $key) {
            if (isset($input[$key])) $out[$key] = sanitize_text_field($input[$key]);
        }
        $out['excluded_statuses'] = (isset($input['excluded_statuses']) && is_array($input['excluded_statuses']))
            ? array_values(array_unique(array_map(function($v) { return sanitize_key($v); }, $input['excluded_statuses'])))
            : [];
        if (empty($out['secret'])) $out['secret'] = wp_generate_password(40, false, false);
        return $out;
    }

    public function admin_menu() {
        add_submenu_page('woocommerce', 'پل ارتباطی تلگرام', 'پل ارتباطی تلگرام', 'manage_woocommerce', 'igk-tg-bridge', [$this, 'settings_page']);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        global $wpdb;
        $s = $this->settings();
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 30");
        $webhook_url = rest_url(self::REST_NS . '/webhook');
        ?>
        <div class="wrap" dir="rtl" style="max-width:1100px">
            <h1>پل ارتباطی سفارش‌های تلگرام</h1>
            <div class="notice notice-info"><p>این افزونه پیام سفارش افزونه قدیمی را در گروه اصلی شناسایی می‌کند، همان پیام را به گروه دوم می‌فرستد و نتیجه را روی پیام اصلی گروه اول ریپلای می‌کند. سپس نتیجه را به وب‌هوک افزونه قدیمی تحویل می‌دهد تا همان افزونه وضعیت و شمارنده خودش را مدیریت کند.</p></div>
            <?php if (isset($_GET['igk_msg'])): ?><div class="notice notice-info"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['igk_msg']))); ?></p></div><?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('igk_tg_bridge_group'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="igk_token">توکن ربات جدید</label></th><td><input id="igk_token" type="password" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[bot_token]" value="<?php echo esc_attr($s['bot_token']); ?>" autocomplete="new-password"><p class="description">ربات جدید باید در هر دو گروه عضو باشد؛ در گروه دوم ادمین باشد و در گروه اول اجازه ارسال پیام و واکنش داشته باشد.</p></td></tr>
                    <tr><th><label for="igk_g1">شناسه گروه اصلی</label></th><td><input id="igk_g1" type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[group1_id]" value="<?php echo esc_attr($s['group1_id']); ?>" placeholder="-1001234567890"></td></tr>
                    <tr><th><label for="igk_g2">شناسه گروه انجام سفارش</label></th><td><input id="igk_g2" type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[group2_id]" value="<?php echo esc_attr($s['group2_id']); ?>" placeholder="-1001234567890"></td></tr>
                    <tr><th><label for="igk_complete_reaction">واکنش سفارش تکمیل‌شده</label></th><td><input id="igk_complete_reaction" type="text" class="small-text" name="<?php echo esc_attr(self::OPT); ?>[complete_reaction]" value="<?php echo esc_attr($s['complete_reaction']); ?>"></td></tr>
                    <tr><th><label for="igk_failed_reaction">واکنش سفارش ناموفق</label></th><td><input id="igk_failed_reaction" type="text" class="small-text" name="<?php echo esc_attr(self::OPT); ?>[failed_reaction]" value="<?php echo esc_attr($s['failed_reaction']); ?>"></td></tr>
                    <tr><th>وضعیت‌هایی که نباید منتقل شوند</th><td>
                        <?php $order_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : []; ?>
                        <select name="<?php echo esc_attr(self::OPT); ?>[excluded_statuses][]" multiple="multiple" size="6" style="min-width:280px">
                            <?php foreach ($order_statuses as $status_key => $status_label): $status_slug = preg_replace('/^wc-/', '', $status_key); ?>
                                <option value="<?php echo esc_attr($status_slug); ?>" <?php selected(in_array($status_slug, (array)$s['excluded_statuses'], true)); ?>><?php echo esc_html($status_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">سفارش‌هایی با این وضعیت‌ها به گروه دوم منتقل نمی‌شوند. وضعیت‌هایی که عنوانشان شامل «کارت به کارت» باشد نیز به‌صورت خودکار نادیده گرفته می‌شوند.</p>
                    </td></tr>
                </table>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
            <hr>
            <h2>راه‌اندازی ربات جدید</h2>
            <p><strong>وب‌هوک:</strong> <code><?php echo esc_html($webhook_url); ?></code></p>
            <p>در گروه دوم روی پیام سفارش Reply کنید و <code>1✅</code> یا <code>1❌</code> بفرستید. افزونه در گروه اول روی پیام قدیمی ریپلای می‌کند و واکنش تنظیم‌شده را نیز ثبت می‌کند. سپس نتیجه را به وب‌هوک افزونه قدیمی می‌رساند تا منطق تکمیل سفارش و شمارنده دلاری همان افزونه اجرا شود.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px"><?php wp_nonce_field('igk_tg_test'); ?><input type="hidden" name="action" value="igk_tg_bridge_test"><?php submit_button('تست ربات جدید', 'secondary', 'submit', false); ?></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"><?php wp_nonce_field('igk_tg_webhook'); ?><input type="hidden" name="action" value="igk_tg_bridge_webhook"><?php submit_button('ثبت وب‌هوک', 'primary', 'submit', false); ?></form>
            <hr><h2>آخرین سفارش‌های پل ارتباطی</h2>
            <table class="widefat striped"><thead><tr><th>Order ID</th><th>پیام اصلی</th><th>پیام گروه دوم</th><th>وضعیت پل</th><th>دلار</th><th>آخرین خطا</th><th>عملیات</th></tr></thead><tbody>
            <?php if ($rows): foreach ($rows as $r): ?>
                <tr><td><?php echo (int)$r->order_id; ?></td><td><?php echo (int)$r->group1_message_id; ?></td><td><?php echo (int)$r->group2_message_id; ?></td><td><?php echo esc_html($r->status); ?></td><td><?php echo esc_html($r->dollar_amount); ?></td><td><?php echo esc_html($r->last_error); ?></td><td>
                <?php if ($r->status === 'error'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('igk_tg_retry_' . (int)$r->order_id); ?><input type="hidden" name="action" value="igk_tg_bridge_retry"><input type="hidden" name="order_id" value="<?php echo (int)$r->order_id; ?>"><button class="button">تلاش مجدد ارسال به گروه دوم</button></form><?php endif; ?>
                <?php if ($r->status === 'legacy_error'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('igk_tg_retry_legacy_' . (int)$r->order_id); ?><input type="hidden" name="action" value="igk_tg_bridge_retry_legacy"><input type="hidden" name="order_id" value="<?php echo (int)$r->order_id; ?>"><button class="button">تلاش مجدد ثبت نتیجه در افزونه قدیمی</button></form><?php endif; ?>
                </td></tr>
            <?php endforeach; else: ?><tr><td colspan="7">هنوز پیامی از افزونه قبلی دریافت نشده است.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    private function api($method, $params = []) {
        $s = $this->settings();
        if (empty($s['bot_token'])) return new WP_Error('no_token', 'توکن ربات جدید وارد نشده است.');
        $url = 'https://api.telegram.org/bot' . trim($s['bot_token']) . '/' . $method;
        $response = wp_remote_post($url, [
            'timeout' => 25,
            'redirection' => 2,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($params),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['ok'])) {
            return new WP_Error('telegram_api', !empty($data['description']) ? $data['description'] : 'خطا در ارتباط با تلگرام.');
        }
        return $data['result'] ?? true;
    }

    public function capture_existing_notifier_message($response, $args, $url) {
        if (is_wp_error($response) || !is_string($url)) return $response;
        if (strpos($url, 'https://api.telegram.org/bot') !== 0 || !preg_match('~/sendMessage(?:\?|$)~', $url)) return $response;
        $body = $args['body'] ?? [];
        if (is_string($body)) { $parsed = []; parse_str($body, $parsed); $body = $parsed; }
        if (!is_array($body)) return $response;
        $s = $this->settings();
        if (empty($s['bot_token']) || empty($s['group1_id']) || empty($s['group2_id'])) return $response;
        if (!isset($body['chat_id']) || (string)$body['chat_id'] !== (string)$s['group1_id']) return $response;
        $text = isset($body['text']) ? (string)$body['text'] : '';
        if (!preg_match('/Order\s*ID\s*:\s*(\d+)/i', $text, $order_match)) return $response;
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || empty($payload['ok']) || empty($payload['result']['message_id'])) return $response;
        $order_id = absint($order_match[1]);
        $source_message_id = absint($payload['result']['message_id']);
        if (!$order_id || !$source_message_id) return $response;
        // Ignore manually handled card-to-card orders and any statuses selected in WooCommerce settings.
        if ($this->is_excluded_order($order_id)) return $response;
        $this->bridge_captured_message($order_id, (string)$s['group1_id'], $source_message_id, $text);
        return $response;
    }

    private function is_excluded_order($order_id) {
        if (!function_exists('wc_get_order')) return false;
        $order = wc_get_order($order_id);
        if (!$order) return false;
        $status = sanitize_key($order->get_status());
        $settings = $this->settings();
        if (in_array($status, (array)($settings['excluded_statuses'] ?? []), true)) return true;
        $all_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $label = isset($all_statuses['wc-' . $status]) ? wp_strip_all_tags($all_statuses['wc-' . $status]) : $status;
        $normalized = preg_replace('/[\s\x{200c}\x{200f}\x{200e}]+/u', '', (string)$label);
        return (mb_stripos($normalized, 'کارتبهکارت') !== false) || (mb_stripos($normalized, 'کارتبهکارتدریافت') !== false);
    }

    private function bridge_captured_message($order_id, $group1_chat_id, $group1_message_id, $source_text) {
        global $wpdb;
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($this->table, [
            'order_id' => $order_id,
            'group1_chat_id' => $group1_chat_id,
            'group1_message_id' => $group1_message_id,
            'group2_chat_id' => (string)$this->settings()['group2_id'],
            'source_text' => $source_text,
            'status' => 'capturing',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%d','%s','%s','%s','%s','%s']);

        if (!$inserted) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $order_id));
            if (!$row || $row->status !== 'error') return;
            $claimed = $wpdb->update($this->table, [
                'status' => 'capturing', 'last_error' => '',
                'group1_chat_id' => $group1_chat_id,
                'group1_message_id' => $row->group1_message_id ? (int)$row->group1_message_id : $group1_message_id,
                'group2_chat_id' => (string)$this->settings()['group2_id'],
                'source_text' => $row->source_text ? $row->source_text : $source_text,
                'updated_at' => $now,
            ], ['id' => (int)$row->id, 'status' => 'error']);
            if ($claimed !== 1) return;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $order_id));
            $group1_message_id = (int)$row->group1_message_id;
            $source_text = (string)$row->source_text;
        }

        $s = $this->settings();
        $sent = $this->api('sendMessage', [
            'chat_id' => $s['group2_id'],
            'text' => $source_text,
            'disable_web_page_preview' => true,
        ]);
        if (is_wp_error($sent) || empty($sent['message_id'])) {
            $err = is_wp_error($sent) ? $sent->get_error_message() : 'شناسه پیام گروه دوم از تلگرام دریافت نشد.';
            $wpdb->update($this->table, ['status' => 'error', 'last_error' => $err, 'updated_at' => current_time('mysql', true)], ['order_id' => $order_id]);
            return;
        }
        $wpdb->update($this->table, [
            'group2_message_id' => (int)$sent['message_id'], 'status' => 'waiting',
            'last_error' => '', 'updated_at' => current_time('mysql', true),
        ], ['order_id' => $order_id]);
    }

    public function register_webhook_route() {
        register_rest_route(self::REST_NS, '/webhook', [
            'methods' => 'POST', 'callback' => [$this, 'receive_webhook'], 'permission_callback' => '__return_true',
        ]);
    }

    public function receive_webhook(WP_REST_Request $request) {
        $s = $this->settings();
        $secret = $request->get_header('x-telegram-bot-api-secret-token');
        if (empty($s['secret']) || !hash_equals((string)$s['secret'], (string)$secret)) return new WP_REST_Response(['ok' => false], 403);
        $update = $request->get_json_params();
        if (!is_array($update) || empty($update['update_id'])) return new WP_REST_Response(['ok' => true], 200);
        $msg = $update['message'] ?? null;
        if (!$msg || empty($msg['chat']['id']) || (string)$msg['chat']['id'] !== (string)$s['group2_id']) return new WP_REST_Response(['ok' => true], 200);
        if (empty($msg['reply_to_message']['message_id']) || empty($msg['from']['id'])) return new WP_REST_Response(['ok' => true], 200);

        $text = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));
        // Group 2 admin command: 1✅, 1 ✅, 1❌, 1 ❌, or just ❌.
        if (!preg_match('/^\s*(?:(\d+)\s*)?(✅|❌)\s*$/u', $text, $m)) return new WP_REST_Response(['ok' => true], 200);
        $emoji = $m[2];
        $amount = isset($m[1]) ? $m[1] : '';
        if ($emoji === '✅' && $amount === '') return new WP_REST_Response(['ok' => true], 200);

        $member = $this->api('getChatMember', ['chat_id' => $s['group2_id'], 'user_id' => (int)$msg['from']['id']]);
        if (is_wp_error($member) || !in_array($member['status'] ?? '', ['administrator', 'creator'], true)) return new WP_REST_Response(['ok' => true], 200);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE group2_message_id=%d AND group2_chat_id=%s", (int)$msg['reply_to_message']['message_id'], (string)$s['group2_id']));
        if (!$row || $row->status !== 'waiting') return new WP_REST_Response(['ok' => true], 200);
        $claimed = $wpdb->update($this->table, ['status' => 'processing_result', 'last_error' => '', 'updated_at' => current_time('mysql', true)], ['id' => (int)$row->id, 'status' => 'waiting']);
        if ($claimed !== 1) return new WP_REST_Response(['ok' => true], 200);

        // Required by the legacy notifier: it accepts "✅ 1" (not "1✅") as its incoming command.
        $result_text = ($emoji === '✅') ? ('✅ ' . $amount) : '❌';
        $reply = $this->api('sendMessage', [
            'chat_id' => $row->group1_chat_id ?: $s['group1_id'],
            'text' => $result_text,
            'reply_to_message_id' => (int)$row->group1_message_id,
            'allow_sending_without_reply' => false,
        ]);
        if (is_wp_error($reply) || empty($reply['message_id'])) {
            $err = is_wp_error($reply) ? $reply->get_error_message() : 'پیام ریپلای‌شده از تلگرام تأیید نشد.';
            $wpdb->update($this->table, ['status' => 'waiting', 'last_error' => $err, 'updated_at' => current_time('mysql', true)], ['id' => (int)$row->id, 'status' => 'processing_result']);
            return new WP_REST_Response(['ok' => false, 'error' => $err], 500);
        }

        // Apply an actual Telegram reaction to the original order message.
        $reaction = ($emoji === '✅') ? $s['complete_reaction'] : $s['failed_reaction'];
        $reaction_result = $this->api('setMessageReaction', [
            'chat_id' => $row->group1_chat_id ?: $s['group1_id'],
            'message_id' => (int)$row->group1_message_id,
            'reaction' => [['type' => 'emoji', 'emoji' => $reaction]],
            'is_big' => false,
        ]);
        $reaction_error = is_wp_error($reaction_result) ? $reaction_result->get_error_message() : '';

        // The old bot cannot receive messages authored by another bot in a group.
        // Relay the verified admin result to its existing /ott-webhook endpoint so its own
        // legacy handler updates WooCommerce and its dollar counter, without editing that plugin.
        $legacy = $this->notify_legacy_notifier($row, $result_text, (int)$reply['message_id'], (int)$msg['from']['id'], (int)$update['update_id']);
        if (is_wp_error($legacy)) {
            $wpdb->update($this->table, [
                'status' => 'legacy_error', 'dollar_amount' => $amount,
                'result_message_id' => (int)$reply['message_id'],
                'last_error' => trim($legacy->get_error_message() . ($reaction_error ? ' | واکنش: ' . $reaction_error : '')),
                'telegram_update_id' => (int)$update['update_id'], 'updated_at' => current_time('mysql', true),
            ], ['id' => (int)$row->id, 'status' => 'processing_result']);
            return new WP_REST_Response(['ok' => true, 'warning' => $legacy->get_error_message()], 200);
        }

        $wpdb->update($this->table, [
            'status' => ($emoji === '✅') ? 'completed' : 'failed',
            'dollar_amount' => $amount,
            'result_message_id' => (int)$reply['message_id'],
            'telegram_update_id' => (int)$update['update_id'],
            'last_error' => $reaction_error ? ('واکنش ثبت نشد: ' . $reaction_error) : '',
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int)$row->id, 'status' => 'processing_result']);
        return new WP_REST_Response(['ok' => true], 200);
    }

    private function notify_legacy_notifier($row, $result_text, $result_message_id, $admin_id, $update_id) {
        $endpoint = home_url('/ott-webhook');
        $payload = [
            'update_id' => (int)$update_id,
            'message' => [
                'message_id' => (int)$result_message_id,
                'from' => ['id' => (int)$admin_id, 'is_bot' => false, 'first_name' => 'Group admin'],
                'chat' => ['id' => (string)$row->group1_chat_id, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $result_text,
                'reply_to_message' => [
                    'message_id' => (int)$row->group1_message_id,
                    'chat' => ['id' => (string)$row->group1_chat_id, 'type' => 'supergroup'],
                    'text' => (string)$row->source_text,
                ],
            ],
        ];
        $response = wp_remote_post($endpoint, [
            'timeout' => 25,
            'redirection' => 2,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) return new WP_Error('legacy_webhook', 'ارتباط با وب‌هوک افزونه قدیمی برقرار نشد: ' . $response->get_error_message());
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return new WP_Error('legacy_webhook', 'وب‌هوک افزونه قدیمی پاسخ HTTP ' . $code . ' داد.');
        return true;
    }

    public function handle_test() {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer('igk_tg_test');
        $me = $this->api('getMe');
        $msg = is_wp_error($me) ? $me->get_error_message() : ('اتصال موفق؛ ربات: @' . ($me['username'] ?? 'unknown'));
        wp_safe_redirect(add_query_arg('igk_msg', rawurlencode($msg), admin_url('admin.php?page=igk-tg-bridge')));
        exit;
    }

    public function handle_webhook_setup() {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer('igk_tg_webhook');
        $s = $this->settings();
        $url = rest_url(self::REST_NS . '/webhook');
        $result = $this->api('setWebhook', [
            'url' => $url,
            'secret_token' => $s['secret'],
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
        ]);
        $msg = is_wp_error($result) ? $result->get_error_message() : 'وب‌هوک با موفقیت ثبت شد.';
        wp_safe_redirect(add_query_arg('igk_msg', rawurlencode($msg), admin_url('admin.php?page=igk-tg-bridge')));
        exit;
    }

    public function handle_retry() {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        $order_id = absint($_POST['order_id'] ?? 0);
        check_admin_referer('igk_tg_retry_' . $order_id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $order_id));
        if ($row && $row->status === 'error' && !empty($row->group1_message_id) && !empty($row->source_text)) {
            $this->bridge_captured_message($order_id, (string)$row->group1_chat_id, (int)$row->group1_message_id, (string)$row->source_text);
        }
        wp_safe_redirect(admin_url('admin.php?page=igk-tg-bridge'));
        exit;
    }

    public function handle_retry_legacy() {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        $order_id = absint($_POST['order_id'] ?? 0);
        check_admin_referer('igk_tg_retry_legacy_' . $order_id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id=%d", $order_id));
        if ($row && $row->status === 'legacy_error' && !empty($row->result_message_id)) {
            $text = ($row->dollar_amount !== '') ? ('✅ ' . $row->dollar_amount) : '❌';
            $retry = $this->notify_legacy_notifier($row, $text, (int)$row->result_message_id, 0, time());
            if (!is_wp_error($retry)) {
                $wpdb->update($this->table, ['status' => ($row->dollar_amount !== '') ? 'completed' : 'failed', 'last_error' => '', 'updated_at' => current_time('mysql', true)], ['id' => (int)$row->id]);
            } else {
                $wpdb->update($this->table, ['last_error' => $retry->get_error_message(), 'updated_at' => current_time('mysql', true)], ['id' => (int)$row->id]);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=igk-tg-bridge'));
        exit;
    }
}

IGK_Telegram_Order_Bridge::instance();
