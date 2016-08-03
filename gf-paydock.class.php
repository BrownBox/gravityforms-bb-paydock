<?php
if (method_exists('GFForms', 'include_payment_addon_framework')) {
    GFForms::include_payment_addon_framework();
    class GFPayDock extends GFPaymentAddOn {
        protected $_version = GF_PAYDOCK_VERSION;
        protected $_min_gravityforms_version = "1.8";
        protected $_slug = "PayDock";
        protected $_path = "gravityforms-bb-paydock/paydock.php";
        protected $_full_path = __FILE__;
        protected $_title = "Gravity Forms Brown Box PayDock Add-On";
        protected $_short_title = "PayDock";
        protected $_supports_callbacks = true;
        protected $_requires_credit_card = false;

        private $sandbox_endpoint = 'https://api-sandbox.paydock.com/v1/';
        private $production_endpoint = 'https://api.paydock.com/v1/';

        private static $_instance = null;

        public static function get_instance() {
            if ( self::$_instance == null ) {
                self::$_instance = new self;
            }

            return self::$_instance;
        }

        public function init() {
            parent::init();
            add_filter("gform_field_value_feed_reference", array($this, "generate_random_number"));
            add_filter("gform_field_value_main_reference", array($this, "generate_random_main_number"));
        }

        public function plugin_page() {
?>
<a href="http://thepaydock.com" target="_blank"><img src="<?php echo plugin_dir_url(__FILE__).'/img/paydock_small.png' ?>"></a>
<p>PayDock is a revolutionary way to integrate recurring and one-off payments into your website, regardless of gateway and free from hassle.</p>
<p>PayDock settings are managed on a per-form basis.</p>
<p><a href="http://docs.thepaydock.com">Click here</a> for API documentation or <a href="mailto:support@thepaydock.com">email PayDock for support</a>.</p>
<?php
        }

        public function feed_settings_fields() {
            $pd_options = $this->get_plugin_settings();

            $environments = array();
            $environments['sandbox']['uri'] = $this->sandbox_endpoint;
            $environments['sandbox']['key'] = $pd_options['pd_sandbox_api_key'];

            $environments['production']['uri'] = $this->production_endpoint;
            $environments['production']['key'] = $pd_options['pd_production_api_key'];

            $gateways_select = array();

            foreach ($environments as $env => $details) { // maybe this is crap but the only two options we should ever have is 2 x API keys
                if (strlen($details['key']) == 40) { // should be 40 character key
                    $curl_header = array();
                    $curl_header[] = 'x-user-token:' . $details['key'];
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $details['uri'] . 'gateways/');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // this one is important
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    $result = curl_exec($ch);
                    curl_close($ch);

                    $json_string = json_decode($result, true);
                    $gateways = $json_string['resource']['data'];

                    foreach ($gateways as $gateway) {
                        if (strpos($details['uri'], 'sandbox')) {
                            $gateway['name'] = '[Sandbox Account Gateway] ' . $gateway['name'];
                        } else {
                            $gateway['name'] = '[Production Account Gateway] ' . $gateway['name'];
                        }
                        $gateways_select[] = array(
                                "label" => $gateway['name'],
                                "value" => $gateway['_id']
                        );
                    }
                } else {
                    echo "There is something wrong with your ".$env." PayDock API Key. Please check your settings again or contact support.";
                }
            }

            return array(
                    array(
                            "title" => "PayDock Feed Settings",
                            "fields" => array(
                                    array(
                                            "label" => "Feed name",
                                            "type" => "text",
                                            "name" => "feedName",
                                            "tooltip" => "Give this feed a helpful name",
                                            "class" => "medium",
                                    ),
                                    array(
                                            "label" => "Select Gateway",
                                            "type" => "select",
                                            "name" => "pd_select_gateway",
                                            "tooltip" => "Select which gateway you wish to push this feed to",
                                            "choices" => $gateways_select,
                                            'required' => true,
                                    ),
//                                     array(
//                                             "label" => "Feed Reference",
//                                             "type" => "text",
//                                             "name" => "pd_reference",
//                                             "tooltip" => "Use this to add a reference to your submission.",
//                                             "class" => "medium",
//                                     ),
                                    array(
                                            "label" => "Send to Production",
                                            "type" => "checkbox",
                                            "name" => "pd_send_to_production",
                                            "tooltip" => "Check this box to route payments to your production environment.",
                                            "choices" => array(
                                                    array(
                                                            "label" => "Send payments to your PayDock Production account, otherwise we'll shoot the payments off to Sandbox (make sure you've entered your API keys).",
                                                            "name" => "pd_send_to_production",
                                                    ),
                                            ),
                                    ),
                                    array( // Can't override choices if it's part of the field_map below
                                            "name" => "pd_total_payable",
                                            "type" => "select",
                                            "label" => "Amount",
                                            "required" => true,
                                            "choices" => $this->productFields(),
                                    ),
                                    array(
                                            "name" => "pd_currency",
                                            "type" => "select",
                                            "label" => "Currency",
                                            "choices" => $this->currencyOptions(),
                                    ),
                                    array(
                                            "name" => "pd_payment_type",
                                            "type" => "select",
                                            "label" => "Payment Type",
                                            "required" => false,
                                            'choices' => array(
            	                                    array(
                                                            'value' => '',
            	                                            'label' => 'Credit Card',
                                                    ),
            	                                    array(
                                                            'value' => 'bsb',
            	                                            'label' => 'Direct Debit',
                                                    ),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_personal_mapped_details",
                                            "label" => "Personal Details",
                                            "type" => "field_map",
                                            "field_map" => array(
                                                    array(
                                                            "name" => "pd_email",
                                                            "label" => "Email",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_first_name",
                                                            "label" => "First Name",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_last_name",
                                                            "label" => "Last Name",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_phone",
                                                            "label" => "Phone",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_line1",
                                                            "label" => "Address Line 1",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_line2",
                                                            "label" => "Address Line 2",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_city",
                                                            "label" => "City",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_state",
                                                            "label" => "State",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_postcode",
                                                            "label" => "Postcode",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_address_country",
                                                            "label" => "Country",
                                                            "required" => false,
                                                    ),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_payment_mapped_details",
                                            "label" => "Payment Details",
                                            "type" => "field_map",
                                            "field_map" => array(
                                                    array(
                                                            "name" => "pd_transaction_reference",
                                                            "label" => "Transaction Reference",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_description",
                                                            "label" => "Description",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_payment_source",
                                                            "label" => "Payment Source",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_account_name",
                                                            "label" => "Account Name",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_account_bsb",
                                                            "label" => "Account BSB",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_account_number",
                                                            "label" => "Account Number",
                                                            "required" => false,
                                                    ),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_payment_type_mapped_details",
                                            "label" => "Payment Schedule",
                                            "type" => "field_map",
                                            "field_map" => array(
                                                    array(
                                                            "name" => "pd_payment_frequency",
                                                            "label" => "Payment Frequency",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_payment_interval",
                                                            "label" => "Payment Interval",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_payment_start_date",
                                                            "label" => "Payment Start Date",
                                                            "required" => false,
                                                    ),
                                                    array(
                                                            "name" => "pd_payment_end_date",
                                                            "label" => "Payment End Date",
                                                            "required" => false,
                                                    ),
                                            ),
                                    ),
                                    array(
                                            "name" => "condition",
                                            "label" => __("Condition", "gravityforms-bb-paydock"),
                                            "type" => "feed_condition",
                                            "checkbox_label" => __('Enable Condition', 'gravityforms-bb-paydock'),
                                            "instructions" => __("Process this PayDock feed if", "gravityforms-bb-paydock"),
                                    ),
                            ),
                    ),
            );
        }

        //this function return products fields and total field
        protected function productFields() {
            $form = $this->get_current_form();
            $fields = $form['fields'];
            $default_settings = array();

            $check_total_exist = 0; // if field total does not exist
            array_push($default_settings, array(
                    "value" => "",
                    "label" => "Select a Field"
            ));

            // If we have BB Cart, we can get amount from there
            if (defined('BB_CART_SESSION_ITEM')) {
                $default_settings[] = array(
                        'value' => 'bb_cart',
                        'label' => 'BB Cart',
                );
            }

            foreach ($fields as $key => $field) {
                if ($field['type'] == 'product' || $field['type'] == 'total') {
                    if ($field['type'] == 'total') {
                        $check_total_exist = 1; //total exists.
                    }
                    $field_settings = array();
                    $field_settings['value'] = $field['id'];
                    $field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
                    array_push($default_settings, $field_settings);
                } elseif ($field['type'] == 'envoyrecharge') {
                    $field_settings = array();
                    $field_settings['value'] = $field['id'].'.1';
                    $field_settings['label'] = __($field['label'].' [Amount]', 'gravityforms-bb-paydock');
                    array_push($default_settings, $field_settings);
                } elseif ($field['type'] == 'bb_click_array') {
                    $field_settings = array();
                    $field_settings['value'] = $field['id'].'.1';
                    $field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
                    array_push($default_settings, $field_settings);
                }
            }

            //check if field total don't exist then add it
            if ($check_total_exist == 0) {
                $field_settings = array();
                $field_settings['value'] = 'total';
                $field_settings['label'] = __('Total', 'gravityforms-bb-paydock');
                array_push($default_settings, $field_settings);
            }
            return $default_settings;
        }

        /**
         * List of options for Currency setting
         * @return array
         */
        protected function currencyOptions() {
            $form = $this->get_current_form();
            $fields = $form['fields'];
            $default_settings = array();

            array_push($default_settings, array(
                    "value" => "",
                    "label" => "Default (from GF Settings)",
            ));

            foreach ($fields as $key => $field) {
                $field_settings = array();
                $field_settings['value'] = $field['id'];
                $field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
                array_push($default_settings, $field_settings);
            }
            return $default_settings;
        }

        public function plugin_settings_fields() {
            return array(
                    array(
                            "title" => "Add your PayDock API keys below",
                            "fields" => array(
                                    array(
                                            "name" => "pd_production_api_key",
                                            "tooltip" => "Add your API key from My Account -> API & Settings",
                                            "label" => "PayDock Production API Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                                    array(
                                            "name" => "pd_sandbox_api_key",
                                            "tooltip" => "Add your API key from My Account -> API & Settings",
                                            "label" => "PayDock Sandbox API Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    )
                            )
                    )
            );
        }

        public function feed_list_columns() {
            return array(
                'feedName' => __('Name', 'gravityforms-bb-paydock'),
            );
        }

        public function get_submission_data($feed, $form, $entry) {
    		$form_data = array();

    		$form_data['form_title'] = $form['title'];

    		//getting mapped field data
    		$billing_fields = $this->billing_info_fields();
    		foreach ($billing_fields as $billing_field) {
    			$field_name             = $billing_field['name'];
    			$input_id               = rgar($feed['meta'], "billingInformation_{$field_name}");
    			$form_data[$field_name] = $this->get_field_value($form, $entry, $input_id);
    		}

    		//getting credit card field data
    		$card_field = $this->get_credit_card_field($form);
    		if ($card_field) {
    			$form_data['card_number']          = $this->remove_spaces_from_card_number(rgpost("input_{$card_field->id}_1"));
    			$form_data['card_expiration_date'] = rgpost("input_{$card_field->id}_2");
    			$form_data['card_security_code']   = rgpost("input_{$card_field->id}_3");
    			$form_data['card_name']            = rgpost("input_{$card_field->id}_5");
    		}

    		//getting product field data
    		$order_info = $this->get_order_data($feed, $form, $entry);
    		$form_data  = array_merge($form_data, $order_info);

    		// Hack to allow it to process the feed
    		if ($form_data['payment_amount'] == 0) {
    		    $form_data['payment_amount'] = 1;
    		}

    		return $form_data;
        }

        public function authorize($feed, $submission_data, $form, $entry) {
            $data = array();

            $payment_type = $feed["meta"]["pd_payment_type"];
            if ($payment_type == "bsb") {
                $data["customer"]["payment_source"]["type"] = "bsb";
                $data["customer"]["payment_source"]["account_name"] = $entry[$feed["meta"]["pd_payment_mapped_details_pd_account_name"]];
                $data["customer"]["payment_source"]["account_bsb"] = str_replace('-', '', $entry[$feed["meta"]["pd_payment_mapped_details_pd_account_bsb"]]);
                $data["customer"]["payment_source"]["account_number"] = $entry[$feed["meta"]["pd_payment_mapped_details_pd_account_number"]];
            } else {
                $data["customer"]["payment_source"]["card_name"] = $submission_data['card_name'];
                $data["customer"]["payment_source"]["card_number"] = $submission_data['card_number'];
                $ccdate_array = $submission_data['card_expiration_date'];
                $ccdate_month = $ccdate_array[0];
                if (strlen($ccdate_month) < 2)
                    $ccdate_month = '0' . $ccdate_month;
                $ccdate_year = $ccdate_array[1];
                if (strlen($ccdate_year) > 2)
                    $ccdate_year = substr($ccdate_year, -2); // Only want last 2 digits
                $data["customer"]["payment_source"]["expire_month"] = $ccdate_month;
                $data["customer"]["payment_source"]["expire_year"] = $ccdate_year;
                $data["customer"]["payment_source"]["card_ccv"] = $submission_data['card_security_code'];
            }

            $phone = '';
            if (!empty($entry[$feed["meta"]["pd_personal_mapped_details_pd_phone"]])) {
                $phone = $entry[$feed["meta"]["pd_personal_mapped_details_pd_phone"]];
                if (strpos($phone, '0') === 0) {
                    $phone = substr($phone, 1);
                }
                if (strpos($phone, '+') === false) {
                    $phone = '+61'.$phone;
                }
            }

            $data["customer"]["payment_source"]["gateway_id"] = $feed["meta"]["pd_select_gateway"];
            $data["customer"]["first_name"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_first_name"]];
            $data["customer"]["last_name"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_last_name"]];
            $data["customer"]["email"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_email"]];
            $data["customer"]["phone"] = $phone;
            $data["customer"]["payment_source"]["address_line1"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_line1"]];
            $data["customer"]["payment_source"]["address_line2"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_line2"]];
            $data["customer"]["payment_source"]["address_city"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_city"]];
            $data["customer"]["payment_source"]["address_state"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_state"]];
            $data["customer"]["payment_source"]["address_postcode"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_postcode"]];
            $data["customer"]["payment_source"]["address_country"] = $entry[$feed["meta"]["pd_personal_mapped_details_pd_address_country"]];
            $data["reference"] = $entry[$feed["meta"]["pd_payment_mapped_details_pd_transaction_reference"]];
            $data["description"] = $entry[$feed["meta"]["pd_payment_mapped_details_pd_description"]];
            $data["currency"] = (!empty($entry[$feed["meta"]["pd_currency"]])) ? $entry[$feed["meta"]["pd_currency"]] : GFCommon::get_currency();

            $pd_options = $this->get_plugin_settings();

            if ($feed['meta']['pd_send_to_production'] == "1") {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $feed_gateway_key = $feed['meta']['pd_select_gateway'];
            $_SESSION['PD_GATEWAY'] = $feed_gateway_key;

            $transactions = array();
            $interval = $entry[$feed["meta"]["pd_payment_type_mapped_details_pd_payment_interval"]];
            if (empty($interval)) {
                $interval = 'one-off';
            }
            $amount_field = $feed["meta"]["pd_total_payable"];
            if ($amount_field == 'bb_cart') {
                if (!empty($_SESSION[BB_CART_SESSION_ITEM])) {
                    $cart_items = $_SESSION[BB_CART_SESSION_ITEM];
                    if (!empty($cart_items['woo']) && class_exists('WC_Product_Factory')) { // WooCommerce
                        if (!isset($transactions['one-off']))
                            $transactions['one-off'] = 0;
                        foreach ($cart_items['woo'] as $product) {
                            $product_factory = new WC_Product_Factory();
                            $prod_obj = $product_factory->get_product($product['product_id']);
                            $transactions['one-off'] += $prod_obj->get_price_excluding_tax($product['quantity']);
                        }
                        unset($cart_items['woo']);
                        $transactions['one-off'] += bb_cart_calculate_shipping();
                    }
                    if (!empty($cart_items['event'])) { // Event Manager Pro
                        if (!isset($transactions['one-off']))
                            $transactions['one-off'] = 0;
                        foreach ($cart_items['event'] as $event) {
                            $transactions['one-off'] += $event['booking']->booking_price;
                        }
                        unset($cart_items['event']);
                    }
                    if (!empty($cart_items)) { // BB Cart
                        foreach ($cart_items as $cart_item) {
                            if (!isset($transactions[$cart_item['frequency']]))
                                $transactions[$cart_item['frequency']] = 0;
                            $transactions[$cart_item['frequency']] += $cart_item['price']/100;
                        }
                    }
                }
            } elseif ($amount_field == 'total') {
                foreach ($form["fields"] as $key => $field) {
                    if ($field['type'] == 'product') {
                        switch ($field['inputType']) {
                        	case 'singleproduct':
                        	    $amount = $this->clean_amount($entry[$field['id'].'.2'])/100;
                        	    break;
                        	default:
                        	    $amount = $this->clean_amount($entry[$field['id']])/100;
                        	    break;
                        }
                        if (!isset($transactions[$interval])) {
                            $transactions[$interval] = 0;
                        }
                        $transactions[$interval] += $amount;
                    } elseif ($field['type'] == 'envoyrecharge') {
                        if (rgpost('input_' . $field['id'].'_5') == 'recurring') {
                            $ech_interval = rgpost('input_' . $field['id'].'.2');
                        } else {
                            $ech_interval = $interval;
                        }
                        if (!isset($transactions[$ech_interval])) {
                            $transactions[$ech_interval] = 0;
                        }
                        $transactions[$ech_interval] += $this->clean_amount($entry[$field['id'].'.1'])/100;
                    } elseif ($field['type'] == 'bb_click_array') {
                        if (!isset($transactions[$interval])) {
                            $transactions[$interval] = 0;
                        }
                        $transactions[$interval] += $this->clean_amount($entry[$field['id'].'.1'])/100;
                    }
                }
            } else {
                $transactions[$interval] = $this->clean_amount($entry[$amount_field])/100;
            }

            if (empty($transactions)) {
                $error_message = 'No amounts found to process';
                $auth = array(
                        'is_authorized' => false,
                        'transaction_id' => null,
                        'error_message' => $error_message,
                );

                $GLOBALS['pd_error'] = $error_message;

                add_filter('gform_validation_message', array($this, 'change_message'), 10, 2);
            }

            foreach ($transactions as $interval => $amount) {
                if ($amount <= 0) {
                    $error_message = 'Amount must be greater than zero';
                    $auth = array(
                            'is_authorized' => false,
                            'transaction_id' => null,
                            'error_message' => $error_message,
                    );

                    $GLOBALS['pd_error'] = $error_message;

                    add_filter('gform_validation_message', array($this, 'change_message'), 10, 2);
                    continue;
                }
                $data['amount'] = $amount;
                // Check if it's a subscription or not
                if ($interval != 'one-off') {
                    // Set the right API endpoint
                    $api_url = $feed_uri . 'subscriptions/';
                    // Set add the schedule item
                    $data["schedule"]["frequency"] = $entry[$feed["meta"]["pd_payment_type_mapped_details_pd_payment_frequency"]];
                    $data["schedule"]["interval"] = $interval;

                    $start_date = $entry[$feed["meta"]["pd_payment_type_mapped_details_pd_payment_start_date"]];
                    if ($start_date != "") {
                        $data["schedule"]["start_date"] = $start_date;
                    }

                    $end_date = $entry[$feed["meta"]["pd_payment_type_mapped_details_pd_payment_end_date"]];
                    if ($end_date != "") {
                        $data["schedule"]["end_date"] = $end_date;
                    }
                } else {
                    $api_url = $feed_uri . 'charges/';
                }

                $data_string = json_encode($data);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'x-user-token:' . $request_token,
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data_string)
                ));
                $result = curl_exec($ch);
                curl_close($ch);

                $response = json_decode($result);

                $GLOBALS['transaction_id'] = $GLOBALS['pd_error'] = "";

                if (!is_object($response) || $response->status > 201 || $response->_code > 250) {
                    if ($response == null || $response == '') {
                        $error_message = __('An unknown error occured. No response was received from the gateway. This is probably a temporary connection issue - please try again.', 'gravityforms-bb-paydock');
                    } else {
                        if (is_string($response)) {
                            $error_message = __($response, 'gravityforms-bb-paydock');
                        } elseif (!empty($response->error->message)) {
                            $error_message = __($response->error->message, 'gravityforms-bb-paydock');
                        } elseif (property_exists($response->error, 'details')) {
                            if (!is_object($response->error->details[0])) {
                                $error_message = __($response->error->details[0], 'gravityforms-bb-paydock');
                            }
                        } else {
                            $error_message = __('An unknown error occured. Please try again.', 'gravityforms-bb-paydock');
                        }
                    }
                    $GLOBALS['pd_error'] = $error_message;

                    add_filter('gform_validation_message', array($this, 'change_message'), 10, 2);

                    // set the form validation to false
                    $auth = array(
                            'is_authorized' => false,
                            'transaction_id' => $response->resource->data->_id,
                            'error_message' => $error_message,
                    );

                    foreach ($form['fields'] as &$field) {
                        if ($field->cssClass == 'pd-show-error') {
                            $field->failed_validation = true;
                            $field->validation_message = 'There was a problem processing your payment. Please try again or contact us.';
                            break;
                        }
                    }
                    break;
                } else {
                    $GLOBALS['transaction_id'] = $response->resource->data->_id;

                    add_action("gform_after_submission", array($this, "paydock_post_purchase_actions"), 99, 2);

                    $auth = array(
                            'is_authorized' => true,
                            'transaction_id' => $response->resource->data->_id,
                            'amount' => $amount,
                    );
                }
            }
            return $auth;
        }

        // @todo make this work
//     	protected function get_validation_result($validation_result, $authorization_result) {
//     		$credit_card_page = 0;
//     		foreach ($validation_result['form']['fields'] as &$field) {
//     			if ($field->type == 'creditcard') {
//     				$field->failed_validation  = true;
//     				$field->validation_message = $authorization_result['error_message'];
//     				$credit_card_page          = $field->pageNumber;
//     				break;
//     			}
//     		}

//     		$validation_result['credit_card_page'] = $credit_card_page;
//     		$validation_result['is_valid']         = false;
//     		$validation_result["form"]["error"]    = $authorization_result['error_message'];

//     		return $validation_result;
//     	}

        // @todo replace with get_validation_result() above
        public function change_message($message, $form) {
            return '<div class="validation_error">Error processing transaction: '.$GLOBALS['pd_error'].'.</div>';
        }

        // THIS IS OUR FUNCTION FOR CLEANING UP THE PRICING AMOUNTS THAT GF SPITS OUT
        public function clean_amount($entry) {
            $entry = preg_replace("/\|(.*)/", '', $entry); // replace everything from the pipe symbol forward
            if (strpos($entry, '.') === false) {
                $entry .= ".00";
            }
            if (strpos($entry, '$') !== false) {
                $startsAt = strpos($entry, "$") + strlen("$");
                $endsAt = strlen($entry);
                $amount = substr($entry, 0, $endsAt);
                $amount = preg_replace("/[^0-9,.]/", "", $amount);
            } else {
                $amount = preg_replace("/[^0-9,.]/", "", $entry);
                $amount = sprintf("%.2f", $amount);
            }

            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '', $amount);
            return $amount;
        }

        public function generate_random_number($value) {
            $to_return = "stfcf-" . mt_rand();
            return $to_return;
        }

        public function generate_random_main_number($value) {
            $to_return = "main-form-" . mt_rand();
            return $to_return;
        }

        public function paydock_post_purchase_actions($entry, $form) {
            foreach ($form['fields'] as $field) {
                if ($field['type'] == 'total') {
                    $amount = $entry[$field['id']];
                }
            }
            gform_update_meta($entry['id'], 'payment_status', 'Approved');
            gform_update_meta($entry['id'], 'payment_amount', $amount);

            GFAPI::update_entry_property($entry['id'], 'payment_amount', $amount);
            GFAPI::update_entry_property($entry['id'], 'payment_status', 'Approved');
            GFAPI::update_entry_property($entry['id'], 'transaction_id', $GLOBALS['transaction_id']);

            unset($_SESSION['PD_GATEWAY']);
        }

        public function get_subscription($sub_id, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_subscriptions_by_customer($customer_id, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/?customer_id='.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_subscriptions_by_email($email, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/?status=active&search='.$email);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function update_subscription($sub_id, array $data, $production = false) {
            $data_string = json_encode($data);
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function delete_subscription($sub_id, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_charge($charge_id, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'charges/'.$charge_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_charges_by_email($email, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'charges/?search='.$email);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_customers($production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_customer($customer_id, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/'.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function update_customer($customer_id, array $data, $production = false) {
            $data_string = json_encode($data);
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/'.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'x-user-token:' . $request_token,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
            ));
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function charge_belongs_to_email($charge_id, $email, $production) {
            $charge = $this->get_charge($charge_id, $production);
            return $charge->resource->data->customer->email == $email;
        }

        public function subscription_belongs_to_email($sub_id, $email, $production) {
            $subscription = $this->get_subscription($sub_id, $production);
            return $subscription->resource->data->customer->email == $email;
        }
    }
}
