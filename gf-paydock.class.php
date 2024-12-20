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
        private $gateways = array();
        private $environments = array();

        private static $_instance = null;

        public static function get_instance() {
            if ( self::$_instance == null ) {
                self::$_instance = new self;
            }

            return self::$_instance;
        }

        public function init() {
            add_filter("gform_field_value_feed_reference", array($this, "generate_random_number"));
            add_filter("gform_field_value_main_reference", array($this, "generate_random_main_number"));
            add_action('gform_admin_pre_render', array($this, 'add_merge_tags'));
            add_filter('gform_replace_merge_tags', array($this, 'replace_merge_tags'), 10, 7);
            add_action('profile_update', array($this, 'update_email_address'), 10, 2);
            add_action('bbconnect_merge_users', array($this, 'update_email_address'), 10, 2);

            $pd_options = $this->get_plugin_settings();
			if (!empty($pd_options['pd_sandbox_api_key'])) {
				$this->environments['sandbox'] = array(
						'uri' => $this->sandbox_endpoint,
						'key' => $pd_options['pd_sandbox_api_key'],
				);
			}
			if (!empty($pd_options['pd_production_api_key'])) {
				$this->environments['production'] = array(
						'uri' => $this->production_endpoint,
						'key' => $pd_options['pd_production_api_key'],
				);
			}

            parent::init();
        }

        public function feed_settings_fields() {
            $pd_options = $this->get_plugin_settings();
            $this->load_gateways();
            return array(
                    array(
                            "title" => "Feed Settings",
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
                                            "choices" => $this->gatewayOptions(),
                                            'required' => true,
                                    ),
                                    array(
                                            "label" => "Don't Create Subscriptions",
                                            "type" => "checkbox",
                                            "name" => "pd_dont_create_subscriptions",
                                            "tooltip" => "Selecting this option will force the system to process all payments as one-off; no subscriptions will be created. Only select this if you have an integration in place with an external system (e.g. CRM) which is going to manage recurring payments.",
                                            "choices" => array(
                                                    array(
                                                            "label" => "Only create one-off charges in PayDock, not subscriptions.",
                                                            "name" => "pd_dont_create_subscriptions",
                                                    ),
                                            ),
                                    ),
                            		array(
                            				"label" => "Additional Fraud Protection",
                            				"type" => "select",
                            				"name" => "pd_fraud_protection",
                            				"tooltip" => "This option will instruct PayDock to trigger your gateway's fraud protection. Only some gateways support this, and in most cases it must be configured on the gateway side first. Only enable this if you are certain the gateway is configured accordingly.",
                            				'choices' => array(
                            						array(
                            								'value' => '',
                            								'label' => 'None',
                            						),
                            						array(
                            								'value' => 'fraudguard',
                            								'label' => 'FraudGuard (SecurePay only)',
                            						),
                            				),
                            		),
                                    array(
                                            "label" => "Tokenisation Only",
                                            "type" => "checkbox",
                                            "name" => "pd_tokenisation",
                                            "tooltip" => "Only supported by Bambora. Selecting this option will mean that payments are not processed through PayDock at all. Instead PayDock will generate and return a token which can be used for setting up a regular payment elsewhere. Only select this if you have an integration in place with an external system (e.g. CRM) which is going to manage payment processing.",
                                            "choices" => array(
                                                    array(
                                                            "label" => "Don't process payments through PayDock; only generate a token.",
                                                            "name" => "pd_tokenisation",
                                                    ),
                                            ),
                                    ),
                            ),
                    ),
                    array(
                            "title" => "Transaction Details",
                            "fields" => array(
                                    array( // Can't override choices if it's part of the field_map below
                                            "name" => "pd_total_payable",
                                            "type" => "select",
                                            "label" => "Amount",
                                            "choices" => $this->productFields(),
                                            "required" => true,
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
                                            'choices' => array(
                                                    array(
                                                            'value' => '',
                                                            'label' => 'Credit Card',
                                                    ),
                                                    array(
                                                            'value' => 'bsb',
                                                            'label' => 'Direct Debit',
                                                    ),
                                                    array(
                                                            'value' => 'payment_source',
                                                            'label' => 'Existing Payment Source',
                                                    ),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_transaction_reference",
                                            "label" => "Transaction Reference",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'email', 'phone', 'name', 'hidden'),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_description",
                                            "label" => "Description",
                                            "type" => "field_select",
                                            'args' => array(
													'input_types' => array('text', 'email', 'phone', 'name', 'hidden'),
                                            ),
                                    ),
                            ),
                    ),
                    array(
                            "title" => "Payment Details",
                            "fields" => array(
                                    array(
                                            "name" => "pd_customer",
                                            "label" => "Customer",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'hidden'),
                                            ),
                                            'tooltip' => 'Required if Payment Type "Existing Payment Source" selected',
                                    ),
                                    array(
                                            "name" => "pd_payment_source",
                                            "label" => "Payment Source",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'hidden'),
                                            ),
                                            'tooltip' => 'Only used if Payment Type "Existing Payment Source" selected. If left empty, customer\'s default payment source will be used',
                                    ),
                                    array(
                                            "name" => "pd_account_name",
                                            "label" => "Account Name",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'hidden'),
                                            ),
                                            'tooltip' => 'Required if Payment Type "Direct Debit" selected',
                                    ),
                                    array(
                                            "name" => "pd_account_bsb",
                                            "label" => "Account BSB",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'hidden'),
                                            ),
                                            'tooltip' => 'Required if Payment Type "Direct Debit" selected',
                                    ),
                                    array(
                                            "name" => "pd_account_number",
                                            "label" => "Account Number",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('text', 'hidden'),
                                            ),
                                            'tooltip' => 'Required if Payment Type "Direct Debit" selected',
                                    ),
                            ),
                    ),
                    array(
                            "title" => "Payment Schedule",
                            "fields" => array(
                                    array(
                                            "name" => "pd_payment_interval",
                                            "label" => "Payment Interval",
                                            "type" => "select",
                                            "choices" => $this->intervalOptions(),
                                            'tooltip' => 'Subscription interval (one-off, day, week, month or year)',
                                    ),
                                    array(
                                            "name" => "pd_payment_frequency",
                                            "label" => "Payment Frequency",
                                            "type" => "select",
                                            "choices" => $this->frequencyOptions(),
                                            'tooltip' => 'Subscription frequency (every <i>n</i> intervals, e.g. every <i>3 weeks</i>',
                                    ),
                                    array(
                                            "name" => "pd_payment_start_date",
                                            "label" => "Payment Start Date",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('date', 'hidden'),
                                            ),
                                    ),
                                    array(
                                            "name" => "pd_payment_end_date",
                                            "label" => "Payment End Date",
                                            "type" => "field_select",
                                            'args' => array(
                                                    'input_types' => array('date', 'hidden'),
                                            ),
                                    ),
                            		array(
                            				"name" => "pd_max_payments",
                            				"label" => "Maximum Payments",
                            				"type" => "select",
                            				"choices" => $this->max_payments_options(),
                            				'tooltip' => 'Maximum number of payments for subscriptions. Once this number is reached the subscription will be automatically marked as complete.',
                            		),
                            ),
                    ),
            		array(
            				"title" => "Personal Details",
            				"fields" => array(
            						array(
            								"name" => "pd_email",
            								"label" => "Email",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('email', 'hidden'),
            								),
            								"required" => true,
            						),
            						array(
            								"name" => "pd_first_name",
            								"label" => "First Name",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'name', 'hidden'),
            								),
            								"required" => true,
            						),
            						array(
            								"name" => "pd_last_name",
            								"label" => "Last Name",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'name', 'hidden'),
            								),
            								"required" => true,
            						),
            						array(
            								"name" => "pd_phone",
            								"label" => "Phone Number",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'phone', 'hidden'),
            								),
            						),
            						array(
            								"name" => "pd_address_line1",
            								"label" => "Address Line 1",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            						),
            						array(
            								"name" => "pd_address_line2",
            								"label" => "Address Line 2",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            						),
            						array(
            								"name" => "pd_address_city",
            								"label" => "City",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            						),
            						array(
            								"name" => "pd_address_state",
            								"label" => "State",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            						),
            						array(
            								"name" => "pd_address_postcode",
            								"label" => "Postcode",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            								"required" => false,
            						),
            						array(
            								"name" => "pd_address_country",
            								"label" => "Country",
            								"type" => "field_select",
            								'args' => array(
            										'input_types' => array('text', 'address', 'hidden'),
            								),
            						),
            				),
            		),
                    array(
                            "title" => "Conditional Logic",
                            "fields" => array(
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

        private function load_gateways() {
            foreach ($this->environments as $env => $details) {
                if (strlen($details['key']) >= 40) {
                    $curl_header = array();
					if (substr_count($details['key'], '.') == 2) { // New Access Token
						$curl_header[] = 'x-access-token:' . $details['key'];
					} else { // Old API key
                    	$curl_header[] = 'x-user-token:' . $details['key'];
					}
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $details['uri'] . 'gateways/');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    $result = curl_exec($ch);
                    curl_close($ch);

                    $json_string = json_decode($result, true);
                    $gateways = $json_string['resource']['data'];

                    foreach ($gateways as $gateway) {
                        if ($env == 'sandbox') {
                            $gateway['name'] = '[Sandbox Account Gateway] ' . $gateway['name'];
                        } else {
                            $gateway['name'] = '[Production Account Gateway] ' . $gateway['name'];
                        }
                        $this->gateways[$env][$gateway['_id']] = array(
                                "label" => $gateway['name'],
                                "value" => $gateway['_id'],
                        );
                    }
                }
            }
        }

        /**
         * List of options for Gateway setting
         * @return array
         */
        protected function gatewayOptions() {
            $default_settings = array(
                    array(
                            "value" => "",
                            "label" => "Please Select",
                    )
            );

            foreach ($this->gateways as $end => $gateways) {
                foreach ($gateways as $gateway) {
                    $default_settings[] = $gateway;
                }
            }
            return $default_settings;
        }

        /**
         * List of gateways
         * @return array
         */
        public function get_gateways() {
        	if (empty($this->gateways)) {
        		$this->load_gateways();
        	}
        	return $this->gateways;
        }

        /**
         * Amount fields - BB Cart (if installed), plus any product fields and total fields
         */
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
                if (in_array($field['type'], array('product', 'total', 'number'))) {
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

            // if there is no total field then add custom option
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

        /**
         * List of options for Interval setting
         * @return array
         */
        protected function intervalOptions() {
            $form = $this->get_current_form();
            $fields = $form['fields'];
            $default_settings = array();

            array_push($default_settings, array(
                    "value" => "",
                    "label" => "One Off",
            ), array(
                    "value" => "day",
                    "label" => "Day",
            ), array(
                    "value" => "week",
                    "label" => "Week",
            ), array(
                    "value" => "month",
                    "label" => "Month",
            ), array(
                    "value" => "year",
                    "label" => "Year",
            ));

            foreach ($fields as $key => $field) {
                if (in_array($field->type, array('select', 'radio', 'text', 'hidden'))) {
                    $field_settings = array();
                    $field_settings['value'] = $field['id'];
                    $field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
                    array_push($default_settings, $field_settings);
                }
            }
            return $default_settings;
        }

        /**
         * List of options for Frequency setting
         * @return array
         */
        protected function frequencyOptions() {
            $form = $this->get_current_form();
            $fields = $form['fields'];
            $default_settings = array();

            array_push($default_settings, array(
                    "value" => "",
                    "label" => "1",
            ));

            foreach ($fields as $key => $field) {
                if (in_array($field->type, array('number', 'text', 'hidden'))) {
                    $field_settings = array();
                    $field_settings['value'] = $field['id'];
                    $field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
                    array_push($default_settings, $field_settings);
                }
            }
            return $default_settings;
        }

        /**
         * List of options for Max Payments setting
         * @return array
         */
        protected function max_payments_options() {
        	$form = $this->get_current_form();
        	$fields = $form['fields'];
        	$default_settings = array();

        	array_push($default_settings, array(
        			"value" => "",
        			"label" => "Select a Field",
        	));

        	foreach ($fields as $key => $field) {
        		if (in_array($field->type, array('number', 'text', 'hidden'))) {
        			$field_settings = array();
        			$field_settings['value'] = $field['id'];
        			$field_settings['label'] = __($field['label'], 'gravityforms-bb-paydock');
        			array_push($default_settings, $field_settings);
        		}
        	}
        	return $default_settings;
        }

        public function plugin_settings_fields() {
            return array(
                    array(
                            'title' => '<a href="https://paydock.com" target="_blank"><img src="'.plugin_dir_url(__FILE__).'/img/paydock_small.png"></a>',
                            'description' => '<p>PayDock is a smart payments platform designed for Merchants and Developers.</p>
<p>You will need to <a href="https://app.paydock.com/auth/sign-up" target="_blank">sign up for a PayDock account</a> if you don\'t already have one.</p>
<p>Please note that this plugin was developed by <a href="http://brownbox.net.au/" target="_blank">Brown Box</a>. It is recommended but not officially supported by PayDock.</p>',
                    ),
                    array(
                            "title" => "Add your PayDock API keys below",
                            'tooltip' => 'API keys can be found under My Account -> API & Settings',
                            "fields" => array(
                                    array(
                                            "name" => "pd_production_public_key",
                                            "label" => "Production Public Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                                    array(
                                            "name" => "pd_production_api_key",
                                            "label" => "Production Secret Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                                    array(
                                            "name" => "pd_sandbox_public_key",
                                            "label" => "Sandbox Public Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                                    array(
                                            "name" => "pd_sandbox_api_key",
                                            "label" => "Sandbox Secret Key",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                            ),
                    ),
                    array(
                            "title" => "Bambora Tokenisation Settings",
                            'tooltip' => 'If you are not using Bambora to generate tokens, you can ignore this section',
                            "fields" => array(
                                    array(
                                            "name" => "pd_bambora_customer_storage_number",
                                            "label" => "Customer Storage Number",
                                            "type" => "text",
                                            "class" => "medium"
                                    ),
                                    array(
                                            "name" => "pd_bambora_tokenise_algorithm",
                                            "label" => "Tokenising Algorithm",
                                            "type" => "text",
                                            "class" => "medium",
                                            'default' => '8',
                                    ),
                            ),
                    ),
            );
        }

        public function feed_list_columns() {
            return array(
                'feedName' => __('Name', 'gravityforms-bb-paydock'),
            );
        }

        /**
         * {@inheritDoc}
         * @see GFPaymentAddOn::get_payment_field()
         */
        public function get_payment_field($feed) {
        	$field = rgars($feed, 'meta/pd_total_payable', 'form_total');
        	if ($field == 'total') {
        		$field = 'form_total';
        	}
        	return $field;
        }

		/**
		 * Add supported notification events.
		 *
		 * @since  3.6.4
		 * @access public
		 *
		 * @used-by GFFeedAddOn::notification_events()
		 * @uses    GFFeedAddOn::has_feed()
		 *
		 * @param array $form The form currently being processed.
		 *
		 * @return array|false The supported notification events. False if feed cannot be found within $form.
		 */
		public function supported_notification_events($form) {
			// If this form does not have a PayDock feed, return false.
			if (!$this->has_feed($form['id'])) {
				return false;
			}

			// Return PayDock notification events.
			return array(
				'complete_payment'          => esc_html__('Payment Completed', 'gravityforms-bb-paydock'),
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
            $this->load_gateways();
            $data = array();

            $payment_type = $feed["meta"]["pd_payment_type"];
            if ($payment_type == 'payment_source') {
            	$this->log_debug(__METHOD__.'(): Processing existing payment source feed.');
                $data['customer_id'] = $entry[$feed["meta"]["pd_customer"]];
                if (!empty($entry[$feed["meta"]["pd_payment_source"]])) {
                    $data['customer']['payment_source_id'] = $entry[$feed["meta"]["pd_payment_source"]];
                }
            } else {
            	if ($payment_type == "bsb") {
            		$this->log_debug(__METHOD__.'(): Processing direct debit feed.');
                    $data["customer"]["payment_source"]["type"] = "bsb";
                    $data["customer"]["payment_source"]["account_name"] = $entry[$feed["meta"]["pd_account_name"]];
                    $data["customer"]["payment_source"]["account_bsb"] = str_replace('-', '', $entry[$feed["meta"]["pd_account_bsb"]]);
                    $data["customer"]["payment_source"]["account_number"] = $entry[$feed["meta"]["pd_account_number"]];
            	} else {
            		$this->log_debug(__METHOD__.'(): Processing credit card feed.');
            		$data["customer"]["payment_source"]["type"] = "card";
                    $data["customer"]["payment_source"]["card_name"] = $submission_data['card_name'];
                    $data["customer"]["payment_source"]["card_number"] = $submission_data['card_number'];
                    $ccdate_array = $submission_data['card_expiration_date'];
                    $ccdate_month = $ccdate_array[0];
                    if (strlen($ccdate_month) < 2) {
                        $ccdate_month = '0' . $ccdate_month;
                    }
                    $ccdate_year = $ccdate_array[1];
                    if (strlen($ccdate_year) > 2) {
                        $ccdate_year = substr($ccdate_year, -2); // Only want last 2 digits
                    }
                    $data["customer"]["payment_source"]["expire_month"] = $ccdate_month;
                    $data["customer"]["payment_source"]["expire_year"] = $ccdate_year;
                    $data["customer"]["payment_source"]["card_ccv"] = $submission_data['card_security_code'];
                }

                $first_name = $entry[$feed["meta"]["pd_first_name"]];
                $last_name = $entry[$feed["meta"]["pd_last_name"]];
                $email = strtolower(trim($entry[$feed["meta"]["pd_email"]]));
                $data["customer"]["first_name"] = $first_name;
                $data["customer"]["last_name"] = $last_name;
                $data["customer"]["email"] = $email;

                $phone = '';
                if (!empty($entry[$feed["meta"]["pd_phone"]])) {
                    $phone = preg_replace('/[^\+\d]/', '', $entry[$feed["meta"]["pd_phone"]]);
                    if (strpos($phone, '0') === 0) {
                        $phone = substr($phone, 1);
                    }
                    if (strpos($phone, '+') === false) {
                        $phone = '+61'.$phone;
                    }
                }
                if (!empty($phone)) {
	                $data["customer"]["phone"] = $phone;
                }

                $data["customer"]["payment_source"]["gateway_id"] = $feed["meta"]["pd_select_gateway"];
                if (!empty($entry[$feed["meta"]["pd_address_line1"]])) {
                	$data["customer"]["payment_source"]["address_line1"] = $entry[$feed["meta"]["pd_address_line1"]];
                }
                if (!empty($entry[$feed["meta"]["pd_address_line2"]])) {
                	$data["customer"]["payment_source"]["address_line2"] = $entry[$feed["meta"]["pd_address_line2"]];
                }
                if (!empty($entry[$feed["meta"]["pd_address_city"]])) {
                	$data["customer"]["payment_source"]["address_city"] = $entry[$feed["meta"]["pd_address_city"]];
                }
                if (!empty($entry[$feed["meta"]["pd_address_state"]])) {
                	$data["customer"]["payment_source"]["address_state"] = $entry[$feed["meta"]["pd_address_state"]];
                }
                if (!empty($entry[$feed["meta"]["pd_address_postcode"]])) {
                	$data["customer"]["payment_source"]["address_postcode"] = $entry[$feed["meta"]["pd_address_postcode"]];
                }
           		if (!empty($entry[$feed["meta"]["pd_address_country"]])) {
                	$data["customer"]["payment_source"]["address_country"] = $entry[$feed["meta"]["pd_address_country"]];
                }
            }
            if (!empty($entry[$feed["meta"]["pd_transaction_reference"]])) {
                $data["reference"] = $entry[$feed["meta"]["pd_transaction_reference"]];
            }
            if (!empty($entry[$feed["meta"]["pd_description"]])) {
                $data["description"] = $entry[$feed["meta"]["pd_description"]];
            }
            $data["currency"] = (!empty($entry[$feed["meta"]["pd_currency"]])) ? $entry[$feed["meta"]["pd_currency"]] : GFCommon::get_currency();

            $fraud_protection = $feed['meta']['pd_fraud_protection'];
            switch ($fraud_protection) {
            	case 'fraudguard':
            		$data['meta']['securepay_fraud_guard'] = 'true';
            		$data['meta']['ip_address'] = GFFormsModel::get_ip();
            		break;
            }
            $cleaned_data = $data;
            $cleaned_data['customer']['payment_source'] = 'REDACTED';
            $this->log_debug(__METHOD__.'(): Feed Data => '.print_r($cleaned_data, true));

            $pd_options = $this->get_plugin_settings();

            $feed_gateway_key = $feed['meta']['pd_select_gateway'];
            $_SESSION['PD_GATEWAY'] = $feed_gateway_key;

            if (!empty($this->gateways['production']) && array_key_exists($feed_gateway_key, $this->gateways['production'])) {
                $request_token = $pd_options['pd_production_api_key'];
                $public_key = $pd_options['pd_production_public_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $public_key = $pd_options['pd_sandbox_public_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

            $start_date = $entry[$feed["meta"]["pd_payment_start_date"]];
            $max_payments = $entry[$feed["meta"]["pd_max_payments"]];

            $transactions = array();
            $interval = is_numeric($feed["meta"]["pd_payment_interval"]) ? $entry[$feed["meta"]["pd_payment_interval"]] : $feed["meta"]["pd_payment_interval"];
            if (empty($interval)) {
                $interval = 'one-off';
            }
            $amount_field = $feed["meta"]["pd_total_payable"];
            if ($amount_field == 'bb_cart') {
                if (function_exists('bb_cart_amounts_by_frequency')) { // BB Cart 3.0+
                    $transactions = bb_cart_amounts_by_frequency();
                } else { // BB Cart < 3.0
                    if (!empty($_SESSION[BB_CART_SESSION_ITEM])) {
                        $cart_items = $_SESSION[BB_CART_SESSION_ITEM];
                        if (!empty($cart_items['woo']) && class_exists('WC_Product_Factory')) { // WooCommerce
                            if (!isset($transactions['one-off'])) {
                                $transactions['one-off'] = 0;
                            }
                            foreach ($cart_items['woo'] as $product) {
                            	if (!empty($product['price']) && !empty($product['quantity'])) {
                            		$transactions['one-off'] += ($product['price']*$product['quantity'])/100;
                            	} else {
                            		$product_factory = new WC_Product_Factory();
                            		$prod_obj = $product_factory->get_product($product['product_id']);
                            		$transactions['one-off'] += $prod_obj->get_price_excluding_tax($product['quantity']);
                            	}
                            }
                            unset($cart_items['woo']);
                            $transactions['one-off'] += bb_cart_calculate_shipping();
                        }
                        if (!empty($cart_items['event'])) { // Event Manager Pro
                            if (!isset($transactions['one-off'])) {
                                $transactions['one-off'] = 0;
                            }
                            foreach ($cart_items['event'] as $event) {
                            	$transactions['one-off'] += $event['price'];
                            }
                            unset($cart_items['event']);
                        }
                        if (!empty($cart_items)) { // BB Cart
                            foreach ($cart_items as $cart_item) {
                                if (!isset($transactions[$cart_item['frequency']])) {
                                    $transactions[$cart_item['frequency']] = 0;
                                }
                                $quantity = !empty($cart_item['quantity']) ? $cart_item['quantity'] : 1;
                                $transactions[$cart_item['frequency']] += $quantity*$cart_item['price']/100;
                            }
                        }
                        if (function_exists('bb_cart_total_shipping')) {
                            $shipping = bb_cart_total_shipping();
                            if ($shipping > 0) {
                                if (!isset($transactions['one-off'])) {
                                    $transactions['one-off'] = 0;
                                }
                                $transactions['one-off'] += $shipping;
                            }
                        }
                    }
                }
            } elseif (in_array($amount_field, array('total', 'form_total'))) {
            	// Get total of product fields
            	$order_data = $this->get_order_data($feed, $form, $entry);
            	$transactions[$interval] = $order_data['payment_amount'];
            	// Have to manually add click array values as GF doesn't know about them
            	foreach ($form["fields"] as $field) {
            		if ($field['type'] == 'envoyrecharge') {
            			if (rgpost('input_' . $field['id'].'_5') == 'recurring') {
            				$ech_interval = rgpost('input_' . $field['id'].'.2');
            			} else {
            				$ech_interval = $interval;
            			}
            			if (!isset($transactions[$ech_interval])) {
            				$transactions[$ech_interval] = 0;
            			}
            			$transactions[$ech_interval] += $this->clean_amount($entry[$field['id'].'.1'], $data["currency"]);
            		} elseif ('bb_click_array' == $field['type']) {
            			if (!isset($transactions[$interval])) {
            				$transactions[$interval] = 0;
            			}
            			$transactions[$interval] += $this->clean_amount($entry[$field['id'].'.1'], $data["currency"]);
            		}
            	}
            } else {
                foreach ($form["fields"] as $field) {
                    if ($field->id == $amount_field) {
                    	if ($field->type == 'product') {
                    		$order_data = $this->get_order_data($feed, $form, $entry);
                    		$transactions[$interval] = $order_data['payment_amount'];
                        } else {
                        	$transactions[$interval] = $this->clean_amount($entry[$amount_field], $data["currency"]);
                        }
                        break;
                    }
                }
            }

            $total_amount = array_sum($transactions);
            $this->log_debug(__METHOD__.'(): Total amount => '.$total_amount);
            if ($total_amount <= 0) {
                $error_message = 'No amounts found to process';
                $auth = array(
                        'is_authorized' => false,
                        'transaction_id' => null,
                        'error_message' => $error_message,
                );

                $GLOBALS['pd_error'] = $error_message;

                add_filter('gform_validation_message', array($this, 'change_message'), 10, 2);
                return $auth;
            }

            // Hack to resolve floating point error in json_encode()
            // See https://bugs.php.net/bug.php?id=72567 and https://wiki.php.net/rfc/precise_float_value
            // Solution sourced from https://stackoverflow.com/questions/42981409/php7-1-json-encode-float-issue
            if (version_compare(phpversion(), '7.1', '>=')) {
            	$ini_precision = ini_get('serialize_precision');
            	ini_set('serialize_precision', -1);
            }

            $today_time = strtotime(date('Y-m-d', current_time('timestamp')));

            $auth = array(
            		'is_authorized' => true,
            		'transaction_id' => null,
            		'amount' => 0,
            );
            // Bambora only - tokenise-only request
            if ($feed['meta']['pd_tokenisation']) {
            	$this->log_debug(__METHOD__.'(): Generating Bambora token.');
                // Send customer details with token request
                $api_url = $feed_uri . 'customers/';

                $customer = $data['customer'];
                $customer['payment_source']['meta'] = array(
                        'customer_storage_number' => $pd_options['pd_bambora_customer_storage_number'],
                        'tokenise_algorithm' => $pd_options['pd_bambora_tokenise_algorithm'],
                );
                $data_string = json_encode($customer);

				$curl_header = array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data_string)
				);
				if (substr_count($request_token, '.') == 2) { // New Access Token
					$curl_header[] = 'x-access-token:' . $request_token;
				} else { // Old API key
					$curl_header[] = 'x-user-token:' . $request_token;
				}
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                $result = curl_exec($ch);
                curl_close($ch);

                $response = json_decode($result);

                $GLOBALS['transaction_id'] = $GLOBALS['pd_error'] = $GLOBALS['pd_ref_token'] = "";

                if (!is_object($response) || $response->status > 201 || $response->_code > 250) {
                	$error_message = $this->get_paydock_error_message($response);
                	$this->log_debug(__METHOD__.'(): ERROR => '.$error_message);
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
                } else {
                    $payment_source = $this->_get_default_payment_source_of_a_customer($response);
                	$this->log_debug(__METHOD__.'(): Token generated successfully => '.$payment_source->ref_token);
                    $GLOBALS['pd_ref_token'] = $payment_source->ref_token;

                    add_filter("gform_entry_post_save", array($this, "paydock_post_purchase_actions"), 99, 2);
                }

                // Reverse hack
                if (version_compare(phpversion(), '7.1', '>=')) {
                	ini_set('serialize_precision', $ini_precision);
                }

                return $auth;
            } else {
            	if (!empty($start_date) && strtotime($start_date) > $today_time) { // If start date in future, we don't want to process anything yet
            		$this->log_debug(__METHOD__.'(): Not processing payment as start date is in future.');
                    $total_amount = 0;
                }
                if ($total_amount > 0) {
                	$this->log_debug(__METHOD__.'(): Processing initial payment.');
                    // Process total amount as a one-off
                    $api_url = $feed_uri . 'charges/';
                    $data['amount'] = $total_amount;

                    $data_string = json_encode($data);

					$curl_header = array(
							'Content-Type: application/json',
							'Content-Length: ' . strlen($data_string)
					);
					if (substr_count($request_token, '.') == 2) { // New Access Token
						$curl_header[] = 'x-access-token:' . $request_token;
					} else { // Old API key
						$curl_header[] = 'x-user-token:' . $request_token;
					}
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $api_url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                    $result = curl_exec($ch);
                    curl_close($ch);

                    $response = json_decode($result);

                    $GLOBALS['transaction_id'] = $GLOBALS['pd_error'] = "";

                    if (!is_object($response) || $response->status > 201 || $response->_code > 250) {
                    	$error_message = $this->get_paydock_error_message($response);
                    	$this->log_debug(__METHOD__.'(): ERROR => '.$error_message);
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

                        // Reverse hack
                        if (version_compare(phpversion(), '7.1', '>=')) {
                        	ini_set('serialize_precision', $ini_precision);
                        }

                        return $auth;
                    } else {
                    	$this->log_debug(__METHOD__.'(): Transaction processed successfully => '.$response->resource->data->_id);
                        $GLOBALS['transaction_id'] = $response->resource->data->_id;
                        $GLOBALS['gateway_transaction_id'] = $response->resource->data->external_id;

                        add_filter("gform_entry_post_save", array($this, "paydock_post_purchase_actions"), 99, 2);

                        $auth = array(
                                'is_authorized' => true,
                                'transaction_id' => $response->resource->data->_id,
                                'amount' => $total_amount,
                        );
                    }
                }

                if ($feed['meta']['pd_dont_create_subscriptions']) {
                	$this->log_debug(__METHOD__.'(): Generating one-time token.');
                    // If they don't want to set up subscriptions, just generate a one-time token that can be used by the other system
                    $api_url = $feed_uri.'payment_sources/tokens?public_key='.$public_key;

                    // We only need payment details
                    $token_data = $data["customer"]["payment_source"];

                    // Plus a couple of other fields
                    $token_data['first_name'] = $data["customer"]["first_name"];
                    $token_data['last_name'] = $data["customer"]["last_name"];
                    $token_data['email'] = $data["customer"]["email"];
                    $token_data['phone'] = $data["customer"]["phone"];

                    $data_string = json_encode($token_data);

					$curl_header = array(
							'Content-Type: application/json',
							'Content-Length: ' . strlen($data_string)
					);
					if (substr_count($request_token, '.') == 2) { // New Access Token
						$curl_header[] = 'x-access-token:' . $request_token;
					} else { // Old API key
						$curl_header[] = 'x-user-token:' . $request_token;
					}
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $api_url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                    $result = curl_exec($ch);
                    curl_close($ch);

                    $response = json_decode($result);
                    if (!is_object($response) || $response->status > 201 || $response->_code > 250) {
                    	$this->log_debug(__METHOD__.'(): ERROR => '.$error_message);
                        $error_message = $this->get_paydock_error_message($response);
                        $GLOBALS['pd_error'] = $error_message;
                    } else {
                    	$this->log_debug(__METHOD__.'(): Token generated successfully => '.$response->resource->data);
                        $GLOBALS['pd_token_id'] = $response->resource->data;
                    }
                } else {
                    // Now we can set up subscriptions for any recurring transactions
                    foreach ($transactions as $interval => $amount) {
                    	if ($amount <= 0 || ($interval == 'one-off' && (empty($start_date) || strtotime($start_date) <= $today_time)) || 1 == $max_payments) {
                            continue;
                        }
                        $data['amount'] = $amount;

                        // Set the right API endpoint
                        $api_url = $feed_uri . 'subscriptions/';

                        $frequency = is_numeric($feed["meta"]["pd_payment_frequency"]) ? $entry[$feed["meta"]["pd_payment_frequency"]] : $feed["meta"]["pd_payment_frequency"];
                        if (empty($frequency)) {
                            $frequency = 1;
                        }

                        if ($interval == 'fortnight') { // Hack to support fortnightly recurrence
                            $interval = 'week';
                            $frequency = 2;
                        } elseif ($interval == 'one-off') { // Hack to support future-dated one-off transactions
                            $interval = 'month';
                            $data['schedule']['end_transactions'] = 1;
                        }

                        $data["schedule"]["frequency"] = $frequency;
                        $data["schedule"]["interval"] = $interval;

                        if (empty($start_date)) {
                        	$start_date = date('Y-m-d', strtotime('+'.$frequency.' '.$interval));
                        } else {
                        	while (strtotime($start_date) <= $today_time) {
                        		$start_date = date('Y-m-d', strtotime('+'.$frequency.' '.$interval, strtotime($start_date)));
                        	}
                        }
                        $this->log_debug(__METHOD__.'(): Creating subscription for '.$amount.' every '.$frequency.' '.$interval.'(s) starting on '.$start_date.'.');
                        $data["schedule"]["start_date"] = $start_date;

                        $end_date = $entry[$feed["meta"]["pd_payment_end_date"]];
                        if ($end_date != "") {
                            $data["schedule"]["end_date"] = $end_date;
                        }

                        $data_string = json_encode($data);

						$curl_header = array(
								'Content-Type: application/json',
								'Content-Length: ' . strlen($data_string)
						);
						if (substr_count($request_token, '.') == 2) { // New Access Token
							$curl_header[] = 'x-access-token:' . $request_token;
						} else { // Old API key
							$curl_header[] = 'x-user-token:' . $request_token;
						}
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $api_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                        $result = curl_exec($ch);
                        curl_close($ch);

                        $response = json_decode($result);

                        $GLOBALS['pd_error'] = "";

                        if (!is_object($response) || $response->status > 201 || $response->_code > 250) {
                            $error_message = $this->get_paydock_error_message($response);
                            $this->log_debug(__METHOD__.'(): ERROR => '.$error_message);

                        	if (!empty($auth['amount'])) { // If we have already processed something just send a notification
                                $this->send_subscription_failed_email($first_name, $email, $amount, $interval, $frequency, $error_message);
                        	} else { // Otherwise flag the submission as failed
                        		$GLOBALS['pd_error'] = $error_message;
                        		add_filter('gform_validation_message', array($this, 'change_message'), 10, 2);

                        		// Set the form validation to false
                        		$auth = array(
                        				'is_authorized' => false,
                        				'transaction_id' => $response->resource->data->_id,
                        				'error_message' => $error_message,
                        		);

                        		foreach ($form['fields'] as &$field) {
                        			if ($field->cssClass == 'pd-show-error') {
                        				$field->failed_validation = true;
                        				$field->validation_message = 'There was a problem scheduling your payment. Please try again or contact us.';
                        				break;
                        			}
                        		}
                        	}
                        } else {
                        	$this->log_debug(__METHOD__.'(): Subscription created successfully => '.$response->resource->data->_id);
                        	if (!empty($auth['amount'])) { // Connect initial payment to subscription
                        		/* @todo this won't work without linking the one-off payment to a customer record
                        		 $api_url = $feed_uri.'charges/'.$auth['transaction_id'];
                        		 $data = array(
                        		 'subscription_id' => $response->resource->data->_id,
                        		 );
                        		 $data_string = json_encode($data);
								$curl_header = array(
										'Content-Type: application/json',
										'Content-Length: ' . strlen($data_string)
								);
								if (substr_count($request_token, '.') == 2) { // New Access Token
									$curl_header[] = 'x-access-token:' . $request_token;
								} else { // Old API key
									$curl_header[] = 'x-user-token:' . $request_token;
								}
                        		 $ch = curl_init();
                        		 curl_setopt($ch, CURLOPT_URL, $api_url);
                        		 curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        		 curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
                        		 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        		 curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
                        		 $result = curl_exec($ch);
                        		 curl_close($ch);*/
                        	} else { // We only processed a future-dated subscription, return result
                        		$auth = array(
                        				'is_authorized' => true,
                        				'transaction_id' => $response->resource->data->_id,
                        				'amount' => 0,
                        		);
                        	}
                        	$GLOBALS['subscription_id'] = $response->resource->data->_id;
                        }
                    }
                }
            }

            // Reverse hack
            if (version_compare(phpversion(), '7.1', '>=')) {
            	ini_set('serialize_precision', $ini_precision);
            }

            return $auth;
        }

        private function send_subscription_failed_email($first_name, $email, $amount, $interval, $frequency, $error_message) {
            $from_name = get_bloginfo('name');
            $message = <<<EOM
<p>Dear $first_name,</p>
<p>While we were able to successfully process your initial transaction, an error occured while setting up the recurring payment of $$amount every $frequency $interval. Please contact us to resolve this.</p>
<p>Error details: $error_message</p>
<p>Sincerely,<br>
<p>$from_name</p>
EOM;
            wp_mail($email, 'Recurring Payment Failure', $message, 'Content-type: text/html');
        }

        // @todo make this work
//         protected function get_validation_result($validation_result, $authorization_result) {
//             $credit_card_page = 0;
//             foreach ($validation_result['form']['fields'] as &$field) {
//                 if ($field->type == 'creditcard') {
//                     $field->failed_validation  = true;
//                     $field->validation_message = $authorization_result['error_message'];
//                     $credit_card_page          = $field->pageNumber;
//                     break;
//                 }
//             }

//             $validation_result['credit_card_page'] = $credit_card_page;
//             $validation_result['is_valid']         = false;
//             $validation_result["form"]["error"]    = $authorization_result['error_message'];

//             return $validation_result;
//         }

        // @todo replace with get_validation_result() above
        public function change_message($message, $form) {
            return '<div class="validation_error">Error processing transaction: '.$GLOBALS['pd_error'].'.</div>';
        }

        public function get_paydock_error_message($response) {
            $error_message = $error_details = '';
            if ($response == null || $response == '') {
				$error_message = __('An unknown error occured - no response was received from the gateway. Your payment may have been processed, but the gateway did not send a confirmation. We strongly recommend that you do not try again, but instead please contact us so we can check whether the payment went through successfully.', 'gravityforms-bb-paydock');
            } else {
                if (is_string($response)) {
                    $error_message = __($response, 'gravityforms-bb-paydock');
                } elseif (!empty($response->error->message)) {
                    $error_message = __($response->error->message, 'gravityforms-bb-paydock');
                }
                if (property_exists($response->error, 'details')) {
					if (is_array($response->error->details)) {
						if (!is_object($response->error->details[0])) {
							$error_details = __($response->error->details[0], 'gravityforms-bb-paydock');
						} elseif (isset($response->error->details[0]->gateway_specific_description)) {
							$error_details = $response->error->details[0]->gateway_specific_description;
						}
					} elseif (property_exists($response->error->details, 'messages') && is_array($response->error->details->messages)) {
						$error_details = implode('. ', $response->error->details->messages);
					}
                }
                if (empty($error_message)) {
                    $error_message = __('An unknown error occured. Please try again.', 'gravityforms-bb-paydock');
                }
            }
            if (!empty($error_details)) {
                $error_message .= ' ('.$error_details.')';
            }
            return $error_message;
        }

        /**
         * Convert currency amount to a clean number
         * @param string $amount
         * @param string $currency_code
         * @return number|boolean
         * @since 1.0.0
         */
        public function clean_amount($amount, $currency_code = '') {
        	$amount = preg_replace("/\|(.*)/", '', $amount); // replace everything from the pipe symbol forward
			return GFCommon::to_number($amount, $currency_code);
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

			unset($_SESSION['PD_GATEWAY']);

			$entry['is_fulfilled'] = '1';
			$payment = array(
					'payment_status' => 'Paid',
					'payment_date' => gmdate('Y-m-d H:i:s'),
					'type' => 'complete_payment',
					'amount' => $amount,
					'transaction_id' => $GLOBALS['transaction_id'],
			);
			$this->complete_payment($entry, $payment);

			return $entry;
        }

        public function add_merge_tags($form) {
?>
            <script type="text/javascript">
                gform.addFilter('gform_merge_tags', 'add_merge_tags');
                function add_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
                    mergeTags['paydock'] = {
                            'label': 'PayDock',
                            'tags': []
                    };
                    mergeTags["paydock"].tags.push({tag: '{paydock_transaction_id}', label: 'PayDock Transaction ID'});
                    mergeTags["paydock"].tags.push({tag: '{gateway_transaction_id}', label: 'Gateway Transaction ID'});
                    return mergeTags;
                }
            </script>
<?php
            return $form;
        }

        public function replace_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
            $pd_merge_tag = '{paydock_transaction_id}';
            $gateway_merge_tag = '{gateway_transaction_id}';

            if ((strpos($text, $pd_merge_tag) === false && strpos($text, $gateway_merge_tag) === false) || empty($entry) || empty($form)) {
                return $text;
            }

            $pd_transaction_id = rgar($entry, 'transaction_id');
            $text = str_replace($pd_merge_tag, $pd_transaction_id, $text);

            $gateway_transaction_id = gform_get_meta($entry['id'], 'gateway_transaction_id');
            $text = str_replace($gateway_merge_tag, $gateway_transaction_id, $text);
            return $text;
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/?customer_id='.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_subscriptions_by_email($email, $production = false) {
			$customers = $this->get_customers_by_email($email, $production);
			$customer_subscriptions = array();
			foreach ($customers->resource->data as $customer) {
				$subscriptions = $this->get_subscriptions_by_customer($customer->_id, $production);
				if ($subscriptions->status != 200) {
					return false;
				}
				foreach ($subscriptions->resource->data as $subscription) {
					$customer_subscriptions[] = $subscription;
				}
			}

			return $customer_subscriptions;
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

			$curl_header = array(
					'Content-Type: application/json',
					'Content-Length: ' . strlen($data_string)
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'subscriptions/'.$sub_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'charges/'.$charge_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'charges/?search='.urlencode($email));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function get_customers_by_email($email, $production = false) {
            $pd_options = $this->get_plugin_settings();
            if ($production) {
                $request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
                $request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/?email='.urlencode($email));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

			$curl_header = array(
					'Content-Type: application/json',
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/'.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

        public function update_email_address($user_id, $old_user_data) {
            $new_user_data = get_user_by('id', $user_id);
            $new_email = $new_user_data->user_email;
            $old_email = $old_user_data->user_email;
            if (!empty($new_email) && !empty($old_email) && $new_email != $old_email) {
                $env = array();
                $pd_options = $this->get_plugin_settings();
                if (!empty($pd_options['pd_production_api_key'])) {
                    $env[] = true;
                }
                if (!empty($pd_options['pd_sandbox_api_key'])) {
                    $env[] = false;
                }
                foreach ($env as $production) {
                    $customers = $this->get_customers_by_email($old_email, $production);
                    foreach ($customers->resource->data as $customer) {
						if ($customer->email == $old_email) {
							$data = array('email' => $new_email);
							$this->update_customer($customer->_id, $data, $production);
						}
                    }
                }
            }
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

			$curl_header = array(
					'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/'.$customer_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
            $result = curl_exec($ch);
            curl_close($ch);

            return json_decode($result);
        }

		public function update_card_expiry($customer, $payment_source, $expire_month, $expire_year, $ccv, $production = false) {
			$pd_options = $this->get_plugin_settings();
            if ($production) {
				$request_token = $pd_options['pd_production_api_key'];
                $feed_uri = $this->production_endpoint;
            } else {
				$request_token = $pd_options['pd_sandbox_api_key'];
                $feed_uri = $this->sandbox_endpoint;
            }

			$data = array(
				'expire_month' => $expire_month,
				'expire_year' => $expire_year,
			);
			$data_string = json_encode($data);
			$curl_header = array(
					'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'vault-tokens/'.$payment_source->vault_token);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
            $result = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($result);
			if ($result->status >= 400) {
				return $result;
			}

			$data = array(
				'vault_token' => $payment_source->vault_token,
				'card_ccv' => $ccv,
			);
			$data_string = json_encode($data);
			$curl_header = array(
					'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string)
			);
			if (substr_count($request_token, '.') == 2) { // New Access Token
				$curl_header[] = 'x-access-token:' . $request_token;
			} else { // Old API key
				$curl_header[] = 'x-user-token:' . $request_token;
			}
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $feed_uri.'customers/'.$customer->customer_id.'/payment_sources/'.$payment_source->_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_header);
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

        /**
         * Get payment sources by email address
         *
         * @param string $email_address
         */
        public function get_payment_sources_by_email_address($email_address, $production) {
            $default_payment_sources = array();

            // Pull all subscriptions by email address
            $subscriptions = $this->get_subscriptions_by_email($email_address, $production);

			if (is_array($subscriptions)) {
				foreach ($subscriptions as $subscription) {
					// Get customer ID
					$customer_id = $subscription->customer->customer_id;

					// Get customer details
					$customer = $this->get_customer($customer_id, $production);

					// Get default payment source for a customer
					$default_payment_source = $this->_get_default_payment_source_of_a_customer($customer);

					if ($default_payment_source) {
						$default_payment_sources[] = $default_payment_source;
					}
				}
			}

            return $default_payment_sources;
        }

        /**
         * Get default payment source for a provided customer
         *
         * @param string $default_payment_source_id
         * @param array $payment_sources
         * @return bool|stdClass
         */
        protected function _get_default_payment_source_of_a_customer( stdClass $customer ) {
            if ( ! isset( $customer->resource->data->default_source ) || ! isset( $customer->resource->data->payment_sources ) ) {
                return false;
            }

            $payment_sources = $customer->resource->data->payment_sources;
            $default_payment_source_id = $customer->resource->data->default_source;

            foreach ( $payment_sources as $payment_source ) {
                if ( $default_payment_source_id === $payment_source->_id ) {
                    return $payment_source;
                }
            }

            return false;
        }
    }
}
