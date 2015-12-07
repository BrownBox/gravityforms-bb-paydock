<?php
/*
Plugin Name: Gravity Forms Brown Box PayDock Add-On
Plugin URI: http://brownbox.net.au/
Description: Integrates Gravity Forms with <a href="http://thepaydock.com/">PayDock</a>, enabling end users to purchase goods and services through Gravity Forms.
Version: 2.0.3
Author: Brown Box
Author URI: http://brownbox.net.au/
Text Domain: gravityforms-bb-paydock
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2015 Brown Box
Last updated: September 14, 2015

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

define('GF_PAYDOCK_VERSION', '2.0.1');

add_action('gform_loaded', array('GF_PayDock_Launch', 'load'), 5);

class GF_PayDock_Launch
{
    public static function load()
    {
        if (!method_exists('GFForms', 'include_payment_addon_framework')) {
            return;
        }

        require_once('gf-paydock.class.php');
        require_once('envoy-field-settings.php');

        GFAddOn::register('GFPayDock');
    }
}

register_activation_hook(__FILE__, 'migrate_ech_settings');
function migrate_ech_settings() {
    $ech_options = get_option('gravityformsaddon_EnvoyRecharge_settings');
    $paydock_options = get_option('gravityformsaddon_PayDock_settings');
    if (!empty($ech_options['envoyapikey']) && empty($paydock_options)) {
        $paydock_options = array(
                'pd_production_api_key' => $ech_options['envoyapikey'],
        );
        update_option('gravityformsaddon_PayDock_settings', $paydock_options);
    }
}

// Enable the Gravity Forms credit card field
add_action("gform_enable_credit_card_field", "gf_paydock_enable_creditcard");
function gf_paydock_enable_creditcard($is_enabled){
    return true;
}