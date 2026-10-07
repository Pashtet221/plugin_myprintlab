<?php
/** Run on a disposable WordPress + WooCommerce site: wp eval-file tests/workflow.php */
if (!class_exists('WooCommerce') || !class_exists('PPW_Print_Product_Workflow')) {
    throw new RuntimeException('Activate WooCommerce and PPW first.');
}
$ppw = (new ReflectionClass('PPW_Print_Product_Workflow'))->newInstanceWithoutConstructor();
$checks = 0;
$orders = [];
$coupons = [];
$old_url = get_option('ppw_webhook_url', null);
$old_secret = get_option('ppw_webhook_secret', null);
$secret = bin2hex(random_bytes(32));
$payloads = [];
$fail_delivery = false;
$mock_url = 'https://ppw-test.example.invalid/webhook';
$mock = function ($pre, $args, $url) use (&$payloads, &$fail_delivery, $mock_url) {
    if ($url !== $mock_url) return $pre;
    $payloads[] = json_decode($args['body'], true);
    return ['headers'=>[], 'body'=>'', 'response'=>['code'=>$fail_delivery ? 503 : 200, 'message'=>'Test'], 'cookies'=>[]];
};
add_filter('pre_http_request', $mock, 10, 3);
update_option('ppw_webhook_url', $mock_url);
update_option('ppw_webhook_secret', $secret);
$check = function ($condition, $message) use (&$checks) {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks; echo 'PASS: ' . $message . "\n";
};
$make_order = function ($count) use (&$orders) {
    $order = wc_create_order();
    for ($i = 0; $i < $count; ++$i) {
        $item = new WC_Order_Item_Product();
        $item->set_name('Print fixture ' . $i);
        $item->set_quantity(1); $item->set_subtotal(10); $item->set_total(10);
        $item->add_meta_data('_ppw_file_url', 'http://127.0.0.1:8080/original-' . $i . '.pdf');
        $item->add_meta_data('_ppw_file_name', 'original.pdf');
        $item->add_meta_data('_ppw_file_review_status', 'pending');
        $item->add_meta_data('isPDFCheked', 'False');
        $item->add_meta_data('pdfCheckResult', 'pending');
        $order->add_item($item);
    }
    $order->set_status('file-review'); $order->calculate_totals(); $order->save();
    $orders[] = $order->get_id();
    return $order;
};
$callback = function ($order, $id, $result, $proof = '', $revision = null) use ($secret) {
    $req = new WP_REST_Request('POST', '/ppw/v1/file-result');
    $req->set_header('X-PPW-Secret', $secret);
    $params = ['order_id'=>$order->get_id(), 'item_id'=>$id, 'result'=>$result, 'proof_url'=>$proof];
    if ($revision !== null) $params['revision'] = $revision;
    $req->set_body_params($params);
    return rest_do_request($req);
};
$act = function ($order_id, $item_id, $action, $key = null, $nonce = null) use ($ppw) {
    $order = wc_get_order($order_id);
    $prefix = ['remove_item'=>'ppw_remove_item_', 'confirm_item_proof'=>'ppw_confirm_item_proof_', 'replace_file'=>'ppw_replace_file_'];
    return $ppw->process_customer_file_action($order, $item_id, $action,
        $key === null ? $order->get_order_key() : $key,
        $nonce === null ? wp_create_nonce($prefix[$action] . $order_id . '_' . $item_id) : $nonce);
};
try {
    $order = $make_order(4); $ids = array_keys($order->get_items());
    $check($ppw->get_hidden_admin_order_statuses($order) === ['pending', 'on-hold', 'awaiting-proof'], 'Print editor hides unrelated manual status choices');
    $order->set_status('on-hold');
    $check(!in_array('on-hold', $ppw->get_hidden_admin_order_statuses($order), true), 'Existing order status remains selectable');
    $order->set_status('file-review');
    $ordinary = $make_order(0);
    $check($ppw->get_hidden_admin_order_statuses($ordinary) === [], 'Ordinary orders keep all status choices');
    $statuses = wc_get_order_statuses();
    $check(isset($statuses['wc-pending'], $statuses['wc-on-hold'], $statuses['wc-awaiting-proof']) && $statuses['wc-awaiting-payment'] === 'Готов к оплате', 'System statuses remain registered and custom payment label is distinct');
    $cases = ['done'=>'accepted', 'ChangedNeedApprov'=>'awaiting_modified_confirmation', 'NeedApprov'=>'awaiting_print_confirmation', 'needNewFile'=>'failed'];
    $i = 0;
    foreach ($cases as $code=>$expected) {
        $response = $callback($order, $ids[$i++], $code, 'http://127.0.0.1:8080/proof.pdf');
        $check($response->get_status() === 200 && $response->get_data()['item_status'] === $expected, 'Denis status ' . $code);
    }
    $check(!wc_get_order($order->get_id())->needs_payment(), 'Mixed statuses block payment');
    add_filter('woocommerce_is_account_page', '__return_true');
    $render = function ($id) use ($order, $ppw) {
        $fresh = wc_get_order($order->get_id());
        ob_start(); $ppw->render_order_item_file_meta($id, $fresh->get_item($id), $fresh);
        return ob_get_clean();
    };
    $html = $render($ids[3]);
    $check(strpos($html, 'replace_file') !== false && strpos($html, 'remove_item') !== false, 'Rejected file UI offers replacement and deletion');
    foreach ([$ids[1], $ids[2]] as $id) {
        $html = $render($id);
        $check(strpos($html, 'confirm_item_proof') !== false && strpos($html, 'remove_item') !== false && strpos($html, 'replace_file') === false, 'Prepared file UI offers approval and deletion');
    }
    $check(strpos($render($ids[0]), 'ppw_action') === false, 'Accepted file UI requires no customer action');
    remove_filter('woocommerce_is_account_page', '__return_true');
    $check(is_wp_error($act($order->get_id(), $ids[3], 'remove_item', 'wrong')), 'Wrong order key cannot delete');
    $check(is_wp_error($act($order->get_id(), $ids[3], 'remove_item', null, 'wrong')), 'Invalid nonce cannot delete');
    $check(is_wp_error($act($order->get_id(), $ids[0], 'remove_item')), 'Accepted item cannot be deleted');
    $other = $make_order(1);
    $check(is_wp_error($act($order->get_id(), array_key_first($other->get_items()), 'remove_item')), 'Item from another order cannot be deleted');
    $check(!is_wp_error($act($order->get_id(), $ids[1], 'confirm_item_proof')), 'Customer approves corrected file');
    $check(!wc_get_order($order->get_id())->needs_payment(), 'One approval does not unlock whole order');
    $check(!is_wp_error($act($order->get_id(), $ids[2], 'confirm_item_proof')), 'Customer approves prepared file');
    $check($callback($order, $ids[1], 'ChangedNeedApprov', 'http://127.0.0.1:8080/proof.pdf')->get_status() === 409, 'Late callback cannot undo customer approval');
    $check(!is_wp_error($act($order->get_id(), $ids[3], 'remove_item')), 'Rejected position can be removed');
    $fresh = wc_get_order($order->get_id());
    $check(count($fresh->get_items()) === 3 && (float) $fresh->get_total() === 30.0, 'Removal persists and recalculates total');
    $check($fresh->has_status('awaiting-payment') && $fresh->needs_payment(), 'Accepted plus approved items unlock payment');
    $payload = end($payloads);
    $check($payload['event'] === 'print_order_created' && $payload['action'] === 'remove_item' && count($payload['items']) === 3 && (float)$payload['total'] === 30.0, 'Original webhook receives full updated snapshot');
    $check($payload['revision'] === 3 && $payload['changed_item_id'] === $ids[3] && !empty($payload['event_id']) && !empty($payload['items'][1]['proof_url']), 'Snapshot identifies revision, changed item and proof');
    $ppw->retry_created_webhook($fresh->get_id());
    $check(wc_get_order($fresh->get_id())->has_status('awaiting-payment'), 'Initial retry cannot reset approved order');
    $n = count($payloads); $ppw->retry_updated_webhook($fresh->get_id(), 1);
    $check(count($payloads) === $n, 'Superseded update retry is ignored');
    $check($callback($fresh, $ids[0], 'done', '', 1)->get_status() === 409, 'Stale revision callback is rejected');

    $discounted = $make_order(2); $discount_ids = array_keys($discounted->get_items());
    $coupon = new WC_Coupon(); $coupon->set_code('ppw-test-' . bin2hex(random_bytes(8)));
    $coupon->set_discount_type('fixed_cart'); $coupon->set_amount(5); $coupon->save(); $coupons[] = $coupon->get_id();
    $discounted->apply_coupon($coupon);
    $callback($discounted, $discount_ids[0], 'done'); $callback($discounted, $discount_ids[1], 'needNewFile');
    $check(!is_wp_error($act($discounted->get_id(), $discount_ids[1], 'remove_item')), 'Discounted order permits rejected item removal');
    $check((float)wc_get_order($discounted->get_id())->get_total() === 5.0, 'Fixed cart coupon is redistributed across remaining items');

    $single = $make_order(1);
    $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title('Fixture delivery'); $shipping->set_total(5); $single->add_item($shipping);
    $fee = new WC_Order_Item_Fee(); $fee->set_name('Fixture fee'); $fee->set_total(2); $single->add_item($fee); $single->calculate_totals(); $single_id = array_key_first($single->get_items());
    $callback($single, $single_id, 'needNewFile');
    $fail_delivery = true;
    $check(!is_wp_error($act($single->get_id(), $single_id, 'remove_item')), 'Deletion succeeds while webhook receiver fails');
    $empty = wc_get_order($single->get_id());
    $check($empty->has_status('cancelled') && (float)$empty->get_total() === 0.0 && !$empty->needs_payment(), 'Deleting last position cancels empty order');
    $check((bool)wp_next_scheduled('ppw_retry_updated_webhook', [$empty->get_id(), 1]), 'HTTP 503 schedules full snapshot retry');
    $first_event_id = end($payloads)['event_id']; $fail_delivery = false;
    $ppw->retry_updated_webhook($empty->get_id(), 1);
    $check((int)wc_get_order($empty->get_id())->get_meta('_ppw_update_sent_revision') === 1 && end($payloads)['event_id'] === $first_event_id, 'Successful retry records delivery with stable event ID');

    $approved = $make_order(1); $approved_id = array_key_first($approved->get_items());
    $check($callback($approved, $approved_id, 'NeedApprov')->get_status() === 400, 'Approval status requires a prepared file');
    $callback($approved, $approved_id, 'done');
    $check(wc_get_order($approved->get_id())->needs_payment(), 'done alone needs no customer approval');
    $approved = wc_get_order($approved->get_id()); $approved->payment_complete();
    $check($callback($approved, $approved_id, 'needNewFile')->get_status() === 409, 'Paid order rejects review changes');
    $check(is_wp_error($act($approved->get_id(), $approved_id, 'remove_item')), 'Paid order rejects deletion');

    // Exercise actual PHP HTTP uploads, not fake is_uploaded_file() inputs.
    $http = $make_order(1); $http_id = array_key_first($http->get_items());
    $callback($http, $http_id, 'needNewFile');
    update_option('ppw_webhook_url', ''); // No external receiver is needed for HTTP upload tests.
    $multipart = function ($fields, $file_field, $contents) {
        $boundary = 'ppw-' . bin2hex(random_bytes(16)); $body = '';
        foreach ($fields as $key=>$value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$file_field\"; filename=\"proof.pdf\"\r\nContent-Type: application/pdf\r\n\r\n$contents\r\n--$boundary--\r\n";
        return ['body'=>$body, 'headers'=>['Content-Type'=>'multipart/form-data; boundary=' . $boundary], 'redirection'=>0, 'timeout'=>30];
    };
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $args = $multipart(['ppw_action'=>'replace_file', 'order_id'=>$http->get_id(), 'item_id'=>$http_id, 'order_key'=>$http->get_order_key(), '_wpnonce'=>wp_create_nonce('ppw_replace_file_' . $http->get_id() . '_' . $http_id)], 'ppw_replacement_file', $pdf);
    $response = wp_remote_post(home_url('/'), $args);
    $check(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 302, 'Customer multipart replacement POST succeeds');
    wp_cache_flush(); // The HTTP worker changed metadata outside this CLI process.
    $http = wc_get_order($http->get_id()); $http_item = $http->get_item($http_id);
    $check($http_item->get_meta('pdfCheckResult') === 'pending' && $http_item->get_meta('isPDFCheked') === 'False' && is_file($http_item->get_meta('_ppw_file_path')), 'Replacement is stored and resets only changed file to pending');
    update_option('ppw_webhook_url', $mock_url);
    $ppw->retry_updated_webhook($http->get_id(), 1);
    $check(end($payloads)['action'] === 'replace_file' && end($payloads)['items'][0]['pdfCheckResult'] === 'pending', 'Replacement resends original full-order webhook');
    $args = $multipart(['order_id'=>$http->get_id(), 'item_id'=>$http_id, 'result'=>'ChangedNeedApprov', 'revision'=>1], 'proof_file', $pdf);
    $args['headers']['X-PPW-Secret'] = $secret;
    $response = wp_remote_post(home_url('/?rest_route=/ppw/v1/file-result'), $args);
    $check(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200, 'Printer multipart proof_file callback succeeds');
    wp_cache_flush();
    $http = wc_get_order($http->get_id());
    $check($http->get_item($http_id)->get_meta('pdfCheckResult') === 'awaiting_modified_confirmation' && $http->get_item($http_id)->get_meta('_ppw_item_proof_url'), 'Uploaded printer proof is available for customer approval');
    echo "SUCCESS: $checks checks\n";
} finally {
    remove_filter('pre_http_request', $mock, 10);
    foreach (['ppw_webhook_url'=>$old_url, 'ppw_webhook_secret'=>$old_secret] as $name=>$value) {
        if ($value === null) delete_option($name); else update_option($name, $value);
    }
    remove_filter('woocommerce_is_account_page', '__return_true');
    foreach ($coupons as $id) { $coupon = new WC_Coupon($id); $coupon->delete(true); }
    foreach ($orders as $id) {
        $order = wc_get_order($id);
        if (!$order) continue;
        foreach ($order->get_items() as $item) {
            $path = $item->get_meta('_ppw_file_path');
            if ($path && is_file($path) && strpos($path, wp_upload_dir()['basedir'] . '/') === 0) unlink($path);
            $proof = $item->get_meta('_ppw_item_proof_url');
            $uploads = wp_upload_dir();
            if ($proof && strpos($proof, $uploads['baseurl'] . '/') === 0) {
                $path = $uploads['basedir'] . substr($proof, strlen($uploads['baseurl']));
                if (is_file($path)) unlink($path);
            }
        }
        wp_clear_scheduled_hook('ppw_retry_created_webhook', [$id]);
        for ($revision = 1; $revision <= 5; ++$revision) wp_clear_scheduled_hook('ppw_retry_updated_webhook', [$id, $revision]);
        $order->delete(true);
    }
}
