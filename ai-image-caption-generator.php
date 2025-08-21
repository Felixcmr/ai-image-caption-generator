<?php
/**
 * Plugin Name: AI Image Caption Generator
 * Description: Generiert Bildunterschriften und Alt-Text für Medien über KI-APIs
 * Version: 2.4.2
 * Author: Your Name
 * Plugin URI: https://github.com/Felixcmr/ai-image-caption-generator
 * GitHub Plugin URI: https://github.com/Felixcmr/ai-image-caption-generator
 * Primary Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Image_Caption_Generator {
    
    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_generate_caption', array($this, 'ajax_generate_caption'));
        add_action('wp_ajax_save_caption', array($this, 'ajax_save_caption'));
        add_action('wp_ajax_save_alt_text', array($this, 'ajax_save_alt_text'));
        add_action('wp_ajax_generate_single_alt_text', array($this, 'ajax_generate_single_alt_text'));
        add_filter('attachment_fields_to_edit', array($this, 'add_caption_button'), 10, 2);
        
        // Bulk-Actions in der Mediathek
        add_filter('bulk_actions-upload', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_notices'));
        
        // Spalte in der Mediathek für Alt-Text-Status
        add_filter('manage_media_columns', array($this, 'add_alt_text_column'));
        add_action('manage_media_custom_column', array($this, 'display_alt_text_column'), 10, 2);
        // Filter für Mediathek
        add_action('restrict_manage_posts', array($this, 'add_alt_text_filter'));
        add_filter('parse_query', array($this, 'filter_by_alt_text'));
        
        // Bulk-Button in der Mediathek
        add_action('admin_footer-upload.php', array($this, 'add_bulk_generate_button'));
    }
    
    public function add_alt_text_filter() {
        $screen = get_current_screen();
        if ($screen->id !== 'upload') {
            return;
        }
        
        $selected = isset($_GET['alt_text_filter']) ? $_GET['alt_text_filter'] : '';
        ?>
        <select name="alt_text_filter" id="alt_text_filter">
            <option value="">Alle Bilder</option>
            <option value="no_alt" <?php selected($selected, 'no_alt'); ?>>Ohne Alt-Text</option>
            <option value="has_alt" <?php selected($selected, 'has_alt'); ?>>Mit Alt-Text</option>
        </select>
        <?php
    }
    
    public function filter_by_alt_text($query) {
        global $pagenow;
        
        if ($pagenow !== 'upload.php' || !is_admin()) {
            return;
        }
        
        if (isset($_GET['alt_text_filter']) && $_GET['alt_text_filter'] !== '') {
            if ($_GET['alt_text_filter'] === 'no_alt') {
                $query->set('meta_query', array(
                    'relation' => 'OR',
                    array(
                        'key' => '_wp_attachment_image_alt',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => '_wp_attachment_image_alt',
                        'value' => '',
                        'compare' => '='
                    )
                ));
            } elseif ($_GET['alt_text_filter'] === 'has_alt') {
                $query->set('meta_query', array(
                    array(
                        'key' => '_wp_attachment_image_alt',
                        'value' => '',
                        'compare' => '!='
                    )
                ));
            }
        }
    }
    
    public function add_bulk_generate_button() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Füge Button zur Toolbar hinzu
            if ($('.wp-filter .media-toolbar-secondary').length === 0) {
                $('.wp-filter').append('<div class="media-toolbar-secondary"></div>');
            }
            
            var buttonHtml = '<button type="button" id="bulk-generate-page" class="button button-primary" style="margin-left: 10px;">KI Alt-Texte für diese Seite generieren</button>';
            $('.wp-filter .media-toolbar-secondary').append(buttonHtml);
            
            // Zeige Button nur wenn Bilder ohne Alt-Text vorhanden sind
            checkForMissingAltTexts();
            
            function checkForMissingAltTexts() {
                var hasImagesWithoutAlt = false;
                $('.alt_text_status').each(function() {
                    if ($(this).text().indexOf('Fehlt') > -1) {
                        hasImagesWithoutAlt = true;
                        return false;
                    }
                });
                
                if (hasImagesWithoutAlt) {
                    $('#bulk-generate-page').show();
                } else {
                    $('#bulk-generate-page').hide();
                }
            }
            
            // Button Handler
            $('#bulk-generate-page').click(function() {
                var button = $(this);
                var originalText = button.text();
                
                // Sammle alle Bilder ohne Alt-Text auf dieser Seite
                var imagesWithoutAlt = [];
                $('.alt_text_status').each(function() {
                    if ($(this).text().indexOf('Fehlt') > -1) {
                        var attachmentId = $(this).find('.generate-alt-text-single').data('attachment-id');
                        if (attachmentId) {
                            imagesWithoutAlt.push(attachmentId);
                        }
                    }
                });
                
                if (imagesWithoutAlt.length === 0) {
                    alert('Keine Bilder ohne Alt-Text auf dieser Seite gefunden.');
                    return;
                }
                
                if (!confirm('Alt-Texte für ' + imagesWithoutAlt.length + ' Bilder auf dieser Seite generieren?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Generiere Alt-Texte...');
                
                var processed = 0;
                var errors = 0;
                
                function processNext() {
                    if (processed >= imagesWithoutAlt.length) {
                        // Fertig
                        var message = 'Fertig! ' + (processed - errors) + ' Alt-Texte generiert';
                        if (errors > 0) {
                            message += ', ' + errors + ' Fehler';
                        }
                        button.text(message);
                        setTimeout(function() {
                            button.prop('disabled', false).text(originalText);
                            checkForMissingAltTexts();
                        }, 3000);
                        return;
                    }
                    
                    var attachmentId = imagesWithoutAlt[processed];
                    var progressText = 'Generiere ' + (processed + 1) + ' von ' + imagesWithoutAlt.length + '...';
                    button.text(progressText);
                    
                    $.ajax({
                        url: aiCaptionAjax.ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'generate_single_alt_text',
                            attachment_id: attachmentId,
                            nonce: aiCaptionAjax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                // Update der Anzeige
                                var cell = $('[data-attachment-id="' + attachmentId + '"]').closest('td');
                                cell.html('<span style="color: green;">✓ Vorhanden</span><br><small>' + response.data.alt_text.substring(0, 50) + '...</small>');
                            } else {
                                errors++;
                            }
                            processed++;
                            setTimeout(processNext, 1000); // 1 Sekunde Pause
                        },
                        error: function() {
                            errors++;
                            processed++;
                            setTimeout(processNext, 1000);
                        }
                    });
                }
                
                // Starte Verarbeitung
                processNext();
            });
        });
        </script>
        <?php
    }
    
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
        
        add_settings_field(
            'caption_field',
            'Bildunterschrift speichern in',
            array($this, 'caption_field_callback'),
            'ai_image_caption_settings',
            'ai_image_caption_section'
        );
    }
    
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
        echo '<option value="gpt-5"' . selected($value, 'gpt-5', false) . '>GPT-5 (neuestes Modell)</option>';
        echo '<option value="gpt-5-mini"' . selected($value, 'gpt-5-mini', false) . '>GPT-5 Mini (schneller & günstiger)</option>';
        echo '<option value="gpt-5-nano"' . selected($value, 'gpt-5-nano', false) . '>GPT-5 Nano (sehr günstig & schnell)</option>';
        echo '<option value="gpt-4o"' . selected($value, 'gpt-4o', false) . '>GPT-4o</option>';
        echo '<option value="gpt-4o-mini"' . selected($value, 'gpt-4o-mini', false) . '>GPT-4o Mini</option>';
        echo '<option value="gpt-4-vision-preview"' . selected($value, 'gpt-4-vision-preview', false) . '>GPT-4 Vision</option>';
        echo '</select>';
        echo '<p class="description">GPT-5 ist das beste Modell. GPT-5 Mini ist günstiger, GPT-5 Nano am günstigsten.</p>';
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
    
    public function caption_field_callback() {
        $value = isset($this->options['caption_field']) ? $this->options['caption_field'] : 'excerpt';
        echo '<select name="ai_image_caption_options[caption_field]">';
        echo '<option value="excerpt"' . selected($value, 'excerpt', false) . '>Bildunterschrift (Standard)</option>';
        echo '<option value="content"' . selected($value, 'content', false) . '>Beschreibung</option>';
        echo '</select>';
        echo '<p class="description">Wählen Sie, wo die generierte Bildunterschrift gespeichert werden soll.</p>';
    }
    
    public function enqueue_scripts($hook) {
        if (in_array($hook, array('post.php', 'post-new.php', 'upload.php', 'media_page_ai-bulk-alt-text', 'settings_page_ai-image-caption-generator'))) {
            wp_enqueue_script('ai-caption-script', plugin_dir_url(__FILE__) . 'ai-caption.js', array('jquery'), '2.4', true);
            wp_localize_script('ai-caption-script', 'aiCaptionAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_caption_nonce'),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'captionField' => isset($this->options['caption_field']) ? $this->options['caption_field'] : 'excerpt'
            ));
        }
    }
    
    public function add_alt_text_column($columns) {
        $columns['alt_text_status'] = 'Alt-Text';
        return $columns;
    }
    
    public function display_alt_text_column($column_name, $post_id) {
        if ($column_name === 'alt_text_status') {
            $alt_text = get_post_meta($post_id, '_wp_attachment_image_alt', true);
            if (!empty($alt_text)) {
                echo '<span style="color: green;">✓ Vorhanden</span>';
            } else {
                echo '<span style="color: red;">✗ Fehlt</span>';
                echo '<br><button type="button" class="button button-small generate-alt-text-single" data-attachment-id="' . $post_id . '">Generieren</button>';
            }
        }
    }
    
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['generate_alt_texts'] = 'AI Alt-Texte generieren';
        return $bulk_actions;
    }
    
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'generate_alt_texts') {
            return $redirect_to;
        }
        
        // Speichere die IDs für die Verarbeitung
        set_transient('ai_bulk_generate_ids', $post_ids, 300);
        
        $redirect_to = add_query_arg('bulk_ai_generate', count($post_ids), $redirect_to);
        return $redirect_to;
    }
    
    public function bulk_action_notices() {
        if (!empty($_REQUEST['bulk_ai_generate'])) {
            $count = intval($_REQUEST['bulk_ai_generate']);
            ?>
            <div class="notice notice-info is-dismissible">
                <p><?php printf('AI Alt-Text Generierung wurde für %d Bilder gestartet.', $count); ?></p>
                <p><a href="<?php echo admin_url('upload.php?page=ai-bulk-alt-text'); ?>" class="button button-primary">Zur Bulk-Generierung</a></p>
            </div>
            <?php
        }
    }
    
    public function ajax_generate_single_alt_text() {
        if (!wp_verify_nonce($_POST['nonce'], 'ai_caption_nonce')) {
            wp_send_json_error('Sicherheitscheck fehlgeschlagen');
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        if (!$attachment_id) {
            wp_send_json_error('Ungültige Bild-ID');
        }
        
        // Generiere nur Alt-Text
        $result = $this->generate_alt_text_only($attachment_id);
        
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
        } else {
            // Speichere den Alt-Text
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $result['alt_text']);
            
            wp_send_json_success(array(
                'alt_text' => $result['alt_text'],
                'message' => 'Alt-Text erfolgreich generiert und gespeichert'
            ));
        }
    }
    
    private function generate_alt_text_only($attachment_id) {
        $image_url = wp_get_attachment_url($attachment_id);
        if (!$image_url) {
            return array('error' => 'Bild-URL konnte nicht ermittelt werden');
        }
        
        // Hole Metadaten
        $attachment = get_post($attachment_id);
        $image_title = $attachment->post_title;
        $image_filename = basename($image_url);
        $parent_id = $attachment->post_parent;
        
        $api_key = isset($this->options['openai_api_key']) ? $this->options['openai_api_key'] : '';
        if (empty($api_key)) {
            return array('error' => 'OpenAI API-Schlüssel fehlt');
        }
        
        $model = isset($this->options['openai_model']) ? $this->options['openai_model'] : 'gpt-4o-mini';
        
        // Kontext aufbauen
        $context_parts = array();
        
        if (!empty($image_title) && $image_title !== $image_filename) {
            $context_parts[] = "Bildtitel: " . $image_title;
        }
        
        if (!empty($image_filename)) {
            $filename_clean = pathinfo($image_filename, PATHINFO_FILENAME);
            $filename_clean = str_replace(array('-', '_'), ' ', $filename_clean);
            if (strlen($filename_clean) > 3 && !preg_match('/^(img|image|photo|dsc|p)\d*$/i', $filename_clean)) {
                $context_parts[] = "Dateiname: " . $filename_clean;
            }
        }
        
        // Zugeordneter Artikel
        if ($parent_id > 0) {
            $parent_post = get_post($parent_id);
            if ($parent_post) {
                $context_parts[] = "Verwendet in Artikel: " . $parent_post->post_title;
                
                // Optional: Hole Kategorien und Tags
                $categories = get_the_category($parent_id);
                if (!empty($categories)) {
                    $cat_names = array_map(function($cat) { return $cat->name; }, $categories);
                    $context_parts[] = "Kategorien: " . implode(', ', $cat_names);
                }
                
                $tags = get_the_tags($parent_id);
                if (!empty($tags)) {
                    $tag_names = array_map(function($tag) { return $tag->name; }, $tags);
                    $context_parts[] = "Schlagwörter: " . implode(', ', $tag_names);
                }
            }
        }
        
        // Prüfe auch wo das Bild verwendet wird (falls nicht direkt zugeordnet)
        if ($parent_id == 0) {
            global $wpdb;
            $used_in = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts} 
                WHERE post_content LIKE %s 
                AND post_status = 'publish' 
                AND post_type IN ('post', 'page')
                LIMIT 1",
                '%' . $wpdb->esc_like($image_url) . '%'
            ));
            
            if (!empty($used_in)) {
                $context_parts[] = "Verwendet in: " . $used_in[0]->post_title;
            }
        }
        
        $context_info = '';
        if (!empty($context_parts)) {
            $context_info = "\n\nKontextinformationen:\n" . implode("\n", $context_parts) . "\n\nBerücksichtige diese Informationen für einen präzisen, kontextbezogenen Alt-Text.";
        }
        
        $prompt = 'Erstelle einen präzisen Alt-Text für dieses Bild zur Barrierefreiheit. Der Alt-Text soll:
- Das Bild sachlich und objektiv beschreiben
- Die wichtigsten visuellen Elemente nennen
- Den Kontext des Artikels berücksichtigen (falls vorhanden)
- Für Screenreader optimiert sein
- Maximal 125 Zeichen lang sein
- KEINE Formatierungszeichen enthalten' . $context_info . '

Antworte NUR mit dem Alt-Text, ohne zusätzliche Erklärungen.';
        
        $body = json_encode(array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => array(
                                'url' => $image_url
                            )
                        )
                    )
                )
            ),
            'max_tokens' => 100
        ));
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => $body
        ));
        
        if (is_wp_error($response)) {
            return array('error' => 'Verbindungsfehler: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body_content = wp_remote_retrieve_body($response);
            $error_data = json_decode($body_content, true);
            $error_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTP ' . $status_code;
            return array('error' => 'OpenAI Fehler: ' . $error_msg);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array('error' => 'Keine Antwort von OpenAI erhalten');
        }
        
        $alt_text = trim($data['choices'][0]['message']['content']);
        $alt_text = $this->clean_formatting($alt_text);
        
        return array('alt_text' => $alt_text);
    }
    
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>AI Image Caption Generator</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ai_image_caption_settings');
                do_settings_sections('ai_image_caption_settings');
                submit_button();
                ?>
            </form>
            
            <h2>Tipp: Bulk-Generierung</h2>
            <p>
                Sie können Alt-Texte für mehrere Bilder gleichzeitig generieren:
            </p>
            <ol>
                <li>Gehen Sie zur <a href="<?php echo admin_url('upload.php'); ?>">Mediathek</a></li>
                <li>Nutzen Sie den Filter "Ohne Alt-Text" um nur Bilder ohne Alt-Text anzuzeigen</li>
                <li>Klicken Sie auf "KI Alt-Texte für diese Seite generieren" um alle sichtbaren Bilder zu verarbeiten</li>
            </ol>
            
            <h2>Tests</h2>
            <p>
                <button id="test-connection" class="button">API-Verbindung testen</button>
                <span id="test-result"></span>
            </p>
            
            <h2>Update-Checker Debug</h2>
            <p>
                <button id="debug-update-checker" class="button">Update-Checker testen</button>
                <span id="update-debug-result"></span>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-connection').click(function() {
                var button = $(this);
                var result = $('#test-result');
                
                button.prop('disabled', true);
                result.html(' Teste...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_ai_connection',
                        nonce: '<?php echo wp_create_nonce('test_ai_connection'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html(' ✓ OK');
                        } else {
                            result.html(' ✗ ' + response.data);
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
            
            $('#debug-update-checker').click(function() {
                var button = $(this);
                var result = $('#update-debug-result');
                
                button.prop('disabled', true);
                result.html(' Teste Update-Checker...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'debug_update_checker',
                        nonce: '<?php echo wp_create_nonce('debug_update_checker'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<br><strong>GitHub API Tests:</strong><ul>';
                            for (var endpoint in response.data) {
                                var status = response.data[endpoint].includes('OK') ? '✓' : '✗';
                                html += '<li>' + status + ' ' + endpoint + ': ' + response.data[endpoint] + '</li>';
                            }
                            html += '</ul>';
                            result.html(html);
                        } else {
                            result.html(' ✗ ' + response.data);
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function add_caption_button($form_fields, $post) {
        if (!wp_attachment_is_image($post->ID)) {
            return $form_fields;
        }
        
        $current_caption = $post->post_excerpt;
        $current_description = $post->post_content;
        $current_alt = get_post_meta($post->ID, '_wp_attachment_image_alt', true);
        
        $caption_field = isset($this->options['caption_field']) ? $this->options['caption_field'] : 'excerpt';
        $display_caption = $caption_field === 'excerpt' ? $current_caption : $current_description;
        
        $image_title = $post->post_title;
        $image_url = wp_get_attachment_url($post->ID);
        $image_filename = basename($image_url);
        $filename_clean = pathinfo($image_filename, PATHINFO_FILENAME);
        $filename_clean = str_replace(array('-', '_'), ' ', $filename_clean);
        
        $auto_context = array();
        if (!empty($image_title) && $image_title !== $image_filename) {
            $auto_context[] = "Titel: " . esc_html($image_title);
        }
        if (strlen($filename_clean) > 3 && !preg_match('/^(img|image|photo|dsc|p)\d*$/i', $filename_clean)) {
            $auto_context[] = "Dateiname: " . esc_html($filename_clean);
        }
        
        $field_label = $caption_field === 'excerpt' ? 'Bildunterschrift' : 'Beschreibung';
        
        $form_fields['ai_caption_generator'] = array(
            'label' => 'KI-Beschreibung',
            'input' => 'html',
            'html' => '
                <div style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                    <p><strong>Aktuelle Werte:</strong></p>
                    <p>' . $field_label . ': <em id="current-caption-' . $post->ID . '">' . (empty($display_caption) ? 'Keine' : esc_html($display_caption)) . '</em></p>
                    <p>Alt-Text: <em id="current-alt-' . $post->ID . '">' . (empty($current_alt) ? 'Keiner' : esc_html($current_alt)) . '</em></p>
                    
                    ' . (!empty($auto_context) ? '<p><strong>Automatisch erkannte Informationen:</strong><br><small style="color: #666;">' . implode(' | ', $auto_context) . '</small></p>' : '') . '
                    
                    <p>
                        <label><strong>Zusätzlicher Kontext (optional):</strong></label><br>
                        <input type="text" id="context-' . $post->ID . '" placeholder="z.B. Aufgenommen auf Langeoog, Urlaubsfoto, Firmenfeier..." style="width: 100%; margin-bottom: 10px;" />
                        <small>Die KI nutzt automatisch Bildtitel und Dateiname. Hier können Sie weitere Infos ergänzen.</small>
                    </p>
                    
                    <p>
                        <button type="button" class="button button-primary ai-generate-btn" data-attachment-id="' . $post->ID . '">
                            KI-Beschreibung generieren
                        </button>
                        <span id="loading-' . $post->ID . '" style="display:none;">⏳ Generiere...</span>
                    </p>
                    
                    <div id="result-' . $post->ID . '" style="display:none; margin-top: 15px; padding: 10px; background: #fff; border: 1px solid #ccc;">
                        <h4>Generierte Beschreibungen:</h4>
                        <p>
                            <label><strong>' . $field_label . ':</strong></label><br>
                            <textarea id="caption-' . $post->ID . '" rows="3" style="width: 100%;"></textarea>
                        </p>
                        <p>
                            <label><strong>Alt-Text:</strong></label><br>
                            <textarea id="alt-' . $post->ID . '" rows="2" style="width: 100%;"></textarea>
                        </p>
                        <p>
                            <button type="button" class="button button-primary ai-save-caption-only" data-attachment-id="' . $post->ID . '">
                                ' . $field_label . ' übernehmen
                            </button>
                            <button type="button" class="button button-primary ai-save-alt-only" data-attachment-id="' . $post->ID . '">
                                Alt-Text übernehmen
                            </button>
                            <button type="button" class="button ai-save-btn" data-attachment-id="' . $post->ID . '">
                                Beides übernehmen
                            </button>
                            <button type="button" class="button ai-cancel-btn" data-attachment-id="' . $post->ID . '">
                                Abbrechen
                            </button>
                        </p>
                    </div>
                    
                    <div id="error-' . $post->ID . '" style="display:none; color: red; margin-top: 10px;"></div>
                    <div id="success-' . $post->ID . '" style="display:none; color: green; margin-top: 10px;"></div>
                </div>
            '
        );
        
        return $form_fields;
    }
    
    public function ajax_generate_caption() {
        if (!wp_verify_nonce($_POST['nonce'], 'ai_caption_nonce')) {
            wp_send_json_error('Sicherheitscheck fehlgeschlagen');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        $context = sanitize_text_field($_POST['context']);
        
        if (!$attachment_id) {
            wp_send_json_error('Ungültige Bild-ID');
        }
        
        $result = $this->generate_caption_for_image($attachment_id, $context);
        
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
        } else {
            wp_send_json_success($result);
        }
    }
    
    public function generate_caption_for_image($attachment_id, $context = '') {
        $image_url = wp_get_attachment_url($attachment_id);
        if (!$image_url) {
            return array('error' => 'Bild-URL konnte nicht ermittelt werden');
        }
        
        $attachment = get_post($attachment_id);
        $image_title = $attachment->post_title;
        $image_filename = basename($image_url);
        $image_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        
        $api_key = isset($this->options['openai_api_key']) ? $this->options['openai_api_key'] : '';
        if (empty($api_key)) {
            return array('error' => 'OpenAI API-Schlüssel fehlt in den Einstellungen');
        }
        
        $model = isset($this->options['openai_model']) ? $this->options['openai_model'] : 'gpt-4o-mini';
        $caption_style = isset($this->options['caption_style']) ? $this->options['caption_style'] : 'inspirierend';
        $caption_length = isset($this->options['caption_length']) ? $this->options['caption_length'] : 'kurz';
        
        $length_limits = array(
            'sehr_kurz' => 15,
            'kurz' => 25,
            'mittel' => 40
        );
        $word_limit = $length_limits[$caption_length];
        
        if ($caption_style === 'inspirierend') {
            $style_instruction = 'Schreibe eine kurze, prägnante Bildunterschrift im Stil eines hochwertigen Reisemagazins. Verwende maximal 1-2 kurze Sätze. Der erste Satz sollte poetisch und eingängig sein, der zweite Satz kann eine kurze Ergänzung liefern. Beispiel: "Dem Wind entgegen – mit dem Rad durch die Dünen von Langeoog. Ein Weg durch stille Natur und weite Horizonte." WICHTIG: Verwende KEINE Formatierungszeichen wie ** oder andere Markdown-Symbole.';
        } elseif ($caption_style === 'marketing') {
            $style_instruction = 'Schreibe eine kurze, verkaufsorientierte Bildunterschrift (maximal 1-2 Sätze), die zum Handeln motiviert. Erste Zeile als eingängige Headline, zweite als Call-to-Action. KEINE Formatierungszeichen verwenden.';
        } else {
            $style_instruction = 'Schreibe eine kurze, sachliche Bildunterschrift in 1-2 präzisen Sätzen, die das Bild objektiv beschreibt. KEINE Formatierungszeichen verwenden.';
        }
        
        $auto_context_parts = array();
        
        if (!empty($image_title) && $image_title !== $image_filename) {
            $auto_context_parts[] = "Bildtitel: " . $image_title;
        }
        
        if (!empty($image_filename)) {
            $filename_clean = pathinfo($image_filename, PATHINFO_FILENAME);
            $filename_clean = str_replace(array('-', '_'), ' ', $filename_clean);
            if (strlen($filename_clean) > 3 && !preg_match('/^(img|image|photo|dsc|p)\d*$/i', $filename_clean)) {
                $auto_context_parts[] = "Dateiname: " . $filename_clean;
            }
        }
        
        if (!empty($image_alt)) {
            $auto_context_parts[] = "Aktueller Alt-Text: " . $image_alt;
        }
        
        $context_part = '';
        if (!empty($auto_context_parts) || !empty($context)) {
            $context_part = "\n\nVerfügbare Kontextinformationen:";
            
            if (!empty($auto_context_parts)) {
                $context_part .= "\n" . implode("\n", $auto_context_parts);
            }
            
            if (!empty($context)) {
                $context_part .= "\nZusätzlicher Kontext: " . $context;
            }
            
            $context_part .= "\n\nNutze diese Informationen intelligent für Ortsangaben, Aktivitäten oder weitere Details in der Bildunterschrift.";
        }
        
        $prompt = $style_instruction . '

WICHTIG: Die Bildunterschrift soll kurz und prägnant sein - maximal ' . $word_limit . ' Wörter!

Erstelle auch einen präzisen Alt-Text für Barrierefreiheit (sachlich und beschreibend).

' . $context_part . '

Antworte im Format:
BILDUNTERSCHRIFT: [kurze, prägnante Bildunterschrift - maximal ' . $word_limit . ' Wörter]
ALT-TEXT: [sachlicher Alt-Text]';
        
        $body = json_encode(array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => array(
                                'url' => $image_url
                            )
                        )
                    )
                )
            ),
            'max_tokens' => 300
        ));
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => $body
        ));
        
        if (is_wp_error($response)) {
            return array('error' => 'Verbindungsfehler: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body_content = wp_remote_retrieve_body($response);
            $error_data = json_decode($body_content, true);
            $error_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTP ' . $status_code;
            return array('error' => 'OpenAI Fehler: ' . $error_msg);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array('error' => 'Keine Antwort von OpenAI erhalten');
        }
        
        $content = $data['choices'][0]['message']['content'];
        
        preg_match('/BILDUNTERSCHRIFT:\s*(.+?)(?=ALT-TEXT:|$)/s', $content, $caption_matches);
        preg_match('/ALT-TEXT:\s*(.+?)$/s', $content, $alt_matches);
        
        $caption = isset($caption_matches[1]) ? trim($caption_matches[1]) : '';
        $alt_text = isset($alt_matches[1]) ? trim($alt_matches[1]) : '';
        
        if (empty($caption) && empty($alt_text)) {
            $lines = array_filter(explode("\n", trim($content)));
            $caption = isset($lines[0]) ? trim($lines[0]) : '';
            $alt_text = isset($lines[1]) ? trim($lines[1]) : $caption;
        }
        
        if (empty($caption)) {
            $caption = trim($content);
        }
        if (empty($alt_text)) {
            $alt_text = $caption;
        }
        
        $caption = $this->clean_formatting($caption);
        $alt_text = $this->clean_formatting($alt_text);
        
        return array(
            'caption' => $caption,
            'alt_text' => $alt_text
        );
    }
    
    public function ajax_save_caption() {
        if (!wp_verify_nonce($_POST['nonce'], 'ai_caption_nonce')) {
            wp_send_json_error('Sicherheitscheck fehlgeschlagen');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        $caption = wp_kses_post($_POST['caption']);
        $save_both = isset($_POST['save_both']) ? $_POST['save_both'] === 'true' : true;
        
        if (!$attachment_id) {
            wp_send_json_error('Ungültige Bild-ID');
        }
        
        $caption_field = isset($this->options['caption_field']) ? $this->options['caption_field'] : 'excerpt';
        
        if ($caption_field === 'content') {
            $update_data = array(
                'ID' => $attachment_id,
                'post_content' => $caption
            );
        } else {
            $update_data = array(
                'ID' => $attachment_id,
                'post_excerpt' => $caption
            );
        }
        
        $updated = wp_update_post($update_data, true);
        
        if (is_wp_error($updated)) {
            wp_send_json_error('Fehler beim Speichern: ' . $updated->get_error_message());
        }
        
        // Alt-Text nur speichern wenn beide gespeichert werden sollen
        if ($save_both && isset($_POST['alt_text'])) {
            $alt_text = sanitize_text_field($_POST['alt_text']);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }
        
        $field_label = $caption_field === 'excerpt' ? 'Bildunterschrift' : 'Beschreibung';
        
        wp_send_json_success(array(
            'message' => $save_both ? 'Beschreibungen erfolgreich gespeichert' : $field_label . ' erfolgreich gespeichert',
            'caption' => $caption
        ));
    }
    
    public function ajax_save_alt_text() {
        if (!wp_verify_nonce($_POST['nonce'], 'ai_caption_nonce')) {
            wp_send_json_error('Sicherheitscheck fehlgeschlagen');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $attachment_id = intval($_POST['attachment_id']);
        $alt_text = sanitize_text_field($_POST['alt_text']);
        
        if (!$attachment_id) {
            wp_send_json_error('Ungültige Bild-ID');
        }
        
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        
        wp_send_json_success(array(
            'message' => 'Alt-Text erfolgreich gespeichert',
            'alt_text' => $alt_text
        ));
    }
    
    private function clean_formatting($text) {
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);
        $text = preg_replace('/\*(.*?)\*/', '$1', $text);
        $text = preg_replace('/_(.*?)_/', '$1', $text);
        $text = preg_replace('/`(.*?)`/', '$1', $text);
        $text = preg_replace('/#{1,6}\s*/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

// Plugin initialisieren
new AI_Image_Caption_Generator();

// Test-Funktion für API-Verbindung
add_action('wp_ajax_test_ai_connection', function() {
    check_ajax_referer('test_ai_connection', 'nonce');
    
    $options = get_option('ai_image_caption_options', array());
    $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    
    if (empty($api_key)) {
        wp_send_json_error('API-Schlüssel fehlt');
        return;
    }
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'timeout' => 10,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ),
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array('role' => 'user', 'content' => 'Test')
            ),
            'max_tokens' => 5
        ))
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Verbindungsfehler');
        return;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code === 200) {
        wp_send_json_success('API funktioniert mit ' . (isset($options['openai_model']) ? $options['openai_model'] : 'gpt-4o-mini'));
    } else {
        wp_send_json_error('HTTP ' . $status_code);
    }
});

// Aktivierungs-Hook
register_activation_hook(__FILE__, function() {
    $default_options = array(
        'openai_model' => 'gpt-4o-mini',
        'caption_style' => 'inspirierend',
        'caption_length' => 'kurz',
        'caption_field' => 'excerpt'
    );
    add_option('ai_image_caption_options', $default_options);
});

// Debug-Funktion für Update-Checker
add_action('wp_ajax_debug_update_checker', function() {
    check_ajax_referer('debug_update_checker', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
        return;
    }
    
    $github_token = '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'; // Ersetzen Sie dies durch Ihr neues Token
    
    // Test verschiedene GitHub API Endpunkte
    $endpoints = array(
        'repo' => 'https://api.github.com/repos/Felixcmr/ai-image-caption-generator',
        'releases' => 'https://api.github.com/repos/Felixcmr/ai-image-caption-generator/releases',
        'tags' => 'https://api.github.com/repos/Felixcmr/ai-image-caption-generator/tags',
        'latest_release' => 'https://api.github.com/repos/Felixcmr/ai-image-caption-generator/releases/latest'
    );
    
    $results = array();
    
    foreach ($endpoints as $name => $url) {
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'token ' . $github_token,
                'User-Agent' => 'WordPress-Plugin-AI-Caption-Generator',
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));
        
        if (is_wp_error($response)) {
            $results[$name] = 'Fehler: ' . $response->get_error_message();
        } else {
            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status === 200) {
                $data = json_decode($body, true);
                if ($name === 'latest_release') {
                    $results[$name] = 'OK - Version: ' . ($data['tag_name'] ?? 'Keine');
                } else {
                    $count = is_array($data) ? count($data) : 1;
                    $results[$name] = 'OK - ' . $count . ' Einträge';
                }
            } else {
                $results[$name] = 'HTTP ' . $status . ' - ' . substr($body, 0, 100);
            }
        }
    }
    
    wp_send_json_success($results);
});

// --- Plugin Update Checker für privates Repository ---
add_action('plugins_loaded', function () {
    $path = __DIR__ . '/inc/plugin-update-checker-master/plugin-update-checker.php';
    if (!file_exists($path)) {
        add_action('admin_notices', function () use ($path) {
            echo '<div class="notice notice-error"><p><strong>AI Image Caption Generator:</strong> PUC nicht gefunden: '
                 . esc_html($path) . '</p></div>';
        });
        return;
    }

    require_once $path;

    try {
        $updater = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
            'https://github.com/Felixcmr/ai-image-caption-generator',
            __FILE__,
            'ai-image-caption-generator'
        );

        // Setzen Sie hier Ihr neues GitHub Token ein
        $github_token = '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'; // Ersetzen Sie dies durch Ihr neues Token
        
        $updater->setAuthentication($github_token);
        $updater->setBranch('main');

        // Zusätzliche Konfiguration für private Repositories
        $updater->addQueryArgFilter(function($queryArgs) use ($github_token) {
            $queryArgs['access_token'] = $github_token;
            return $queryArgs;
        });

        $vcs = $updater->getVcsApi();
        if ($vcs) {
            $vcs->enableReleaseAssets();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AI Caption Generator: PUC für privates Repository initialisiert');
        }

    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AI Caption Generator PUC Fehler: ' . $e->getMessage());
        }
        
        add_action('admin_notices', function () use ($e) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p><strong>AI Image Caption Generator:</strong> Update-Checker Fehler: ' 
                     . esc_html($e->getMessage()) . '</p></div>';
            }
        });
    }
});

