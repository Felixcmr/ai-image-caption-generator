<?php
/**
 * Plugin Name: AI Image Caption Generator
 * Description: Generiert Bildunterschriften und Alt-Text für Medien über KI-APIs
 * Version: 1.9.0
 * Author: Your Name
 * Plugin URI: https://github.com/Felixcmr/ai-image-caption-generator
 * GitHub Plugin URI: https://github.com/Felixcmr/ai-image-caption-generator
 * Primary Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('AICG_VERSION', '1.9.0');
define('AICG_PLUGIN_FILE', __FILE__);
define('AICG_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AICG_UPDATE_URL', 'https://your-domain.com/wp-json/plugin-updates/v1/check');

// Update-Checker einbinden
require_once plugin_dir_path(__FILE__) . 'class-plugin-updater.php';

// Hauptklasse des Plugins
class AI_Image_Caption_Generator {
    
    private $options;
    private $updater;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_generate_caption', array($this, 'ajax_generate_caption'));
        add_action('wp_ajax_save_caption', array($this, 'ajax_save_caption'));
        add_filter('attachment_fields_to_edit', array($this, 'add_caption_button'), 10, 2);
        
        // Update-Checker initialisieren
        $this->init_updater();
    }
    
    private function init_updater() {
        $this->updater = new AICG_Plugin_Updater(
            AICG_UPDATE_URL,
            AICG_PLUGIN_FILE,
            array(
                'version' => AICG_VERSION,
                'license' => get_option('aicg_license_key', ''),
                'item_name' => 'AI Image Caption Generator',
                'author' => 'Your Name',
                'beta' => false
            )
        );
    }
    
    // ... Rest des Plugin-Codes bleibt gleich ...
    
    public function init() {
        $this->options = get_option('ai_image_caption_options', array());
    }
    
    public function add_admin_menu() {
        add_options_page(
            'AI Image Caption Generator',
            'AI Image Captions',
            'manage_options',
            'ai-image-caption-generator',
            array($this, 'options_page')
        );
    }
    
    public function settings_init() {
        register_setting('ai_image_caption_settings', 'ai_image_caption_options');
        
        // Lizenz-Sektion hinzufügen
        add_settings_section(
            'ai_image_caption_license',
            'Lizenz & Updates',
            array($this, 'license_section_callback'),
            'ai_image_caption_settings'
        );
        
        add_settings_field(
            'license_key',
            'Lizenzschlüssel',
            array($this, 'license_key_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_license'
        );
        
        add_settings_section(
            'ai_image_caption_section',
            'API-Einstellungen',
            array($this, 'settings_section_callback'),
            'ai_image_caption_settings'
        );
        
        add_settings_field(
            'openai_api_key',
            'OpenAI API Key',
            array($this, 'openai_api_key_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_section'
        );
        
        add_settings_field(
            'openai_model',
            'OpenAI Modell',
            array($this, 'openai_model_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_section'
        );
        
        add_settings_field(
            'caption_style',
            'Stil der Bildunterschriften',
            array($this, 'caption_style_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_section'
        );
        
        add_settings_field(
            'caption_length',
            'Länge der Bildunterschriften',
            array($this, 'caption_length_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_section'
        );
    }
    
    public function license_section_callback() {
        echo '<p>Geben Sie Ihren Lizenzschlüssel ein, um automatische Updates zu erhalten.</p>';
        echo '<p>Aktuelle Version: ' . AICG_VERSION . '</p>';
        
        // Update-Check Button
        echo '<p><button type="button" id="check-for-updates" class="button">Nach Updates suchen</button> ';
        echo '<span id="update-check-result"></span></p>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#check-for-updates').click(function() {
                var $button = $(this);
                var $result = $('#update-check-result');
                
                $button.prop('disabled', true);
                $result.text('Prüfe auf Updates...');
                
                // Force update check
                $.post(ajaxurl, {
                    action: 'aicg_force_update_check',
                    nonce: '<?php echo wp_create_nonce('aicg_update_check'); ?>'
                }, function(response) {
                    $button.prop('disabled', false);
                    if (response.success) {
                        $result.html(response.data);
                    } else {
                        $result.text('Fehler beim Update-Check');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function license_key_callback() {
        $license = get_option('aicg_license_key', '');
        echo '<input type="text" name="aicg_license_key" value="' . esc_attr($license) . '" size="40" />';
        echo '<p class="description">Erhalten Sie Ihren Lizenzschlüssel unter your-domain.com</p>';
    }
    
    // ... Rest der Callback-Funktionen bleiben gleich ...
    
    public function settings_section_callback() {
        echo '<p>Konfigurieren Sie die API-Einstellungen für die Generierung von Bildunterschriften.</p>';
    }
    
    public function openai_api_key_callback() {
        $value = isset($this->options['openai_api_key']) ? $this->options['openai_api_key'] : '';
        echo '<input type="password" name="ai_image_caption_options[openai_api_key]" value="' . esc_attr($value) . '" size="50" />';
    }
    
    public function openai_model_callback() {
        $value = isset($this->options['openai_model']) ? $this->options['openai_model'] : 'gpt-4o-mini';
        echo '<select name="ai_image_caption_options[openai_model]">';
        echo '<option value="gpt-4o-mini"' . selected($value, 'gpt-4o-mini', false) . '>GPT-4o Mini</option>';
        echo '<option value="gpt-4-vision-preview"' . selected($value, 'gpt-4-vision-preview', false) . '>GPT-4 Vision</option>';
        echo '</select>';
    }
    
    public function caption_style_callback() {
        $value = isset($this->options['caption_style']) ? $this->options['caption_style'] : 'inspirierend';
        echo '<select name="ai_image_caption_options[caption_style]">';
        echo '<option value="inspirierend"' . selected($value, 'inspirierend', false) . '>Inspirierend & atmosphärisch</option>';
        echo '<option value="sachlich"' . selected($value, 'sachlich', false) . '>Sachlich & beschreibend</option>';
        echo '<option value="marketing"' . selected($value, 'marketing', false) . '>Marketing & emotional</option>';
        echo '</select>';
        echo '<p class="description">Inspirierend: Wie in Reisemagazinen. Sachlich: Neutrale Beschreibung. Marketing: Verkaufsorientiert.</p>';
    }
    
    public function caption_length_callback() {
        $value = isset($this->options['caption_length']) ? $this->options['caption_length'] : 'kurz';
        echo '<select name="ai_image_caption_options[caption_length]">';
        echo '<option value="sehr_kurz"' . selected($value, 'sehr_kurz', false) . '>Sehr kurz (max. 15 Wörter)</option>';
        echo '<option value="kurz"' . selected($value, 'kurz', false) . '>Kurz (max. 25 Wörter)</option>';
        echo '<option value="mittel"' . selected($value, 'mittel', false) . '>Mittel (max. 40 Wörter)</option>';
        echo '</select>';
        echo '<p class="description">ChatGPT-Stil entspricht "Kurz". Sehr kurz für Social Media, Mittel für Blogbeiträge.</p>';
    }
    
    public function enqueue_scripts($hook) {
        if (in_array($hook, array('post.php', 'post-new.php', 'upload.php', 'settings_page_ai-image-caption-generator'))) {
            wp_enqueue_script('ai-caption-script', plugin_dir_url(__FILE__) . 'ai-caption.js', array('jquery'), AICG_VERSION, true);
            wp_localize_script('ai-caption-script', 'aiCaptionAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_caption_nonce'),
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ));
        }
    }
    
    // ... Rest des Codes bleibt unverändert ...
}

// Plugin initialisieren
new AI_Image_Caption_Generator();

// Force Update Check AJAX Handler
add_action('wp_ajax_aicg_force_update_check', function() {
    check_ajax_referer('aicg_update_check', 'nonce');
    
    if (!current_user_can('update_plugins')) {
        wp_send_json_error('Keine Berechtigung');
    }
    
    // Transient löschen um Update-Check zu erzwingen
    delete_transient('aicg_update_check');
    
    // Update-Check durchführen
    wp_update_plugins();
    
    // Prüfen ob Update verfügbar
    $updates = get_site_transient('update_plugins');
    if (isset($updates->response[AICG_PLUGIN_BASENAME])) {
        $update_info = $updates->response[AICG_PLUGIN_BASENAME];
        $message = sprintf(
            '✓ Update verfügbar! Version %s ist verfügbar. <a href="%s">Jetzt aktualisieren</a>',
            $update_info->new_version,
            admin_url('plugins.php')
        );
    } else {
        $message = '✓ Sie haben bereits die neueste Version.';
    }
    
    wp_send_json_success($message);
});

// Lizenzschlüssel speichern
add_action('update_option_aicg_license_key', function($old_value, $new_value) {
    if ($new_value !== $old_value) {
        // Lizenz beim Server validieren
        delete_transient('aicg_license_status');
    }
}, 10, 2);

// ... Rest der Original-Funktionen ...