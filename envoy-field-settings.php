<?php
/**
 * The EnvoyRecharge field type is maintained for backwards-compatibility reasons. It will be removed in a future version of the plugin.
 */

// add_filter('gform_add_field_buttons', 'deprecated_envoy_add_field', 10, 1);
// function deprecated_envoy_add_field($field_groups) {
//     foreach ($field_groups as &$group) {
//         if ($group["name"] == "pricing_fields") {
//             $group["fields"][] = array(
//                     "class" => "button",
//                     "value" => __("EnvoyRecharge", "gravityformsenvoyrecharge"),
//                     "onclick" => "StartAddField('envoyrecharge');"
//             );
//             break;
//         }
//     }
//     return $field_groups;
// }

// Adds title to GF custom field
add_filter('gform_field_type_title', 'deprecated_envoy_field_title', 5, 2);
function deprecated_envoy_field_title($title, $field_type) {
    if ($field_type == 'envoyrecharge')
        return __('EnvoyRecharge (Deprecated)', 'gravityformsenvoyrecharge');
    return $title;
}

// Adds the input area to the external side
add_action("gform_field_input", "deprecated_envoy_field_field_input", 10, 5);
function deprecated_envoy_field_field_input($input, $field, $value, $lead_id, $form_id) {
    if ($field["type"] == "envoyrecharge") {
        $max_chars = "";
        if (!IS_ADMIN && !empty($field["maxLength"]) && is_numeric($field["maxLength"]))
            $max_chars = self::get_counter_script($form_id, $field_id, $field["maxLength"]);

        $input_name = $form_id . '_' . $field["id"];
        $tabindex = GFCommon::get_tabindex();
        $css = isset($field['cssClass']) ? $field['cssClass'] : "";
        //add a variable to disable a select field if admin  dashboard is opened
        if (IS_ADMIN)
            $disabled = 'disabled';
        else
            $disabled = '';

        $amount = '';
        $frequency = '';
        $recurring = '';
        if (is_array($value)) {
            $amount = esc_attr(rgget($field["id"] . ".1", $value));
            $frequency = rgget($field["id"] . ".2", $value);
            $recurring = rgget('input_'.$field['id'].'.5') == "recurring" ? "checked='checked'" : "";
        }

        $recur_label = empty($field['field_envoyrecharge_recur_label']) ? 'Yes, I want to make a recurring donation' : $field['field_envoyrecharge_recur_label'];

        $recur_hidden = !empty($field['field_envoyrecharge_recurring_disabled']) ? ' style="display: none;"' : '';

        $html = "<div class='ginput_container'>"."\n";
        $html .= '<input name="input_'.$field['id'].'.1" id="input_'.$input_name.'_1" class="gform_ech ginput_amount '.$field["type"].' '.esc_attr($css).' '.$field['size'].'" type="text" '.$disabled.' value="'.$amount.'">';
        $html .= '<div class="gform_envoyrecharge_recurring envoyrecharge_recur_setting"'.$recur_hidden.'><input name="input_'.$field['id'].'.5" id="ginput_envoyrecharge_recurring_'.$field['id'].'" type="checkbox" '.$disabled.' value="recurring" '.$recurring.' onclick="EnvoyToggleRecurring(jQuery(this));">
            <label class="ginput_envoyrecharge_label" id="ginput_envoyrecharge_label_'.$field['id'].'" for="ginput_envoyrecharge_recurring_'.$field['id'].'">'.$recur_label.'</label></div>';
        $html .= "
    <select $disabled data-checkbox='ginput_envoyrecharge_recurring_".$field['id']."' name='input_".$field['id'].".2' id='input_".$input_name."_2' class='select envoyrecharge_recur_setting envoyrecharge_recur_frequency gform_ech ".$field["type"].' '.esc_attr($css).' '.$field['size']."'$recur_hidden>".GFCommon::get_select_choices($field, $value)."</select>";
        $html .= "</div>";
        return $html;
    }

    return $input;
}

// Now we execute some javascript technicalities for the field to load correctly
add_action("gform_editor_js", "deprecated_envoy_field_gform_editor_js");
function deprecated_envoy_field_gform_editor_js() {
?>
<script type='text/javascript'>

jQuery(document).ready(function($) {
    fieldSettings["envoyrecharge"] = ".label_setting, .description_setting, .rules_setting, .admin_label_setting, .size_setting, .error_message_setting, .css_class_setting, .visibility_setting, .envoyrecharge_setting, .conditional_logic_field_setting";

    //binding to the load field settings event to initialize the checkbox
    $(document).bind("gform_load_field_settings", function(event, field, form){
        $("#field_envoyrecharge_recurring_disabled").attr("checked", field["field_envoyrecharge_recurring_disabled"] == true);
        EnvoyToggleRecurring(field["field_envoyrecharge_recurring_disabled"] == true);
        $("#field_envoyrecharge_recur_label").val(field["field_envoyrecharge_recur_label"]);
//         $('li.gform_ech li.choices_setting').remove();
    });
});

function EnvoyToggleRecurring(disabled) {
    if (disabled)
        jQuery('.field_selected .envoyrecharge_recur_setting').hide();
    else
        jQuery('.field_selected .envoyrecharge_recur_setting').show();
}

function EnvoyUpdateRecurLabel(label) {
    if (label == '')
        label = 'Yes, I want to make a recurring donation';
    jQuery('.field_selected label.ginput_envoyrecharge_label').html(label);
}

</script>
<?php
}

// Add custom settings to the envoyrecharge field
add_action("gform_field_standard_settings", "deprecated_envoy_field_settings", 10, 2);
function deprecated_envoy_field_settings($position, $form_id) {

    // Create settings on position 1430 (right after Description)
    if ($position == 1430) {
?>
        <li class="envoyrecharge_setting field_setting">
            <input type="checkbox" id="field_envoyrecharge_recurring_disabled" onclick="SetFieldProperty('field_envoyrecharge_recurring_disabled', this.checked); EnvoyToggleRecurring(this.checked);">
            <label for="field_envoyrecharge_recurring_disabled" class="inline">
                <?php _e("Disable Recurring", "gravityformsenvoyrecharge"); ?>
                <?php gform_tooltip("form_field_envoyrecharge_recurring"); ?>
            </label>
        </li>
        <li class="envoyrecharge_setting field_setting envoyrecharge_recur_setting">
            <label for="field_envoyrecharge_recur_label">
                <?php _e("Custom Recurring Checkbox Label", "gravityformsenvoyrecharge"); ?>
                <?php gform_tooltip("field_envoyrecharge_recur_label"); ?>
            </label>
            <input type="text" class="fieldwidth-3" size="75" id="field_envoyrecharge_recur_label" onkeyup="SetFieldProperty('field_envoyrecharge_recur_label', this.value); EnvoyUpdateRecurLabel(this.value);">
        </li>
        <li class="envoyrecharge_setting field_setting envoyrecharge_recur_setting">
            <!-- div style="display: none;">
                <input type="checkbox" id="field_choice_values_enabled" onclick="SetFieldProperty('enableChoiceValue', this.checked); ToggleChoiceValue(); SetFieldChoices();"/>
                <label for="field_choice_values_enabled" class="inline gfield_value_label"><?php _e("show values", "gravityforms") ?></label>
            </div -->
            <!--
            <?php echo apply_filters( "gform_choices_setting_title", __("Available Frequencies", "gravityformsenvoyrecharge") ); ?>
            <?php gform_tooltip("field_envoyrecharge_frequency_choices") ?>
            <br>

            <div id="gfield_settings_choices_container">
                <label class="gfield_choice_header_label"><?php _e("Label", "gravityforms") ?></label><label class="gfield_choice_header_value"><?php _e("Value", "gravityforms") ?></label>
                <ul id="field_choices"></ul>
            </div>
            -->
        </li>
<?php
    }
}

add_filter("gform_predefined_choices", "deprecated_envoy_add_predefined_choice");
function deprecated_envoy_add_predefined_choice($choices){
    $choices["My New Choice"] = array("Choice 1|One", "Choice 2|Two", "Choice 3|Three");
    return $choices;
}

add_action("gform_editor_js_set_default_values", "deprecated_envoy_default_frequencies");
function deprecated_envoy_default_frequencies() {
?>
        case 'envoyrecharge':
            field.enableChoiceValue = true;
            if(!field.label)
                field.label = "<?php _e("My Donation", "gravityforms"); ?>";

            if(!field.choices)
                field.choices = new Array(new Choice("<?php _e("Select Frequency", "gravityformsenvoyrecharge"); ?>", " "),
                                          new Choice("<?php _e("Daily", "gravityformsenvoyrecharge"); ?>", "day"),
                                          new Choice("<?php _e("Weekly", "gravityformsenvoyrecharge"); ?>", "week"),
                                          new Choice("<?php _e("Monthly", "gravityformsenvoyrecharge"); ?>", "month"),
                                          new Choice("<?php _e("Annually", "gravityformsenvoyrecharge"); ?>", "year"));

            field.inputs = [new Input(field.id + 0.1, '<?php echo esc_js(__("Amount", "gravityformsenvoyrecharge")); ?>'),
                            new Input(field.id + 0.5, '<?php echo esc_js(__("Recurring", "gravityformsenvoyrecharge")); ?>'),
                            new Input(field.id + 0.2, '<?php echo esc_js(__("Frequency", "gravityformsenvoyrecharge")); ?>')];
        break;
<?php
}

//Filter to add a new tooltip
add_filter('gform_tooltips', 'deprecated_envoy_add_field_tooltips');
function deprecated_envoy_add_field_tooltips($tooltips) {
    $tooltips["form_field_envoyrecharge_recurring"] = "<h6>Disable Recurring</h6> Check the box if you would like to disable the recurring giving option.";
    $tooltips["field_envoyrecharge_recur_label"] = "<h6>Recurring Checkbox Label</h6> Custom label for the recurring checkbox option.";
    $tooltips["field_envoyrecharge_frequency_choices"] = "<h6>Available Frequencies</h6> List the available frequency options you want the user to be able to select from. Note that if your values do not match those accepted by EnvoyRecharge the transaction will fail.";
    return $tooltips;
}

// Add a script to the display of the particular form only if envoyrecharge field is being used
add_action('gform_enqueue_scripts', 'deprecated_envoy_field_gform_enqueue_scripts', 10, 2);
function deprecated_envoy_field_gform_enqueue_scripts($form, $ajax) {
    // cycle through fields to see if envoyrecharge is being used
    foreach ($form['fields'] as $field) {
        if ($field['type'] == 'envoyrecharge') {
            $url = plugins_url('js/gform_envoyrecharge.js', __FILE__);
            wp_enqueue_script("gform_envoyrecharge_script", $url, array("jquery"), '1.0');
            break;
        }
    }
}

// Add a custom class to the field li
add_action("gform_field_css_class", "deprecated_envoy_custom_field_class", 10, 3);
function deprecated_envoy_custom_field_class($classes, $field, $form) {
    if ($field["type"] == "envoyrecharge") {
        $classes .= ' gform_ech gfield_price gfield_price_'.$field['formId'].'_'.$field['id'].'_1 gfield_product_'.$field['formId'].'_'.$field['id'].'_1';
    }

    return $classes;
}

add_filter("gform_entry_field_value", "deprecated_envoy_field_entry_output", 10, 4);
function deprecated_envoy_field_entry_output($value, $field, $lead, $form){
    if ($field["type"] == "envoyrecharge"){
        $value = 'Amount: '.$lead[$field["id"].'.1'].'<br>'
                .'Frequency: '.(empty($lead[$field["id"].'.2']) ? 'one-off' : $lead[$field["id"].'.2']);
    }
    return $value;
}

add_filter('gform_field_validation', 'deprecated_envoy_field_validation', 1, 4);
function deprecated_envoy_field_validation($result, $value, $form, $field) {
    if ($field['type'] == 'envoyrecharge') {
        if ($value[$field['id'].'.5'] == 'recurring') {
            if (empty($value[$field['id'].'.2'])) {
                $result['is_valid'] = false;
                $result['message'] = __('Recurring transactions require frequency to be specified', 'gravityformsenvoyrecharge');
            }
        } else {
            $value[$field['id'].'.2'] == 'one-off';
        }
    }
    return $result;
}