<?php

/**
 * @package insta_Punchout
 * @version 3.0
 */
/*
Plugin Name: InstaPunchout
Description: This is the punchout plugin which is created by InstaPunchout.
Author: InstaPunchout
Version: 1.0.65
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function instapunchout_order_status_changed($order_id, $old_status, $new_status)
{
    try {
        $data = [
            "site_url" => get_site_url(),
            "order_id" => $order_id,
            "old_status" => $old_status,
            "new_status" => $new_status,
        ];
        $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id);
        $response = rest_do_request($request);
        $server = rest_get_server();
        $order = $server->response_to_data($response, false);
        $data["order"] = $order;

        return instapunchout_post_json('https://punchout.cloud/api/v1/plugins/woocommerce-order-status-changed', $data);

        //code...
    } catch (\Throwable $th) {
        return instapunchout_post_json('https://punchout.cloud/log', [
            "error" => "failed to callback woocommerce-order-status-changed",
            "message" => $th->getMessage(),
            "order_id" => $order_id
        ]);
    }
}

add_action('woocommerce_order_status_changed', 'instapunchout_order_status_changed', 10, 3);




function instapunchout_order_refunded($order_id, $refund_id)
{
    $data = [];
    try {
        $data = [
            "site_url" => get_site_url(),
            "order_id" => $order_id,
            "refund_id" => $refund_id,
        ];
        $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id);
        $response = rest_do_request($request);
        $server = rest_get_server();
        $order = $server->response_to_data($response, false);
        $data["order"] = $order;

        $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id . '/refunds/' . $refund_id);
        $response = rest_do_request($request);
        $server = rest_get_server();
        $refund = $server->response_to_data($response, false);
        $data["refund"] = $refund;

        return instapunchout_post_json('https://hooks.punchout.cloud/api/v1/plugins/woocommerce-order-refunded', $data);

    } catch (\Throwable $th) {
        try {
            return instapunchout_post_json('https://hooks.punchout.cloud/api/v1/plugins/woocommerce-order-refunded', $data);
        } catch (\Throwable $th) {
            return instapunchout_post_json('https://punchout.cloud/api/v1/plugins/woocommerce-order-refunded', $data);
        }
    }
}
add_action('woocommerce_order_refunded', 'instapunchout_order_refunded', 10, 2);



/// uxload

add_action('wp_head', 'instapunchout_ux_load');
function instapunchout_ux_load()
{
    $user_id = get_current_user_id();
    $punchout_id = get_user_meta($user_id, 'punchout_id', true);

    if ($user_id && $punchout_id) {
        $response = wp_remote_get('https://punchout.cloud/punchout.js?id=' . $punchout_id, 'json', 'javascript');
        $body = wp_remote_retrieve_body($response);
        if (function_exists('wp_print_inline_script_tag')) {
            wp_print_inline_script_tag($body);
        } else {
            echo sprintf("<script type=\"text/javascript\">%s</script>\n", $body);
        }
    }
}

// punchout

add_action('wp_loaded', 'instapunchout_execute');
function instapunchout_execute()
{

    $punchoutModel = new InstaPunchout_Punchout();
    $punchoutModel->process();
}

function instapunchout_post_json($url, $body = null)
{
    $args = array(
        'body' => json_encode($body),
        'timeout' => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => ["content-type" => "application/json"],
        'cookies' => array(),
    );

    $response = wp_remote_post($url, $args);
    $body = wp_remote_retrieve_body($response);
    return json_decode($body, true);
}

function instapunchout_get_products($vars)
{
    $vars['type'] = array_merge(array_keys(wc_get_product_types()));
    $args = apply_filters('woocommerce_product_object_query_args', $vars);
    $results = WC_Data_Store::load('product')->query($args);
    return apply_filters('woocommerce_product_object_query', $results, $args);
}

class InstaPunchout_Punchout
{

    public function process()
    {
        global $wp;
        $url = $_SERVER['REQUEST_URI'];

        if (strpos($url, '/wp-admin/')) {
            return;
        }

        if (strpos($url, '%2Fpunchout')) {
            $url = str_replace("%2F", "/", $url);
        }

        $index = strpos($url, '/punchout/');

        $order_status_path = "/punchout/api/order_status";
        $cart_path = "/punchout/api/cart";
        $order_path_json = "/punchout/api/order.json";
        $refunds_path_json = "/punchout/api/refunds.json";
        $getorder_path_json = "/punchout/api/getorder.json";
        $getrefund_path_json = "/punchout/api/getrefund.json";
        $orders_path_json = "/punchout/api/orders.json";
        $cart_path_json = "/punchout/api/cart.json";
        $products_path_json = "/punchout/api/products.json";
        $options_path_json = "/punchout/api/options.json";
        $product_path = "/punchout/api/product";
        $punchout_path = substr($url, $index);


        if (substr($url, $index, strlen($orders_path_json)) === $orders_path_json) {
            authorize();

            if (!isset($_GET['after'])) {
                echo json_encode(["error" => "after is required"]);
                exit;
            }
            $after = $_GET['after'];

            $query_data = [
                'orderby' => 'modified_date',
                'order' => 'DESC',
                'return' => 'objects',
            ];
            if (isset($_GET['limit'])) {
                $query_data['limit'] = $_GET['limit'];
            }
            $query = new WC_Order_Query($query_data);
            if (isset($_GET['status'])) {
                $status = explode(',', $_GET['status']);
                $query->set('status', $status);
            }

            $query->set('date_modified', '>' . $after);

            $query->set('created_via', 'rest-api');
            $orders = $query->get_orders();
            header('Content-Type: application/json');
            $orders_data = [];
            foreach ($orders as $order) {
                // check if metadata contains key
                $meta_data = $order->get_meta_data();
                foreach ($meta_data as $meta) {
                    if ($meta->key == 'punchout_order_id') {
                        $orders_data[] = [
                            "status" => $order->get_status(),
                            "punchout_order_id" => $meta->value,
                            "order_id" => $order->get_id(),
                            "date" => $order->get_date_modified()->format('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            $args = array(
                'type' => 'shop_order_refund',
                'date_created' => '>' . $after,
            );
            $refunds = wc_get_orders($args);
            $refunds_data = [];
            foreach ($refunds as $refund) {
                $refunds_data[] = [
                    "refund_id" => $refund->get_id(),
                    "order_id" => $refund->get_parent_id(),
                    "date" => $refund->get_date_created()->format('Y-m-d H:i:s'),
                ];
            }
            echo json_encode(["orders" => $orders_data, "refunds" => $refunds_data]);
            exit;
        } else if (substr($url, $index, strlen($getorder_path_json)) === $getorder_path_json) {
            authorize();
            $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $_GET['id']);
            $response = rest_do_request($request);
            $server = rest_get_server();
            $order = $server->response_to_data($response, false);
            echo json_encode($order);
            exit;
        } else if (substr($url, $index, strlen($getrefund_path_json)) === $getrefund_path_json) {
            authorize();
            $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $_GET['order_id'] . '/refunds/' . $_GET['refund_id']);
            $response = rest_do_request($request);
            $server = rest_get_server();
            $refund = $server->response_to_data($response, false);
            echo json_encode($refund);
            exit;
        } else if (substr($url, $index, strlen($products_path_json)) === $products_path_json) {
            try {
                header('Content-Type: application/json');
                // $authorization_header = getallheaders()["Authorization"];
                // $res = instapunchout_post_json('https://dev.instapunchout.com/authorize', ["authorization"=> $authorization_header]);
                //if($res["authorized"] == true) {
                $data = [];
                $products = instapunchout_get_products(['limit' => 10000]);
                foreach ($products as $key => $value) {
                    $product = $value->get_data();
                    $attributes = [];
                    foreach ($value->get_attributes() as $name => $attribute) {
                        array_push($attributes, ['name' => $name, 'options' => $attribute->get_slugs()]);
                    }
                    $product['attributes'] = $attributes;
                    $product['thumbnail'] = wp_get_attachment_image_src(get_post_thumbnail_id($value->get_id()), 'single-post-thumbnail')[0];
                    array_push($data, $product);
                }
                echo json_encode(["products" => $data]);
                die();
                // instapunchout_create_o(json_decode(file_get_contents('php://input'),true));
                //}else {
                //    echo json_encode(["error" => "You're not authorized", "error_data" => $res]);
                // }
            } catch (Exception $e) {
                echo var_dump($e);
            }
        } else if (substr($url, $index, strlen($product_path)) === $product_path) {
            if (isset($_GET["sku"])) {
                $product_id = wc_get_product_id_by_sku($_GET["sku"]);
                if (!$product_id) {
                    header('Content-Type: application/json');
                    echo json_encode(["error" => "product not found, invalid SKU " . $_GET["sku"]]);
                    exit;
                }
                header('Location: ' . get_permalink($product_id));
                exit;
            } else if (isset($_GET["id"])) {
                $link = get_permalink((int) $_GET["id"]);
                if (!$link) {
                    header('Content-Type: application/json');
                    echo json_encode(["error" => "product not found, invalid ID " . $_GET["id"]]);
                    exit;
                }
                header('Location: ' . $link);
                exit;
            } else {
                header('Content-Type: application/json');
                echo json_encode(["error" => "sku or id is required"]);
                exit;
            }
        } else if (substr($url, $index, strlen($options_path_json)) === $options_path_json) {
            $data = [];

            global $wp_roles;
            $all_roles = $wp_roles->roles;
            $roles = [];
            foreach ($all_roles as $key => $value) {
                array_push($roles, ['label' => $value['name'], 'value' => $key]);
            }
            $data['roles'] = $roles;

            if (class_exists('Groups_Group')) {
                $groups = [];
                foreach (Groups_Group::get_groups() as $group) {
                    array_push($groups, ['label' => $group->name, 'value' => $group->group_id]);
                }
                $data['groups'] = $groups;
            }

            if (class_exists('B2bwhs') || class_exists('B2bkingcore') || class_exists('B2bking')) {
                $post_type = class_exists('B2bwhs') ? 'b2bwhs_group' : 'b2bking_group';
                $groups = [];
                $b2bwhs_groups = get_posts(
                    array(
                        'post_type' => $post_type,
                        'post_status' => 'publish',
                        'numberposts' => -1,
                    )
                );
                foreach ($b2bwhs_groups as $group) {
                    array_push($groups, ['label' => $group->post_title, 'value' => strval($group->ID)]);
                }
                $data['groups'] = $groups;
            }

            // Support WP-Memberships Plugin
            if (function_exists('wpmem_get_memberships')) {
                $memberships_data = wpmem_get_memberships();
                if ($memberships_data) {
                    $memberships = [];
                    foreach ($memberships_data as $membership) {
                        array_push($memberships, ['label' => $membership['title'], 'value' => $membership['name']]);
                    }
                    $data['memberships'] = $memberships;
                }
            }

            echo json_encode($data);
            exit;
        } else if (substr($url, $index, strlen($refunds_path_json)) === $refunds_path_json) {
            try {
                authorize();
                instapunchout_get_refunds($_GET["after"]);
            } catch (Exception $e) {
                echo var_dump($e);
            }
            exit;
        } else if (substr($url, $index, strlen($order_path_json)) === $order_path_json) {

            try {
                authorize();
                instapunchout_create_order(json_decode(file_get_contents('php://input'), true));
            } catch (Exception $e) {
                echo var_dump($e);
            }
            exit;
        } else if (substr($url, $index, strlen($order_status_path)) === $order_status_path) {

            try {
                authorize();
                instapunchout_update_order_status(json_decode(file_get_contents('php://input'), true));
            } catch (Exception $e) {
                echo var_dump($e);
            }
            exit;
        } else if (substr($url, $index, strlen($cart_path_json)) === $cart_path_json) {
            header('Content-Type: application/json');
            echo json_encode(instapunchout_get_cart());
            exit;
        } else if (substr($url, $index, strlen($cart_path)) === $cart_path) {

            $current_user_id = get_current_user_id();
            $punchout_id = get_user_meta($current_user_id, 'punchout_id', true);

            if (!isset($punchout_id)) {
                echo json_encode(['message' => "You're not in a punchout session"]);
                exit;
            }

            $cart = ['items' => instapunchout_get_cart(), 'currency' => get_woocommerce_currency()];

            // no need for further sanization as we need to capture all the request data as is
            $custom = json_decode(file_get_contents('php://input'), true);

            $body = ['cart' => ['Woocommerce' => $cart], 'custom' => $custom];
            $res = instapunchout_post_json('https://punchout.cloud/cart/' . $punchout_id, $body);

            if (isset($res['url'])) {
                WC()->cart->empty_cart();
                // wp_logout();
            }

            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        } else if (strpos($url, "/punchout") !== false) {

            // no need for further sanization as we need to capture all the server data as is
            $server = json_decode(json_encode($_SERVER), true);
            // no need for further sanization as we need to capture all the query data as is
            $query = json_decode(json_encode($_GET), true);

            $data = array(
                'headers' => getallheaders(),
                'server' => $server,
                'body' => file_get_contents('php://input'),
                'query' => $query,
            );

            $res = instapunchout_post_json('https://punchout.cloud/proxy', $data);
            if ($res['action'] == 'print') {
                header('content-type: application/xml');
                $xml = new SimpleXMLElement($res['body']);
                echo $xml->asXML();
            } else if ($res['action'] == 'login') {

                $user = instapunchout_prepare_user($res);

                // check if user is admin or super admin
                if (in_array('administrator', $user->roles) || in_array('super_admin', $user->roles)) {
                    die('You are not allowed to login as an admin');
                }

                if (isset($res['properties']['role'])) {
                    $role = $res['properties']['role'];
                    if (!in_array($role, (array) $user->roles)) {
                        $user->add_role($role);
                    }
                }

                if (isset($res['properties']['customer_role_disabled']) && $res['properties']['customer_role_disabled'] == true) {
                    $user->remove_role('customer');
                }

                if (isset($res['properties']['remove_roles'])) {
                    foreach ($res['properties']['remove_roles'] as $role) {
                        $user->remove_role($role);
                    }
                }

                if (isset($res['properties']['groups'])) {
                    $user_id = $user->ID;
                    $user_group_ids = $res['properties']['groups'];
                    if (class_exists('Groups_Group') && class_exists('Groups_User_Group')) {
                        $groups = Groups_Group::get_groups();
                        foreach ($groups as $group) {
                            if (in_array($group->group_id, $user_group_ids)) {
                                if (!Groups_User_Group::read($user_id, $group->group_id)) {
                                    Groups_User_Group::create(array('user_id' => $user_id, 'group_id' => $group->group_id));
                                }
                            } else {
                                if (Groups_User_Group::read($user_id, $group->group_id)) {
                                    Groups_User_Group::delete($user_id, $group->group_id);
                                }
                            }
                        }
                    }
                    if (class_exists('B2bwhs')) {
                        foreach ($user_group_ids as $group) {
                            update_user_meta($user->ID, 'b2bwhs_customergroup', (int) $group);
                            update_user_meta($user->ID, 'b2bwhs_b2buser', 'yes');
                        }
                    }
                    if (class_exists('B2bking') || class_exists('B2bkingcore')) {
                        foreach ($user_group_ids as $group) {
                            update_user_meta($user->ID, 'b2bking_customergroup', (int) $group);
                            update_user_meta($user->ID, 'b2bking_b2buser', 'yes');
                        }
                    }

                    if (isset($res['properties']['meta_input'])) {
                        foreach ($res['properties']['meta_input'] as $key => $value) {
                            update_user_meta($user->ID, $key, $value);
                        }
                    }
                }

                // Support WP-Memberships Plugin
                if (function_exists('wpmem_set_user_membership') && isset($res['properties']['memberships'])) {
                    foreach ($res['properties']['memberships'] as $membership) {
                        wpmem_set_user_membership($membership, $user->ID);
                    }
                }

                if ($user instanceof WP_User) {

                    WC()->session->delete_session($user->ID);

                    wp_clear_auth_cookie();
                    update_user_meta($user->ID, 'punchout_id', $res['punchout_id']);
                    wp_set_current_user($user->ID);
                    if (isset($res['properties']['custom_set_auth_cookie']) && $res['properties']['custom_set_auth_cookie'] == true) {
                        instapunchout_wp_set_auth_cookie($user->ID);
                    } else {
                        wp_set_auth_cookie($user->ID, false);
                    }

                    // empty cart
                    WC()->cart->empty_cart();

                    // cart edit
                    if (isset($res['properties']['cart_items'])) {
                        foreach ($res['properties']['cart_items'] as $cart_item) {
                            WC()->cart->add_to_cart($cart_item['product_id'], $cart_item['quantity'], $cart_item['variation_id']);
                        }
                    }

                    if (isset($res['redirect'])) {
                        header('Location: ' . $res['redirect']);
                    } else {
                        wp_redirect(home_url());
                    }
                    exit();
                } else {
                    wp_redirect(home_url() . '?user_doesnt_exist');
                    exit();
                }

                header('Location: /?v28');
            } else {
                echo "v0.0.28 unknwon action " . esc_html($res['action']);
                echo json_encode($data);
                echo json_encode($res);
            }
            exit;
        }
    }
}

function authorize()
{
    header('Content-Type: application/json');
    $authorization_header = getallheaders()["Authorization"];
    if (isset($_GET["authorization_header"])) {
        $authorization_header = $_GET["authorization_header"];
    }
    $res = instapunchout_post_json('https://punchout.cloud/authorize', ["authorization" => $authorization_header]);
    if ($res["authorized"] == true) {
        return true;
    } else {
        echo json_encode(["error" => "You're not authorized", "error_data" => $res, "header" => $authorization_header]);
        exit;
    }
}

function instapunchout_get_cart()
{

    try {
        $cart = WC()->cart->get_cart();
        $version2 = substr(WC()->version, 0, strlen("2.3.")) === "2.3.";

        if ($version2) {
            foreach ($cart as $key => $item) {
                // Adds the product name as a new variable.
                $cart[$key]['name'] = $item['data']->post->post_title;

                $cart[$key]['sku'] = $item['product_id'];
                // support for plugin "advanced-custom-fields"
                if (function_exists('get_fields')) {
                    $cart[$key]['custom'] = get_fields($cart[$key]['product_id']);
                }
                // $cart[$key]['shipping_amount'] = $shipping_amout;
            }
        } else {

            if (method_exists(WC()->cart, 'get_shipping_total')) {
                $shipping_amount = WC()->cart->get_shipping_total();
            } else if (isset(WC()->cart->shipping_total)) {
                $shipping_amount = WC()->cart->shipping_total;
            }

            foreach ($cart as $key => $item) {
                $_product = apply_filters('wc_cart_rest_api_cart_item_product', $item['data'], $item, $key);

                // Adds the product name as a new variable.
                $cart[$key]['name'] = $_product->get_name();

                $cart[$key]['sku'] = $_product->get_sku();

                $cart[$key]['product'] = $_product->get_data();

                // get product and its parent attributes
                $cart[$key]['product']['attributes'] = instapunchout_get_product_attributes($_product);

                $cart[$key]['product']['categories'] = [];
                $categories = get_the_terms($_product->get_id(), 'product_cat');
                if ($categories) {
                    $cart[$key]['product']['categories'] = $categories;
                }

                // add parent product categories
                $parent_id = $_product->get_parent_id();
                if ($parent_id) {
                    $parent_categories = get_the_terms($parent_id, 'product_cat');
                    if ($parent_categories) {
                        $cart[$key]['product']['categories'] = array_merge($cart[$key]['product']['categories'], $parent_categories);
                    }
                }

                // support for plugin "advanced-custom-fields"
                if (function_exists('get_fields')) {
                    $cart[$key]['custom'] = get_fields($cart[$key]['product_id']);
                }
                $cart[$key]['shipping_amount'] = $shipping_amount;
            }
        }

        // add shipping address
        try {
            $address = [
                'first_name' => WC()->cart->get_customer()->get_shipping_first_name(),
                'last_name' => WC()->cart->get_customer()->get_shipping_last_name(),
                'company' => WC()->cart->get_customer()->get_shipping_company(),
                'country' => WC()->cart->get_customer()->get_shipping_country(),
                'state' => WC()->cart->get_customer()->get_shipping_state(),
                'postcode' => WC()->cart->get_customer()->get_shipping_postcode(),
                'city' => WC()->cart->get_customer()->get_shipping_city(),
                'address' => WC()->cart->get_customer()->get_shipping_address(),
                'address_2' => WC()->cart->get_customer()->get_shipping_address_2(),
            ];

            $cart['shipping_address'] = $address;
        } catch (Exception $e) {
        }

        try {
            $cart['discount_total'] = WC()->cart->get_discount_total();
        } catch (Exception $e) {

        }

        return $cart;
    } catch (Exception $e) {
        echo var_dump($e);
        exit();
    }
}

function instapunchout_get_product_attributes($_product)
{
    $attributes = [];
    foreach ($_product->get_attributes() as $name => $attribute) {
        $value = null;
        if (method_exists($attribute, 'get_slugs')) {
            $value = $attribute->get_slugs();
        } else if (!is_object($attribute)) {
            $value = $attribute;
        } else {
            $value = json_encode($attribute);
        }
        array_push($attributes, ['name' => $name, 'value' => $value]);
    }

    $parent_id = $_product->get_parent_id();
    if ($parent_id) {
        $_pf = new WC_Product_Factory();
        $_product = $_pf->get_product($parent_id);

        $parent_attributes = instapunchout_get_product_attributes($_product);
        $attributes = array_merge($attributes, $parent_attributes);
    }
    return $attributes;
}

function instapunchout_prepare_user($res)
{
    $GLOBALS['__itsec_recaptcha_cached_result'] = true;
    $email = $res['email'];
    $user = get_user_by('email', $email);
    if (!$user) {
        // fix for nonce_verification_failed caused by Dokan Plugin (dokan-lite)
        add_filter('dokan_register_nonce_check', '__return_false');
        add_filter('c4wp_get_option', '__return_false');
        add_filter('anr_get_option', '__return_false');
        // fix for 'Security violated' for B2B for Woocommerce Plugin
        $_REQUEST['afreg_nonce_field'] = $_POST['afreg_nonce_field'] = wp_create_nonce('afreg_nonce_action');

        // prevents cloudflare turnstile from blocking the request
        define('REST_REQUEST', true);

        $_REQUEST['terms'] = $_POST['terms'] = true;

        $_POST['billing_first_name'] = $res['properties']['first_name'];
        $_POST['billing_last_name'] = $res['properties']['last_name'];

        if (isset($res['properties']['billing_phone'])) {
            $_POST['billing_phone'] = $res['properties']['billing_phone'];
        }
        $user_id = wc_create_new_customer($email, $res['username'], $res['password'], $res['properties']);
        if (is_wp_error($user_id)) {
            die("Failed to create user " . var_dump($user_id));
        }
        $user = get_user_by('email', $email);
    }
    return $user;
}

function instapunchout_create_order($data)
{
    $email = $data['customer_email'];
    $user = instapunchout_prepare_user($data['customer']);
    $admin = get_users(
        array(
            'role__in' => 'administrator',
            'fields' => 'ID',
        )
    )[0];

    if ($user instanceof WP_User) {
        wp_clear_auth_cookie();
        wp_set_current_user($admin);
        wp_set_auth_cookie($admin);
        $data['customer_id'] = $user->ID;
    } else {
        echo json_encode(["error" => "user doesn't exist " . $email]);
        exit();
    }

    foreach ($data['line_items'] as &$item) {
        if (!$item['product_id']) {
            $product_id = wc_get_product_id_by_sku($item['sku']);
            if (!$product_id) {
                echo json_encode(["error" => "product not found, invalid SKU " . $item['sku']]);
                exit;
            }
            $item['product_id'] = $product_id;
            $item['variation_id'] = 0;
        }
        unset($item['sku']);
    }

    $request = new WP_REST_Request('POST', '/wc/v3/orders');
    $request->set_header('content-type', 'application/json');
    $request->set_body(json_encode($data));
    $response = rest_do_request($request);
    $server = rest_get_server();
    $order = $server->response_to_data($response, false);
    echo json_encode($order);
}

function instapunchout_update_order_status($data)
{
    $order = new WC_Order($data['id']);
    $order->update_status($data['status']);
    update_post_meta($data['id'], 'po_number', $data['po_number']);
    echo json_encode(['id' => $order->ID]);
}

function instapunchout_get_refunds($timestamp)
{
    $args = array(
        'type' => 'shop_order_refund',
        'date_created' => '>' . $timestamp,
    );
    $refunds = wc_get_orders($args);
    $data = [];
    foreach ($refunds as $refund) {
        $order = $refund->get_data();
        $parentOrder = wc_get_order($order['parent_id']);
        $parent = $parentOrder->get_data();
        $parent['line_items'] = [];
        foreach ($parentOrder->get_items() as $item) {
            $parent['line_items'][] = $item->get_data();
        }
        $order['parent'] = $parent;
        $order['line_items'] = [];
        foreach ($refund->get_items() as $item) {
            $order['line_items'][] = $item->get_data();
        }
        $data[] = $order;
    }
    echo wp_json_encode(['refunds' => $data]);
}

add_filter('woocommerce_set_cookie_options', 'instapunchout_woocommerce_set_cookie_options_filter', 10, 3);
function instapunchout_woocommerce_set_cookie_options_filter($cookie_options, $name, $value)
{
    $cookie_options['secure'] = 1;
    $cookie_options['samesite'] = 'None';
    return $cookie_options;
}


function instapunchout_wp_set_auth_cookie($user_id)
{
    $expiration = time() + apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, false);
    $expire = 0;
    $auth_cookie_name = SECURE_AUTH_COOKIE;
    $scheme = 'secure_auth';

    $manager = WP_Session_Tokens::get_instance($user_id);
    $token = $manager->create($expiration);

    $auth_cookie = wp_generate_auth_cookie($user_id, $expiration, $scheme, $token);
    $logged_in_cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);

    do_action('set_auth_cookie', $auth_cookie, $expire, $expiration, $user_id, $scheme, $token);
    do_action('set_logged_in_cookie', $logged_in_cookie, $expire, $expiration, $user_id, 'logged_in', $token);


    instapunchout_setcookie($auth_cookie_name, $auth_cookie);
    instapunchout_setcookie(LOGGED_IN_COOKIE, $logged_in_cookie);
}

function instapunchout_setcookie($name, $value)
{
    $date = date("D, d M Y H:i:s", time() + 3600 * 6) . 'GMT';
    header("Set-Cookie: {$name}={$value}; expires={$date};SameSite=None;Secure;HttpOnly");
}