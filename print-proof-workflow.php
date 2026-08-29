<?php
/**
 * Plugin Name: Print Product Workflow for WooCommerce
 * Description: Загрузка файла для товаров типографии, вебхук, статусы проверки/оплаты/утверждения макета, подтверждение макета клиентом.
 * Version: 1.4.0
 * Author: OpenAI
 * Requires Plugins: woocommerce
 * Text Domain: ppw
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PPW_Print_Product_Workflow')) {
    class PPW_Print_Product_Workflow {
        const PRODUCT_META_ENABLED = '_ppw_enable_print_workflow';
        const PRODUCT_META_CONFIG = '_ppw_configurator_json';
        const CART_KEY_CONFIG = 'ppw_configurator';
        const CART_KEY_PRICE = 'ppw_calculated_price';
        const ITEM_META_CONFIG = '_ppw_configurator_json';
        const CART_KEY_FILE = 'ppw_uploaded_file';
        const ITEM_META_URL = '_ppw_file_url';
        const ITEM_META_PATH = '_ppw_file_path';
        const ITEM_META_NAME = '_ppw_file_name';
        const ITEM_META_REVIEW_STATUS = '_ppw_file_review_status';
        const ITEM_META_REVIEW_NOTE = '_ppw_file_review_note';
        const ITEM_META_PROOF_URL = '_ppw_item_proof_url';
        const ITEM_META_REVIEWED_AT = '_ppw_file_reviewed_at';
        // Public (non-underscored) keys are intentionally visible in WooCommerce REST line_items.meta_data.
        // `isPDFCheked` keeps the spelling used by the print-service integration contract.
        const ITEM_META_IS_PDF_CHECKED = 'isPDFCheked';
        const ITEM_META_PDF_CHECK_RESULT = 'pdfCheckResult';
        const ORDER_META_PROOF_URL = '_ppw_proof_file_url';
        const ORDER_META_PROOF_NOTE = '_ppw_proof_note';
        const OPTION_WEBHOOK_URL = 'ppw_webhook_url';
        const OPTION_WEBHOOK_SECRET = 'ppw_webhook_secret';

        public function __construct() {
            add_action('init', [$this, 'register_statuses']);
            add_filter('wc_order_statuses', [$this, 'register_statuses_in_list']);
            add_action('init', [$this, 'handle_customer_proof_approval']);
            add_action('init', [$this, 'handle_customer_file_workflow_action']);
            add_action('rest_api_init', [$this, 'register_review_callback_endpoint']);

            add_action('add_meta_boxes_product', [$this, 'add_product_metabox']);
            add_action('save_post_product', [$this, 'save_product_metabox']);

            add_action('woocommerce_before_add_to_cart_button', [$this, 'render_upload_field']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
            add_filter('body_class', [$this, 'add_print_product_body_class']);
            add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_upload_field'], 10, 3);
            add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
            add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
            add_action('woocommerce_before_calculate_totals', [$this, 'apply_calculated_cart_prices'], 40);

            add_filter('woocommerce_checkout_fields', [$this, 'disable_billing_address_fields']);
            add_filter('woocommerce_billing_fields', [$this, 'disable_billing_address_field_group']);
            add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'hide_customer_file_url_meta'], 10, 2);

            add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_line_item_meta'], 10, 4);
            add_action('woocommerce_checkout_order_processed', [$this, 'set_initial_status_and_send_webhook'], 20, 3);
            add_action('woocommerce_order_item_meta_end', [$this, 'render_order_item_file_meta'], 10, 3);

            add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'allow_payment_for_custom_status'], 10, 2);
            add_filter('woocommerce_order_needs_payment', [$this, 'order_needs_payment_for_custom_status'], 10, 3);
            add_filter('woocommerce_cart_needs_payment', [$this, 'disable_initial_payment_for_print_orders'], 10, 2);

            add_action('add_meta_boxes_shop_order', [$this, 'add_order_metabox']);
            add_action('save_post_shop_order', [$this, 'save_order_metabox'], 10, 2);
            add_action('woocommerce_view_order', [$this, 'render_customer_proof_box']);
            add_action('woocommerce_thankyou', [$this, 'render_thankyou_message'], 15);
            add_action('woocommerce_email_after_order_table', [$this, 'render_email_info'], 10, 4);
            add_action('woocommerce_order_status_changed', [$this, 'maybe_send_proof_email'], 10, 4);
            add_action('woocommerce_order_status_changed', [$this, 'maybe_send_payment_ready_email'], 20, 4);

            add_action('admin_menu', [$this, 'add_settings_page']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('wp_ajax_ppw_ajax_add_to_cart', [$this, 'ajax_add_to_cart']);
            add_action('wp_ajax_nopriv_ppw_ajax_add_to_cart', [$this, 'ajax_add_to_cart']);
            add_action('woocommerce_add_to_cart', [$this, 'prevent_duplicate_plain_add'], 20, 6);
            add_action('woocommerce_cart_loaded_from_session', [$this, 'cleanup_duplicate_plain_items'], 20);
            add_action('woocommerce_before_calculate_totals', [$this, 'cleanup_duplicate_plain_items'], 20);

            // Для типографских товаров количество WooCommerce всегда равно 1.
            // Фактическое количество изделий передается параметром конфигуратора «Тираж».
            add_filter('woocommerce_add_to_cart_quantity', [$this, 'force_print_product_quantity'], 10, 2);
            add_filter('woocommerce_cart_item_quantity', [$this, 'hide_print_product_cart_quantity'], 10, 3);
            add_filter('woocommerce_checkout_cart_item_quantity', [$this, 'hide_print_product_checkout_quantity'], 10, 3);
            add_action('woocommerce_cart_loaded_from_session', [$this, 'force_cart_quantities_to_one'], 30);
            add_action('woocommerce_before_calculate_totals', [$this, 'force_cart_quantities_to_one'], 30);
        }



        public function enqueue_frontend_assets() {
            if (!function_exists('is_product') || !is_product()) {
                return;
            }

            $product_id = $this->get_current_product_id();
            if (!$product_id || !$this->product_requires_file($product_id)) {
                return;
            }

            wp_enqueue_style('ppw-frontend', plugin_dir_url(__FILE__) . 'assets/ppw.css', [], '1.4.0');
            wp_enqueue_script('ppw-frontend', plugin_dir_url(__FILE__) . 'assets/ppw.js', [], '1.4.0', true);

            $config = $this->get_product_config($product_id);

            wp_localize_script('ppw-frontend', 'ppwAjax', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ppw_ajax_add_to_cart'),
                'config'   => $config,
            ]);
        }


        public function add_print_product_body_class($classes) {
            if (!function_exists('is_product') || !is_product()) {
                return $classes;
            }

            $product_id = $this->get_current_product_id();
            if ($product_id && $this->product_requires_file($product_id)) {
                $classes[] = 'ppw-print-product';
            }

            return $classes;
        }

        public function register_statuses() {
            register_post_status('wc-file-review', [
                'label'                     => 'На проверке файла',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('На проверке файла <span class="count">(%s)</span>', 'На проверке файла <span class="count">(%s)</span>', 'ppw'),
            ]);

            register_post_status('wc-awaiting-payment', [
                'label'                     => 'Ожидает оплаты',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('Ожидает оплаты <span class="count">(%s)</span>', 'Ожидает оплаты <span class="count">(%s)</span>', 'ppw'),
            ]);

            register_post_status('wc-awaiting-proof', [
                'label'                     => 'Ожидает утверждения макета',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('Ожидает утверждения макета <span class="count">(%s)</span>', 'Ожидает утверждения макета <span class="count">(%s)</span>', 'ppw'),
            ]);
        }

        public function register_statuses_in_list($statuses) {
            $new_statuses = [];
            foreach ($statuses as $key => $label) {
                $new_statuses[$key] = $label;
                if ('wc-pending' === $key) {
                    $new_statuses['wc-file-review'] = 'На проверке файла';
                    $new_statuses['wc-awaiting-payment'] = 'Ожидает оплаты';
                    $new_statuses['wc-awaiting-proof'] = 'Ожидает утверждения макета';
                }
            }
            return $new_statuses;
        }



        public function ajax_add_to_cart() {
            if (!check_ajax_referer('ppw_ajax_add_to_cart', 'security', false)) {
                wp_send_json_error([
                    'message' => 'Ошибка безопасности. Обновите страницу и попробуйте снова.',
                ], 403);
            }

            if (!function_exists('WC') || !WC()->cart) {
                wp_send_json_error([
                    'message' => 'Корзина WooCommerce недоступна.',
                ], 500);
            }

            wc_clear_notices();

            $product_id   = 0;
            if (isset($_POST['add-to-cart'])) {
                $product_id = absint(wp_unslash($_POST['add-to-cart']));
            } elseif (isset($_POST['product_id'])) {
                $product_id = absint(wp_unslash($_POST['product_id']));
            }

            if (!$product_id && function_exists('get_queried_object_id')) {
                $product_id = absint(get_queried_object_id());
            }
            $quantity     = $this->product_requires_file($product_id) ? 1 : (isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : 1);
            $variation_id = isset($_POST['variation_id']) ? absint(wp_unslash($_POST['variation_id'])) : 0;
            $variations   = [];

            foreach ($_POST as $key => $value) {
                if (0 === strpos((string) $key, 'attribute_')) {
                    $variations[sanitize_text_field(wp_unslash($key))] = sanitize_text_field(wp_unslash($value));
                }
            }

            if (!$product_id) {
                wp_send_json_error([
                    'message' => 'Не удалось определить товар.',
                ], 400);
            }

            $passed = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations);
            if (!$passed) {
                $message = wc_print_notices(true);
                wc_clear_notices();
                wp_send_json_error([
                    'message' => wp_strip_all_tags($message),
                    'notices' => $message,
                ], 400);
            }

            $added_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variations);

            if (!$added_key) {
                $message = wc_print_notices(true);
                wc_clear_notices();
                wp_send_json_error([
                    'message' => $message ? wp_strip_all_tags($message) : 'Не удалось добавить товар в корзину.',
                    'notices' => $message,
                ], 400);
            }

            if (WC()->session) {
                WC()->session->set('ppw_recent_ajax_add', [
                    'time'         => time(),
                    'product_id'   => (int) $product_id,
                    'variation_id' => (int) $variation_id,
                    'cart_item_key'=> (string) $added_key,
                ]);
            }

            do_action('woocommerce_ajax_added_to_cart', $product_id);

            ob_start();
            woocommerce_mini_cart();
            $mini_cart = ob_get_clean();

            $data = [
                'message'   => 'Товар добавлен в корзину.',
                'cart_hash' => WC()->cart->get_cart_hash(),
                'fragments' => apply_filters('woocommerce_add_to_cart_fragments', [
                    'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
                ]),
            ];

            wc_clear_notices();
            wp_send_json_success($data);
        }


        public function prevent_duplicate_plain_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
            if (!function_exists('WC') || !WC()->cart || !WC()->session) {
                return;
            }

            $recent = WC()->session->get('ppw_recent_ajax_add');
            if (empty($recent) || !is_array($recent)) {
                return;
            }

            $recent_time = isset($recent['time']) ? absint($recent['time']) : 0;
            if (!$recent_time || (time() - $recent_time) > 10) {
                WC()->session->__unset('ppw_recent_ajax_add');
                return;
            }

            $target_product_id = $variation_id ?: $product_id;
            if (
                absint($recent['product_id']) !== absint($product_id) ||
                absint($recent['variation_id']) !== absint($variation_id)
            ) {
                return;
            }

            if (
                !$this->product_requires_file($target_product_id) &&
                !$this->product_requires_file($product_id)
            ) {
                return;
            }

            $cart_item = WC()->cart->get_cart_item($cart_item_key);
            $has_file = !empty($cart_item_data[self::CART_KEY_FILE]) || !empty($cart_item[self::CART_KEY_FILE]);

            if ($has_file) {
                return;
            }

            if (!empty($recent['cart_item_key']) && $recent['cart_item_key'] === $cart_item_key) {
                return;
            }

            WC()->cart->remove_cart_item($cart_item_key);
            WC()->session->__unset('ppw_recent_ajax_add');
        }


public function cleanup_duplicate_plain_items() {
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $cart = WC()->cart;
    $cart_contents = $cart->get_cart();
    if (empty($cart_contents) || !is_array($cart_contents)) {
        return;
    }

    $groups = [];
    foreach ($cart_contents as $cart_item_key => $cart_item) {
        $product_id   = !empty($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
        $variation_id = !empty($cart_item['variation_id']) ? absint($cart_item['variation_id']) : 0;
        $target_id    = $variation_id ?: $product_id;

        if (!$target_id) {
            continue;
        }

        if (!$this->product_requires_file($target_id) && !$this->product_requires_file($product_id)) {
            continue;
        }

        $group_key = $product_id . ':' . $variation_id;

        if (!isset($groups[$group_key])) {
            $groups[$group_key] = [
                'with_file'    => [],
                'without_file' => [],
            ];
        }

        $has_file = !empty($cart_item[self::CART_KEY_FILE]) ||
            !empty($cart_item[self::ITEM_META_URL]) ||
            !empty($cart_item[self::ITEM_META_NAME]) ||
            !empty($cart_item[self::ITEM_META_PATH]);

        if ($has_file) {
            $groups[$group_key]['with_file'][] = $cart_item_key;
        } else {
            $groups[$group_key]['without_file'][] = $cart_item_key;
        }
    }

    foreach ($groups as $group) {
        if (empty($group['with_file']) || empty($group['without_file'])) {
            continue;
        }

        foreach ($group['without_file'] as $cart_item_key) {
            $cart->remove_cart_item($cart_item_key);
        }
    }
}

        public function force_print_product_quantity($quantity, $product_id) {
            return $this->product_requires_file((int) $product_id) ? 1 : $quantity;
        }

        public function hide_print_product_cart_quantity($product_quantity, $cart_item_key, $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) ($cart_item['product_id'] ?? 0);
            $parent_id  = (int) ($cart_item['product_id'] ?? 0);

            if ($this->product_requires_file($product_id) || $this->product_requires_file($parent_id)) {
                return '';
            }

            return $product_quantity;
        }

        public function hide_print_product_checkout_quantity($product_quantity, $cart_item, $cart_item_key) {
            $product_id = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) ($cart_item['product_id'] ?? 0);
            $parent_id  = (int) ($cart_item['product_id'] ?? 0);

            if ($this->product_requires_file($product_id) || $this->product_requires_file($parent_id)) {
                return '';
            }

            return $product_quantity;
        }

        public function force_cart_quantities_to_one($cart = null) {
            if (!$cart && function_exists('WC')) {
                $cart = WC()->cart;
            }
            if (!$cart || !method_exists($cart, 'get_cart')) {
                return;
            }

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) ($cart_item['product_id'] ?? 0);
                $parent_id  = (int) ($cart_item['product_id'] ?? 0);

                if (($this->product_requires_file($product_id) || $this->product_requires_file($parent_id)) && (int) $cart_item['quantity'] !== 1) {
                    $cart->set_quantity($cart_item_key, 1, false);
                }
            }
        }

        public function add_product_metabox() {
            add_meta_box(
                'ppw_product_metabox',
                'Настройки типографии',
                [$this, 'render_product_metabox'],
                'product',
                'normal',
                'default'
            );
        }

        public function render_product_metabox($post) {
            wp_nonce_field('ppw_save_product_metabox', 'ppw_product_metabox_nonce');
            $enabled = get_post_meta($post->ID, self::PRODUCT_META_ENABLED, true);
            $config_json = get_post_meta($post->ID, self::PRODUCT_META_CONFIG, true);
            ?>
            <p>
                <label>
                    <input type="checkbox" name="ppw_enable_print_workflow" value="1" <?php checked($enabled, '1'); ?>>
                    Включить загрузку файла и workflow типографии
                </label>
            </p>
            <p style="color:#666;">Для таких товаров на странице товара появится загрузка файла, а заказ после оформления будет переведен в статус «На проверке файла».</p>
            <hr>
            <p><strong>JSON-конфигуратор параметров печати</strong></p>
            <p style="color:#666;">Вставьте JSON в формате <code>{"params":[...]}</code>. Параметр <code>hidden: true</code> не показывается пользователю, но сохраняется и передаётся в webhook. Если поле пустое, конфигуратор не выводится.</p>
            <textarea name="ppw_configurator_json" rows="16" style="width:100%;font-family:monospace;" placeholder='{"params":[...]}' ><?php echo esc_textarea($config_json); ?></textarea>
            <?php
        }

        public function save_product_metabox($post_id) {
            if (!isset($_POST['ppw_product_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ppw_product_metabox_nonce'])), 'ppw_save_product_metabox')) {
                return;
            }
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }
            update_post_meta($post_id, self::PRODUCT_META_ENABLED, isset($_POST['ppw_enable_print_workflow']) ? '1' : '0');

            $raw_config = isset($_POST['ppw_configurator_json']) ? wp_unslash($_POST['ppw_configurator_json']) : '';
            $raw_config = trim((string) $raw_config);

            if ('' === $raw_config) {
                delete_post_meta($post_id, self::PRODUCT_META_CONFIG);
            } else {
                $decoded = json_decode($raw_config, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['params']) && is_array($decoded['params'])) {
                    update_post_meta($post_id, self::PRODUCT_META_CONFIG, wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } else {
                    update_post_meta($post_id, self::PRODUCT_META_CONFIG, $raw_config);
                }
            }
        }

        private function get_product_config($product_id) {
            $raw = trim((string) get_post_meta($product_id, self::PRODUCT_META_CONFIG, true));
            if ('' === $raw) {
                return null;
            }

            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded['params']) || !is_array($decoded['params'])) {
                return null;
            }

            return $decoded;
        }

        private function product_requires_file($product_id) {
            return $product_id > 0 && '1' === get_post_meta($product_id, self::PRODUCT_META_ENABLED, true);
        }

        private function get_current_product_id() {
            global $product;

            if (is_object($product) && method_exists($product, 'get_id')) {
                return (int) $product->get_id();
            }

            $queried_id = get_queried_object_id();
            if ($queried_id) {
                $queried_product = wc_get_product($queried_id);
                if ($queried_product) {
                    return (int) $queried_product->get_id();
                }
            }

            if (get_the_ID()) {
                $loop_product = wc_get_product(get_the_ID());
                if ($loop_product) {
                    return (int) $loop_product->get_id();
                }
            }

            return 0;
        }

        public function render_upload_field() {
            $product_id = $this->get_current_product_id();
            if (!$product_id || !$this->product_requires_file($product_id)) {
                return;
            }
            $config = $this->get_product_config($product_id);
            wp_nonce_field('ppw_upload_file', 'ppw_upload_nonce');
            ?>
            <?php if ($config) : ?>
                <div class="ppw-configurator" data-ppw-configurator></div>
                <input type="hidden" name="ppw_config" value="">
            <?php endif; ?>
            <input type="hidden" name="ppw_calculated_price" value="">
            <div class="ppw-upload-field">
                <label class="ppw-upload-field__label" for="ppw_print_file"><strong>Файл для печати</strong></label>
                <input type="file" id="ppw_print_file" name="ppw_print_file" accept=".pdf,.ai,.psd,.png,.jpg,.jpeg" required>
                <p class="ppw-upload-field__hint">Поддерживаются PDF, AI, PSD, PNG, JPG, JPEG. После оформления заказ попадет на проверку файла.</p>
            </div>
            <button type="button" class="button alt ppw-submit-workflow">Отправить на расчёт</button>
            <?php
        }

        public function validate_upload_field($passed, $product_id, $quantity) {
            if (!$this->product_requires_file($product_id)) {
                return $passed;
            }

            if (!isset($_POST['ppw_upload_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ppw_upload_nonce'])), 'ppw_upload_file')) {
                wc_add_notice('Ошибка проверки формы загрузки файла. Обновите страницу и попробуйте снова.', 'error');
                return false;
            }

            if (empty($_FILES['ppw_print_file']['name'])) {
                wc_add_notice('Пожалуйста, загрузите файл для печати.', 'error');
                return false;
            }

            $config_schema = $this->get_product_config($product_id);
            if ($config_schema) {
                $posted_config = isset($_POST['ppw_config']) ? wp_unslash($_POST['ppw_config']) : '';
                $decoded_config = json_decode((string) $posted_config, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_config) || empty($decoded_config['params']) || !is_array($decoded_config['params'])) {
                    wc_add_notice('Заполните параметры печати.', 'error');
                    return false;
                }

                $decoded_config = $this->normalize_config_payload($decoded_config);

                $errors = $this->validate_config_params($decoded_config['params']);
                if (!empty($errors)) {
                    wc_add_notice(reset($errors), 'error');
                    return false;
                }

                $_POST['ppw_config'] = wp_json_encode($decoded_config, JSON_UNESCAPED_UNICODE);
            }

            return $passed;
        }

       public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    $target_product_id = $variation_id ?: $product_id;

    if (!$this->product_requires_file($target_product_id) && !$this->product_requires_file($product_id)) {
        return $cart_item_data;
    }

    if (empty($_FILES['ppw_print_file']['name'])) {
        return $cart_item_data;
    }

    $uploaded = $this->handle_upload($_FILES['ppw_print_file']);

    if (is_wp_error($uploaded)) {
        wc_add_notice($uploaded->get_error_message(), 'error');
        return $cart_item_data;
    }

    $cart_item_data[self::CART_KEY_FILE] = $uploaded;

    if (!empty($_POST['ppw_config'])) {
        $config = json_decode((string) wp_unslash($_POST['ppw_config']), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($config) && !empty($config['params'])) {
            $cart_item_data[self::CART_KEY_CONFIG] = $this->sanitize_config_payload($config);
        }
    }

    if (isset($_POST['ppw_calculated_price'])) {
        $calculated_price = wc_format_decimal(wp_unslash($_POST['ppw_calculated_price']));
        if ('' !== $calculated_price && is_numeric($calculated_price) && (float) $calculated_price >= 0) {
            $cart_item_data[self::CART_KEY_PRICE] = (float) $calculated_price;
        }
    }

    $cart_item_data['unique_key'] = md5(wp_json_encode($uploaded) . wp_json_encode($cart_item_data[self::CART_KEY_CONFIG] ?? []) . microtime(true));

    /**
     * Кастомное событие для вебхука WooCommerce.
     *
     * В WooCommerce → Настройки → Вебзацепы:
     * Тема: Действие
     * Событие действия: ppw_print_file_uploaded
     */
    do_action('ppw_print_file_uploaded', [
        'event'        => 'print_file_uploaded',
        'product_id'   => $product_id,
        'variation_id' => $variation_id,
        'user_id'      => get_current_user_id(),
        'file_url'     => $uploaded['url'],
        'file_path'    => $uploaded['file'],
        'file_name'    => $uploaded['name'],
        'file_type'    => $uploaded['type'],
        'config'       => $cart_item_data[self::CART_KEY_CONFIG] ?? null,
        'created_at'   => current_time('mysql'),
    ]);

    return $cart_item_data;
}

        public function apply_calculated_cart_prices($cart) {
            if (!$cart || !method_exists($cart, 'get_cart')) {
                return;
            }

            foreach ($cart->get_cart() as $cart_item) {
                if (!isset($cart_item[self::CART_KEY_PRICE]) || !isset($cart_item['data']) || !is_object($cart_item['data'])) {
                    continue;
                }

                $cart_item['data']->set_price((float) $cart_item[self::CART_KEY_PRICE]);
            }
        }

        public function disable_billing_address_fields($fields) {
            if (isset($fields['billing'])) {
                $fields['billing'] = $this->disable_billing_address_field_group($fields['billing']);
            }

            return $fields;
        }

        public function disable_billing_address_field_group($fields) {
            $address_fields = ['billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode'];

            foreach ($address_fields as $field_name) {
                unset($fields[$field_name]);
            }

            return $fields;
        }

        public function hide_customer_file_url_meta($formatted_meta, $item) {
            $hidden_keys = ['Файл для печати', self::ITEM_META_IS_PDF_CHECKED, self::ITEM_META_PDF_CHECK_RESULT];
            foreach ($formatted_meta as $meta_id => $meta) {
                if (isset($meta->key) && in_array($meta->key, $hidden_keys, true)) {
                    unset($formatted_meta[$meta_id]);
                }
            }

            return $formatted_meta;
        }

        private function handle_upload($file) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $allowed = [
                'pdf'  => 'application/pdf',
                'ai'   => 'application/postscript',
                'psd'  => 'image/vnd.adobe.photoshop',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
            ];

            $overrides = [
                'test_form' => false,
                'mimes'     => $allowed,
            ];

            $upload = wp_handle_upload($file, $overrides);
            if (isset($upload['error'])) {
                return new WP_Error('ppw_upload_error', $upload['error']);
            }

            return [
                'url'  => $upload['url'],
                'file' => $upload['file'],
                'name' => basename($upload['file']),
                'type' => $upload['type'],
            ];
        }

        public function display_cart_item_data($item_data, $cart_item) {
            if (!empty($cart_item[self::CART_KEY_CONFIG]['params'])) {
                $item_data[] = [
                    'name'  => 'Параметры печати',
                    'value' => wp_kses_post($this->render_config_summary($cart_item[self::CART_KEY_CONFIG], false)),
                ];
            }

            return $item_data;
        }

        public function save_line_item_meta($item, $cart_item_key, $values, $order) {
            if (empty($values[self::CART_KEY_FILE])) {
                return;
            }
            $file = $values[self::CART_KEY_FILE];
            $item->add_meta_data(self::ITEM_META_URL, esc_url_raw($file['url']), true);
            $item->add_meta_data(self::ITEM_META_PATH, sanitize_text_field($file['file']), true);
            $item->add_meta_data(self::ITEM_META_NAME, sanitize_file_name($file['name']), true);
            $item->add_meta_data(self::ITEM_META_REVIEW_STATUS, 'pending', true);
            $item->add_meta_data(self::ITEM_META_REVIEW_NOTE, '', true);
            $item->add_meta_data(self::ITEM_META_PROOF_URL, '', true);
            $item->add_meta_data(self::ITEM_META_IS_PDF_CHECKED, 'False', true);
            $item->add_meta_data(self::ITEM_META_PDF_CHECK_RESULT, 'pending', true);
            if (!empty($values[self::CART_KEY_CONFIG])) {
                $item->add_meta_data(self::ITEM_META_CONFIG, wp_json_encode($values[self::CART_KEY_CONFIG], JSON_UNESCAPED_UNICODE), true);
                $item->add_meta_data('Параметры печати', $this->render_config_summary($values[self::CART_KEY_CONFIG], false), true);
            }
        }



        private function validate_config_params($params, &$errors = []) {
            foreach ((array) $params as $param) {
                if (!is_array($param)) {
                    continue;
                }

                $type = isset($param['type']) ? (string) $param['type'] : '';
                $label = isset($param['label']) ? (string) $param['label'] : 'Параметр';
                $required = !empty($param['required']);
                $visible_value = array_key_exists('value', $param) ? $param['value'] : null;

                if ($required) {
                    if (in_array($type, ['radio', 'select', 'number'], true) && null === $visible_value) {
                        $errors[] = sprintf('Заполните поле «%s».', $label);
                    }
                    if ('checkbox' === $type && null === $visible_value) {
                        $errors[] = sprintf('Выберите значение для поля «%s».', $label);
                    }
                    if ('multi-select' === $type && (null === $visible_value || !is_array($visible_value) || empty($visible_value))) {
                        $errors[] = sprintf('Выберите хотя бы один вариант в поле «%s».', $label);
                    }
                }

                if ('number' === $type && null !== $visible_value) {
                    if (!is_numeric($visible_value)) {
                        $errors[] = sprintf('Поле «%s» должно быть числом.', $label);
                    } else {
                        $number = (float) $visible_value;
                        if (isset($param['min']) && null !== $param['min'] && $number < (float) $param['min']) {
                            $errors[] = sprintf('Поле «%s» меньше минимального значения.', $label);
                        }
                        if (isset($param['max']) && null !== $param['max'] && $number > (float) $param['max']) {
                            $errors[] = sprintf('Поле «%s» больше максимального значения.', $label);
                        }
                    }
                }

                if (!empty($param['children']) && is_array($param['children'])) {
                    $this->validate_config_params($param['children'], $errors);
                }

                if (!empty($param['options']) && is_array($param['options'])) {
                    foreach ($param['options'] as $option) {
                        if (!empty($option['children']) && is_array($option['children'])) {
                            $this->validate_config_params($option['children'], $errors);
                        }
                    }
                }
            }

            return $errors;
        }

        private function sanitize_config_payload($config) {
            $config = $this->normalize_config_payload($config);
            return json_decode(wp_json_encode($config), true);
        }

        private function normalize_config_payload($config) {
            if (!is_array($config)) {
                return $config;
            }

            if (!empty($config['params']) && is_array($config['params'])) {
                $config['params'] = $this->normalize_config_params($config['params']);
            }

            return $config;
        }

        private function normalize_config_params($params) {
            foreach ((array) $params as $index => $param) {
                if (!is_array($param)) {
                    continue;
                }

                $type = isset($param['type']) ? (string) $param['type'] : '';

                if ('number' === $type && array_key_exists('value', $param) && '' !== $param['value'] && null !== $param['value'] && is_numeric($param['value'])) {
                    $params[$index]['value'] = $this->normalize_number_value(
                        $param['value'],
                        $param['min'] ?? null,
                        $param['max'] ?? null,
                        $param['step'] ?? 1
                    );
                }

                if (!empty($param['children']) && is_array($param['children'])) {
                    $params[$index]['children'] = $this->normalize_config_params($param['children']);
                }

                if (!empty($param['options']) && is_array($param['options'])) {
                    foreach ($param['options'] as $option_index => $option) {
                        if (!empty($option['children']) && is_array($option['children'])) {
                            $params[$index]['options'][$option_index]['children'] = $this->normalize_config_params($option['children']);
                        }
                    }
                }
            }

            return $params;
        }

        private function normalize_number_value($value, $min = null, $max = null, $step = 1) {
            $number = (float) $value;
            $min_number = (null !== $min && '' !== $min && is_numeric($min)) ? (float) $min : 0.0;
            $max_number = (null !== $max && '' !== $max && is_numeric($max)) ? (float) $max : null;
            $step_number = (null !== $step && '' !== $step && is_numeric($step) && (float) $step > 0) ? (float) $step : 1.0;

            if ($number < $min_number) {
                $number = $min_number;
            }

            if (null !== $max_number && $number > $max_number) {
                $number = $max_number;
            }

            $number = $min_number + floor(($number - $min_number) / $step_number) * $step_number;

            if (abs($number - round($number)) < 0.000001) {
                return (int) round($number);
            }

            return $number;
        }

        private function render_config_summary($config, $as_list = true) {
            if (empty($config['params']) || !is_array($config['params'])) {
                return '';
            }

            $rows = [];
            $this->collect_config_rows($config['params'], $rows);

            if (empty($rows)) {
                return '';
            }

            if ($as_list) {
                $html = '<ul class="ppw-config-summary">';
                foreach ($rows as $row) {
                    $html .= '<li><strong>' . esc_html($row['label']) . ':</strong> ' . esc_html($row['value']) . '</li>';
                }
                $html .= '</ul>';
                return $html;
            }

            $parts = [];
            foreach ($rows as $row) {
                $parts[] = '<strong>' . esc_html($row['label']) . ':</strong> ' . esc_html($row['value']);
            }
            return implode('<br>', $parts);
        }

        private function collect_config_rows($params, &$rows) {
            foreach ((array) $params as $param) {
                if (!is_array($param)) {
                    continue;
                }

                if (!empty($param['hidden'])) {
                    continue;
                }

                $label = isset($param['label']) ? (string) $param['label'] : '';
                $type = isset($param['type']) ? (string) $param['type'] : '';
                $value = array_key_exists('value', $param) ? $param['value'] : null;

                if ($label && null !== $value && [] !== $value && '' !== $value) {
                    $rows[] = [
                        'label' => $label,
                        'value' => $this->format_config_value($param),
                    ];
                }

                if (!empty($param['children']) && is_array($param['children'])) {
                    $this->collect_config_rows($param['children'], $rows);
                }

                if (in_array($type, ['radio', 'select'], true) && !empty($param['options']) && is_array($param['options'])) {
                    foreach ($param['options'] as $option) {
                        if (isset($option['value']) && (string) $option['value'] === (string) $value && !empty($option['children'])) {
                            $this->collect_config_rows($option['children'], $rows);
                        }
                    }
                }
            }
        }

        private function format_config_value($param) {
            $type = isset($param['type']) ? (string) $param['type'] : '';
            $value = $param['value'] ?? null;

            if ('checkbox' === $type) {
                return $value ? 'Да' : 'Нет';
            }

            if ('multi-select' === $type && is_array($value)) {
                $labels = [];
                foreach ((array) ($param['options'] ?? []) as $option) {
                    if (isset($option['value']) && in_array((string) $option['value'], array_map('strval', $value), true)) {
                        $labels[] = $option['label'] ?? $option['value'];
                    }
                }
                return implode(', ', $labels ?: $value);
            }

            if (in_array($type, ['radio', 'select'], true)) {
                foreach ((array) ($param['options'] ?? []) as $option) {
                    if (isset($option['value']) && (string) $option['value'] === (string) $value) {
                        return (string) ($option['label'] ?? $value);
                    }
                }
            }

            if ('number' === $type) {
                $unit = !empty($param['unit']) ? ' ' . $param['unit'] : '';
                return (string) $value . $unit;
            }

            return is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        public function set_initial_status_and_send_webhook($order_id, $posted_data, $order) {
            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order_id);
            }
            if (!$order) {
                return;
            }

            $has_print_items = false;
            $file_urls = [];
            foreach ($order->get_items() as $item) {
                $url = $item->get_meta(self::ITEM_META_URL, true);
                if ($url) {
                    $has_print_items = true;
                    $file_urls[] = $url;
                }
            }

            if (!$has_print_items) {
                return;
            }

            if (!$order->has_status('file-review')) {
                $order->update_status('file-review', 'Заказ автоматически переведен в статус «На проверке файла».');
            }

            $this->send_webhook($order, $file_urls);
        }

        private function send_webhook($order, $file_urls) {
            $webhook_url = trim((string) get_option(self::OPTION_WEBHOOK_URL, ''));
            if (!$webhook_url) {
                return;
            }

            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = [
                    'item_id'      => $item->get_id(),
                    'product_id'   => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'name'         => $item->get_name(),
                    'quantity'     => $item->get_quantity(),
                    'total'        => $item->get_total(),
                    'file_url'     => $item->get_meta(self::ITEM_META_URL, true),
                    'file_name'    => $item->get_meta(self::ITEM_META_NAME, true),
                    'isPDFCheked'  => $this->is_item_pdf_checked($item),
                    'pdfCheckResult' => $this->get_item_review_status($item),
                    'review_status'=> $this->get_item_review_status($item),
                    'config'       => json_decode((string) $item->get_meta(self::ITEM_META_CONFIG, true), true),
                ];
            }

            $payload = [
                'event'        => 'print_order_created',
                'order_id'     => $order->get_id(),
                'order_key'    => $order->get_order_key(),
                'status'       => $order->get_status(),
                'currency'     => $order->get_currency(),
                'total'        => $order->get_total(),
                'customer'     => [
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                ],
                'file_urls'    => array_values(array_filter($file_urls)),
                'items'        => $items,
                'created_at'   => current_time('mysql'),
            ];

            $headers = ['Content-Type' => 'application/json'];
            $secret = trim((string) get_option(self::OPTION_WEBHOOK_SECRET, ''));
            if ($secret) {
                $headers['X-PPW-Secret'] = $secret;
            }

            wp_remote_post($webhook_url, [
                'timeout' => 20,
                'headers' => $headers,
                'body'    => wp_json_encode($payload),
            ]);
        }

        public function render_order_item_file_meta($item_id, $item, $order) {
            if (!is_account_page() && !is_order_received_page()) {
                return;
            }
            $url = $item->get_meta(self::ITEM_META_URL, true);
            $name = $item->get_meta(self::ITEM_META_NAME, true);
            if (!$url) {
                return;
            }
            echo '<p><strong>Файл для печати:</strong> ' . esc_html($name ?: basename($url)) . '</p>';

            $status = $this->get_item_review_status($item);
            $note = (string) $item->get_meta(self::ITEM_META_REVIEW_NOTE, true);
            $proof_url = (string) $item->get_meta(self::ITEM_META_PROOF_URL, true);
            echo '<p><strong>Статус файла:</strong> ' . esc_html($this->get_review_status_label($status)) . '</p>';
            if ($note) {
                echo '<p class="ppw-file-note">' . esc_html($note) . '</p>';
            }

            if ($order instanceof WC_Order && $order->has_status('file-review') && 'failed' === $status) {
                $nonce = wp_create_nonce('ppw_replace_file_' . $order->get_id() . '_' . $item_id);
                echo '<form class="ppw-file-action-form" method="post" enctype="multipart/form-data">';
                echo '<input type="hidden" name="ppw_action" value="replace_file">';
                echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
                echo '<input type="hidden" name="item_id" value="' . esc_attr($item_id) . '">';
                echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                echo '<label><strong>Загрузить новый файл:</strong><br><input type="file" name="ppw_replacement_file" accept=".pdf,.ai,.psd,.png,.jpg,.jpeg" required></label> ';
                echo '<button type="submit" class="button">Отправить файл на повторную проверку</button>';
                echo '</form>';
            }

            if ($order instanceof WC_Order && $order->has_status('file-review') && $this->status_requires_customer_confirmation($status) && $proof_url) {
                $nonce = wp_create_nonce('ppw_confirm_item_proof_' . $order->get_id() . '_' . $item_id);
                echo '<div class="ppw-item-proof">';
                echo '<p><a class="button" href="' . esc_url($proof_url) . '" target="_blank" rel="noopener" download>Скачать файл для утверждения</a></p>';
                echo '<form method="post">';
                echo '<input type="hidden" name="ppw_action" value="confirm_item_proof">';
                echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
                echo '<input type="hidden" name="item_id" value="' . esc_attr($item_id) . '">';
                echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
                echo '<button type="submit" class="button alt">Утверждаю</button>';
                echo '</form></div>';
            }

            $config = json_decode((string) $item->get_meta(self::ITEM_META_CONFIG, true), true);
            if (!empty($config['params'])) {
                echo '<div class="ppw-order-config"><strong>Параметры печати:</strong>' . wp_kses_post($this->render_config_summary($config, true)) . '</div>';
            }
        }

        public function allow_payment_for_custom_status($statuses, $order) {
            $statuses[] = 'awaiting-payment';
            return array_unique($statuses);
        }

        public function order_needs_payment_for_custom_status($needs_payment, $order, $valid_statuses) {
            if ($order instanceof WC_Order && $order->has_status('awaiting-payment') && $order->get_total() > 0) {
                return true;
            }
            return $needs_payment;
        }

        public function disable_initial_payment_for_print_orders($needs_payment, $cart) {
            if (is_admin() && !defined('DOING_AJAX')) {
                return $needs_payment;
            }
            if (!$cart || empty($cart->cart_contents)) {
                return $needs_payment;
            }
            foreach ($cart->get_cart() as $item) {
                $product_id = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];
                if ($this->product_requires_file($product_id) || $this->product_requires_file($item['product_id'])) {
                    return false;
                }
            }
            return $needs_payment;
        }

        public function add_order_metabox() {
            add_meta_box(
                'ppw_order_metabox',
                'Типография: файл и макет',
                [$this, 'render_order_metabox'],
                'shop_order',
                'normal',
                'default'
            );
        }

        public function render_order_metabox($post) {
            $order = wc_get_order($post->ID);
            if (!$order) {
                return;
            }
            wp_nonce_field('ppw_save_order_metabox', 'ppw_order_metabox_nonce');
            $proof_url = $order->get_meta(self::ORDER_META_PROOF_URL, true);
            $proof_note = $order->get_meta(self::ORDER_META_PROOF_NOTE, true);

            echo '<p><strong>Загруженные файлы клиента:</strong></p>';
            foreach ($order->get_items() as $item) {
                $url = $item->get_meta(self::ITEM_META_URL, true);
                $name = $item->get_meta(self::ITEM_META_NAME, true);
                if ($url) {
                    echo '<p style="margin:0 0 8px;"><strong>' . esc_html($item->get_name()) . '</strong><br><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($name ?: basename($url)) . '</a></p>';
                    $review_status = $this->get_item_review_status($item);
                    $review_note = (string) $item->get_meta(self::ITEM_META_REVIEW_NOTE, true);
                    $item_proof_url = (string) $item->get_meta(self::ITEM_META_PROOF_URL, true);
                    echo '<div style="padding:10px;margin:0 0 12px;background:#f6f7f7;border:1px solid #dcdcde;">';
                    echo '<label><strong>Результат проверки:</strong><br><select name="ppw_item_status[' . esc_attr($item->get_id()) . ']">';
                    foreach ($this->get_review_status_options() as $value => $label) {
                        echo '<option value="' . esc_attr($value) . '" ' . selected($review_status, $value, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></label>';
                    echo '<p><label><strong>Комментарий пользователю:</strong><br><textarea style="width:100%;" rows="2" name="ppw_item_note[' . esc_attr($item->get_id()) . ']">' . esc_textarea($review_note) . '</textarea></label></p>';
                    echo '<p><label><strong>Исправленный файл (URL):</strong><br><input style="width:100%;" type="url" name="ppw_item_proof_url[' . esc_attr($item->get_id()) . ']" value="' . esc_attr($item_proof_url) . '" placeholder="https://..."></label></p>';
                    echo '<small>Для результатов, требующих утверждения клиента, обязательно укажите ссылку на подготовленный файл.</small>';
                    echo '</div>';
                }

                $config = json_decode((string) $item->get_meta(self::ITEM_META_CONFIG, true), true);
                if (!empty($config['params'])) {
                    echo '<div style="margin:0 0 12px;"><strong>' . esc_html($item->get_name()) . ' — параметры:</strong>' . wp_kses_post($this->render_config_summary($config, true)) . '</div>';
                }
            }

            echo '<hr/>';
            echo '<p><label for="ppw_proof_file_url"><strong>Ссылка на подготовленный макет</strong></label></p>';
            echo '<input type="url" name="ppw_proof_file_url" id="ppw_proof_file_url" value="' . esc_attr($proof_url) . '" style="width:100%;" placeholder="https://...">';

            echo '<p><label for="ppw_proof_note"><strong>Комментарий для клиента</strong></label></p>';
            echo '<textarea name="ppw_proof_note" id="ppw_proof_note" rows="4" style="width:100%;">' . esc_textarea($proof_note) . '</textarea>';

            echo '<p style="color:#666;">После сохранения можно перевести заказ в статус «Ожидает утверждения макета». Тогда клиент увидит кнопку подтверждения.</p>';
        }

        public function save_order_metabox($post_id, $post) {
            if (!isset($_POST['ppw_order_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ppw_order_metabox_nonce'])), 'ppw_save_order_metabox')) {
                return;
            }
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_shop_order', $post_id) && !current_user_can('edit_post', $post_id)) {
                return;
            }

            $order = wc_get_order($post_id);
            if (!$order) {
                return;
            }

            $proof_url = isset($_POST['ppw_proof_file_url']) ? esc_url_raw(wp_unslash($_POST['ppw_proof_file_url'])) : '';
            $proof_note = isset($_POST['ppw_proof_note']) ? sanitize_textarea_field(wp_unslash($_POST['ppw_proof_note'])) : '';

            $order->update_meta_data(self::ORDER_META_PROOF_URL, $proof_url);
            $order->update_meta_data(self::ORDER_META_PROOF_NOTE, $proof_note);

            $statuses = isset($_POST['ppw_item_status']) && is_array($_POST['ppw_item_status']) ? wp_unslash($_POST['ppw_item_status']) : [];
            $notes = isset($_POST['ppw_item_note']) && is_array($_POST['ppw_item_note']) ? wp_unslash($_POST['ppw_item_note']) : [];
            $proof_urls = isset($_POST['ppw_item_proof_url']) && is_array($_POST['ppw_item_proof_url']) ? wp_unslash($_POST['ppw_item_proof_url']) : [];
            foreach ($order->get_items() as $item_id => $item) {
                if (!$item->get_meta(self::ITEM_META_URL, true)) {
                    continue;
                }
                if (isset($statuses[$item_id])) {
                    $status = $this->sanitize_review_status($statuses[$item_id]);
                    $this->set_item_review_status($item, $status);
                    $item->update_meta_data(self::ITEM_META_REVIEWED_AT, current_time('mysql'));
                }
                if (isset($notes[$item_id])) {
                    $item->update_meta_data(self::ITEM_META_REVIEW_NOTE, sanitize_textarea_field($notes[$item_id]));
                }
                if (isset($proof_urls[$item_id])) {
                    $item->update_meta_data(self::ITEM_META_PROOF_URL, esc_url_raw($proof_urls[$item_id]));
                }
                $item->save();
            }

            $order->save();
            $this->evaluate_order_file_workflow($order);
        }

        public function render_customer_proof_box($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $proof_url = $order->get_meta(self::ORDER_META_PROOF_URL, true);
            $proof_note = $order->get_meta(self::ORDER_META_PROOF_NOTE, true);

            if ($order->has_status('awaiting-payment')) {
                $pay_url = $order->get_checkout_payment_url();
                echo '<section class="woocommerce-order-details ppw-box"><h2>Оплата заказа</h2>';
                echo '<p>Файл проверен. Теперь вы можете оплатить заказ.</p>';
                echo '<p><a class="button alt" href="' . esc_url($pay_url) . '">Перейти к оплате</a></p>';
                echo '</section>';
            }

            if ($order->has_status('awaiting-proof') && $proof_url) {
                $approve_url = wp_nonce_url(add_query_arg([
                    'ppw_action' => 'approve_proof',
                    'order_id'   => $order->get_id(),
                ], wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'))), 'ppw_approve_proof_' . $order->get_id());

                echo '<section class="woocommerce-order-details ppw-box"><h2>Подтверждение макета</h2>';
                if ($proof_note) {
                    echo '<p>' . esc_html($proof_note) . '</p>';
                } else {
                    echo '<p>Макет готов. Проверьте его и подтвердите запуск в печать.</p>';
                }
                echo '<p><a class="button" href="' . esc_url($proof_url) . '" target="_blank" rel="noopener">Открыть подготовленный макет</a></p>';
                echo '<p><a class="button alt" href="' . esc_url($approve_url) . '">Ок, макет утвержден, запускаем в печать</a></p>';
                echo '</section>';
            }
        }

        public function handle_customer_proof_approval() {
            if (empty($_GET['ppw_action']) || 'approve_proof' !== $_GET['ppw_action'] || empty($_GET['order_id'])) {
                return;
            }
            $order_id = absint($_GET['order_id']);
            if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'ppw_approve_proof_' . $order_id)) {
                return;
            }
            if (!is_user_logged_in()) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            if ((int) $order->get_user_id() !== get_current_user_id()) {
                return;
            }
            if (!$order->has_status('awaiting-proof')) {
                return;
            }

            $order->update_status('processing', 'Клиент утвердил макет и подтвердил запуск в печать.');
            wc_add_notice('Спасибо! Макет утвержден и заказ передан в печать.', 'success');
            wp_safe_redirect(wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount')));
            exit;
        }

        public function render_thankyou_message($order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !$order->has_status('file-review')) {
                return;
            }
            echo '<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">Заказ создан. Сначала мы проверим ваш файл, после чего пришлем возможность оплатить заказ.</p>';
        }

        public function render_email_info($order, $sent_to_admin, $plain_text, $email) {
            if (!$order instanceof WC_Order || $sent_to_admin) {
                return;
            }
            if ($order->has_status('file-review')) {
                echo wp_kses_post('<p><strong>Что дальше:</strong> мы проверим ваш файл и после проверки откроем оплату заказа.</p>');
            }
            if ($order->has_status('awaiting-proof')) {
                $proof_url = $order->get_meta(self::ORDER_META_PROOF_URL, true);
                if ($proof_url) {
                    $view_url = wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'));
                    echo wp_kses_post('<p><strong>Макет готов к проверке.</strong> Войдите в личный кабинет, откройте заказ и подтвердите запуск в печать.</p>');
                    echo wp_kses_post('<p><a href="' . esc_url($view_url) . '">Открыть заказ</a></p>');
                }
            }
        }

        public function maybe_send_proof_email($order_id, $from_status, $to_status, $order) {
            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order_id);
            }
            if (!$order || 'awaiting-proof' !== $to_status) {
                return;
            }
            $proof_url = $order->get_meta(self::ORDER_META_PROOF_URL, true);
            if (!$proof_url || !$order->get_billing_email()) {
                return;
            }

            $subject = sprintf('Макет готов к подтверждению — заказ #%d', $order->get_id());
            $view_url = wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'));
            $message = "Здравствуйте!\n\n";
            $message .= "Подготовленный файл для заказа #{$order->get_id()} готов к проверке.\n";
            $message .= "Откройте заказ в личном кабинете, проверьте макет и подтвердите запуск в печать.\n\n";
            $message .= "Ссылка на заказ: {$view_url}\n";
            $message .= "Ссылка на макет: {$proof_url}\n\n";
            $message .= "Спасибо!";
            wp_mail($order->get_billing_email(), $subject, $message);
        }

        private function get_review_status_options() {
            return [
                'pending'               => 'Проверяется',
                'failed'                => 'Проверка не пройдена — нужен новый файл',
                'awaiting_modified_confirmation' => 'Изменён под требования — ждёт утверждения клиента',
                'awaiting_print_confirmation' => 'Проверен и подготовлен к печати — ждёт утверждения клиента',
                'approved'              => 'Файл утверждён клиентом',
            ];
        }

        private function get_review_status_label($status) {
            $options = $this->get_review_status_options();
            return isset($options[$status]) ? $options[$status] : $options['pending'];
        }

        private function sanitize_review_status($status) {
            $status = sanitize_key((string) $status);
            // Statuses from versions before 1.4 remain readable.
            if ('awaiting_confirmation' === $status) {
                return 'awaiting_modified_confirmation';
            }
            if ('passed' === $status) {
                return 'awaiting_print_confirmation';
            }
            return array_key_exists($status, $this->get_review_status_options()) ? $status : 'pending';
        }

        private function get_item_review_status($item) {
            $status = $item instanceof WC_Order_Item_Product ? (string) $item->get_meta(self::ITEM_META_REVIEW_STATUS, true) : '';
            return $this->sanitize_review_status($status ?: 'pending');
        }

        private function status_requires_customer_confirmation($status) {
            return in_array($this->sanitize_review_status($status), ['awaiting_modified_confirmation', 'awaiting_print_confirmation'], true);
        }

        private function is_item_pdf_checked($item) {
            return 'pending' !== $this->get_item_review_status($item);
        }

        private function set_item_review_status($item, $status) {
            $status = $this->sanitize_review_status($status);
            $item->update_meta_data(self::ITEM_META_REVIEW_STATUS, $status);
            $item->update_meta_data(self::ITEM_META_IS_PDF_CHECKED, 'pending' === $status ? 'False' : 'True');
            $item->update_meta_data(self::ITEM_META_PDF_CHECK_RESULT, $status);
        }

        public function register_review_callback_endpoint() {
            register_rest_route('ppw/v1', '/file-result', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_review_callback'],
                'permission_callback' => '__return_true',
            ]);
        }

        public function handle_review_callback(WP_REST_Request $request) {
            $secret = trim((string) get_option(self::OPTION_WEBHOOK_SECRET, ''));
            $received = (string) $request->get_header('x-ppw-secret');
            if ('' === $secret || !hash_equals($secret, $received)) {
                return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook secret.'], 403);
            }

            $order_id = absint($request->get_param('order_id'));
            $item_id = absint($request->get_param('item_id'));
            $result = sanitize_key((string) $request->get_param('result'));
            $note = sanitize_textarea_field((string) $request->get_param('message'));
            $proof_url = esc_url_raw((string) $request->get_param('proof_url'));

            $map = [
                'failed'   => 'failed',
                'rejected' => 'failed',
                'modified' => 'awaiting_modified_confirmation',
                'changed'  => 'awaiting_modified_confirmation',
                'print_ready' => 'awaiting_print_confirmation',
                'passed'   => 'awaiting_print_confirmation',
                'success'  => 'awaiting_print_confirmation',
            ];
            if (!$order_id || !$item_id || !isset($map[$result])) {
                return new WP_REST_Response(['success' => false, 'message' => 'Required: order_id, item_id and result=failed|modified|print_ready.'], 400);
            }
            if ($this->status_requires_customer_confirmation($map[$result]) && !$proof_url) {
                return new WP_REST_Response(['success' => false, 'message' => 'proof_url is required when customer approval is required.'], 400);
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_REST_Response(['success' => false, 'message' => 'Order not found.'], 404);
            }
            $item = $order->get_item($item_id);
            if (!$item instanceof WC_Order_Item_Product || !$item->get_meta(self::ITEM_META_URL, true)) {
                return new WP_REST_Response(['success' => false, 'message' => 'Order item not found or has no print file.'], 404);
            }

            $status = $map[$result];
            $this->set_item_review_status($item, $status);
            $item->update_meta_data(self::ITEM_META_REVIEW_NOTE, $note);
            $item->update_meta_data(self::ITEM_META_REVIEWED_AT, current_time('mysql'));
            if ($proof_url) {
                $item->update_meta_data(self::ITEM_META_PROOF_URL, $proof_url);
            } elseif (!$this->status_requires_customer_confirmation($status)) {
                $item->delete_meta_data(self::ITEM_META_PROOF_URL);
            }
            $item->save();

            $order->add_order_note(sprintf('Проверка файла позиции #%d: %s%s', $item_id, $this->get_review_status_label($status), $note ? '. ' . $note : ''));
            $this->evaluate_order_file_workflow($order);

            return new WP_REST_Response([
                'success'      => true,
                'order_id'     => $order->get_id(),
                'item_id'      => $item_id,
                'item_status'  => $status,
                'isPDFCheked'  => $this->is_item_pdf_checked($item),
                'order_status' => $order->get_status(),
                'payment_url'  => $order->has_status('awaiting-payment') ? $order->get_checkout_payment_url() : '',
            ], 200);
        }

        private function customer_can_manage_order($order, $provided_order_key = '') {
            if (!$order instanceof WC_Order) {
                return false;
            }
            if (is_user_logged_in() && $order->get_user_id() && (int) $order->get_user_id() === get_current_user_id()) {
                return true;
            }
            $provided_order_key = (string) $provided_order_key;
            return '' !== $provided_order_key && hash_equals((string) $order->get_order_key(), $provided_order_key);
        }

        private function get_customer_order_return_url($order) {
            if (is_user_logged_in() && $order->get_user_id() && (int) $order->get_user_id() === get_current_user_id()) {
                return wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'));
            }
            return $order->get_checkout_order_received_url();
        }

        public function handle_customer_file_workflow_action() {
            if (empty($_POST['ppw_action']) || empty($_POST['order_id']) || empty($_POST['item_id'])) {
                return;
            }
            $action = sanitize_key(wp_unslash($_POST['ppw_action']));
            if (!in_array($action, ['replace_file', 'confirm_item_proof'], true)) {
                return;
            }

            $order_id = absint($_POST['order_id']);
            $item_id = absint($_POST['item_id']);
            $order = wc_get_order($order_id);
            if (!$order || !$this->customer_can_manage_order($order, isset($_POST['order_key']) ? wp_unslash($_POST['order_key']) : '')) {
                return;
            }
            $item = $order->get_item($item_id);
            if (!$item instanceof WC_Order_Item_Product || !$item->get_meta(self::ITEM_META_URL, true) || !$order->has_status('file-review')) {
                return;
            }

            if ('replace_file' === $action) {
                if (!wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'ppw_replace_file_' . $order_id . '_' . $item_id)) {
                    return;
                }
                if ('failed' !== $this->get_item_review_status($item) || empty($_FILES['ppw_replacement_file']['name'])) {
                    return;
                }
                $uploaded = $this->handle_upload($_FILES['ppw_replacement_file']);
                if (is_wp_error($uploaded)) {
                    wc_add_notice($uploaded->get_error_message(), 'error');
                    wp_safe_redirect($this->get_customer_order_return_url($order));
                    exit;
                }
                $item->update_meta_data(self::ITEM_META_URL, esc_url_raw($uploaded['url']));
                $item->update_meta_data(self::ITEM_META_PATH, sanitize_text_field($uploaded['file']));
                $item->update_meta_data(self::ITEM_META_NAME, sanitize_file_name($uploaded['name']));
                $this->set_item_review_status($item, 'pending');
                $item->update_meta_data(self::ITEM_META_REVIEW_NOTE, 'Новый файл загружен и отправлен на повторную проверку.');
                $item->delete_meta_data(self::ITEM_META_PROOF_URL);
                $item->save();
                $order->add_order_note(sprintf('Клиент загрузил новый файл для позиции #%d. Файл отправлен на повторную проверку.', $item_id));
                $this->send_item_webhook($order, $item, 'print_file_reuploaded');
                wc_add_notice('Новый файл загружен и отправлен на повторную проверку.', 'success');
            }

            if ('confirm_item_proof' === $action) {
                if (!wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'ppw_confirm_item_proof_' . $order_id . '_' . $item_id)) {
                    return;
                }
                if (!$this->status_requires_customer_confirmation($this->get_item_review_status($item))) {
                    return;
                }
                $this->set_item_review_status($item, 'approved');
                $item->update_meta_data(self::ITEM_META_REVIEW_NOTE, 'Файл утверждён клиентом.');
                $item->update_meta_data(self::ITEM_META_REVIEWED_AT, current_time('mysql'));
                $item->save();
                $order->add_order_note(sprintf('Клиент подтвердил исправленный файл позиции #%d.', $item_id));
                $this->send_item_webhook($order, $item, 'print_file_approved');
                wc_add_notice('Файл подтверждён.', 'success');
            }

            $this->evaluate_order_file_workflow($order);
            wp_safe_redirect($this->get_customer_order_return_url($order));
            exit;
        }

        private function evaluate_order_file_workflow($order) {
            if (!$order instanceof WC_Order || $order->has_status(['processing', 'completed', 'cancelled', 'refunded', 'failed'])) {
                return;
            }

            $has_files = false;
            $all_ready = true;
            foreach ($order->get_items() as $item) {
                if (!$item->get_meta(self::ITEM_META_URL, true)) {
                    continue;
                }
                $has_files = true;
                if ('approved' !== $this->get_item_review_status($item)) {
                    $all_ready = false;
                }
            }
            if (!$has_files) {
                return;
            }

            if ($all_ready) {
                if (!$order->has_status('awaiting-payment')) {
                    $order->update_status('awaiting-payment', 'Все файлы прошли проверку/подтверждение. Открыта оплата заказа.');
                }
            } elseif (!$order->has_status('file-review')) {
                $order->update_status('file-review', 'Не все файлы завершили проверку. Оплата закрыта до завершения проверки.');
            }
        }

        private function send_item_webhook($order, $item, $event) {
            $webhook_url = trim((string) get_option(self::OPTION_WEBHOOK_URL, ''));
            if (!$webhook_url || !$order instanceof WC_Order || !$item instanceof WC_Order_Item_Product) {
                return;
            }
            $payload = [
                'event'        => $event,
                'order_id'     => $order->get_id(),
                'order_key'    => $order->get_order_key(),
                'item_id'      => $item->get_id(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'file_url'     => $item->get_meta(self::ITEM_META_URL, true),
                'file_name'    => $item->get_meta(self::ITEM_META_NAME, true),
                'isPDFCheked'  => $this->is_item_pdf_checked($item),
                'pdfCheckResult' => $this->get_item_review_status($item),
                'config'       => json_decode((string) $item->get_meta(self::ITEM_META_CONFIG, true), true),
                'created_at'   => current_time('mysql'),
            ];
            $headers = ['Content-Type' => 'application/json'];
            $secret = trim((string) get_option(self::OPTION_WEBHOOK_SECRET, ''));
            if ($secret) {
                $headers['X-PPW-Secret'] = $secret;
            }
            wp_remote_post($webhook_url, ['timeout' => 20, 'headers' => $headers, 'body' => wp_json_encode($payload)]);
        }

        public function maybe_send_payment_ready_email($order_id, $from_status, $to_status, $order) {
            if ('awaiting-payment' !== $to_status) {
                return;
            }
            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order_id);
            }
            if (!$order || !$order->get_billing_email()) {
                return;
            }
            $pay_url = $order->get_checkout_payment_url();
            $subject = sprintf('Файлы проверены — можно оплатить заказ #%d', $order->get_id());
            $message = "Здравствуйте!\n\nВсе файлы заказа #{$order->get_id()} прошли проверку и необходимые подтверждения.\nТеперь заказ можно оплатить.\n\nСсылка на оплату: {$pay_url}\n\nСпасибо!";
            wp_mail($order->get_billing_email(), $subject, $message);
        }

        public function add_settings_page() {
            add_submenu_page(
                'woocommerce',
                'Print Workflow',
                'Print Workflow',
                'manage_woocommerce',
                'ppw-settings',
                [$this, 'render_settings_page']
            );
        }

        public function register_settings() {
            register_setting('ppw_settings_group', self::OPTION_WEBHOOK_URL, [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => '',
            ]);
            register_setting('ppw_settings_group', self::OPTION_WEBHOOK_SECRET, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]);
        }

        public function render_settings_page() {
            ?>
            <div class="wrap">
                <h1>Print Workflow</h1>
                <form method="post" action="options.php">
                    <?php settings_fields('ppw_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ppw_webhook_url">Webhook URL</label></th>
                            <td>
                                <input name="<?php echo esc_attr(self::OPTION_WEBHOOK_URL); ?>" type="url" id="ppw_webhook_url" value="<?php echo esc_attr(get_option(self::OPTION_WEBHOOK_URL, '')); ?>" class="regular-text">
                                <p class="description">Сюда будет отправляться POST при создании заказа с файлом.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ppw_webhook_secret">Webhook secret</label></th>
                            <td>
                                <input name="<?php echo esc_attr(self::OPTION_WEBHOOK_SECRET); ?>" type="text" id="ppw_webhook_secret" value="<?php echo esc_attr(get_option(self::OPTION_WEBHOOK_SECRET, '')); ?>" class="regular-text">
                                <p class="description">Если указан, будет отправлен в заголовке X-PPW-Secret.</p>
                                <p class="description"><strong>Callback проверки:</strong> <code><?php echo esc_html(rest_url('ppw/v1/file-result')); ?></code>. Отправляйте тот же секрет в заголовке X-PPW-Secret.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }
    }
}

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    new PPW_Print_Product_Workflow();
});
