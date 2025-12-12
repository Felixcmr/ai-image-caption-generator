jQuery(document).ready(function($) {
    // Debug-Modus
    var debug = aiCaptionAjax.debug || false;
    
    function log(message) {
        if (debug && console && console.log) {
            console.log('AI Caption Generator: ' + message);
        }
    }
    
    // Debug-Funktion zum Finden der richtigen Selektoren
    function debugMediaFields() {
        log('=== Debug: Suche nach Media-Feldern ===');
        
        // Alt-Text Felder
        log('Alt-Text Felder:');
        log('  input[name="_wp_attachment_image_alt"]: ' + $('input[name="_wp_attachment_image_alt"]').length);
        log('  #attachment_alt: ' + $('#attachment_alt').length);
        log('  #attachment-details-alt-text: ' + $('#attachment-details-alt-text').length);
        
        // Caption Felder (excerpt)
        log('Caption Felder (excerpt):');
        log('  textarea[name="excerpt"]: ' + $('textarea[name="excerpt"]').length);
        log('  #attachment_caption: ' + $('#attachment_caption').length);
        log('  #attachment-details-caption: ' + $('#attachment-details-caption').length);
        
        // Content Felder
        log('Content Felder:');
        log('  textarea[name="content"]: ' + $('textarea[name="content"]').length);
        log('  #attachment_content: ' + $('#attachment_content').length);
        log('  #attachment-details-description: ' + $('#attachment-details-description').length);
        
        // Alle textarea und input Felder anzeigen
        log('Alle Textareas auf der Seite:');
        $('textarea').each(function(i) {
            log('  ' + i + ': id="' + $(this).attr('id') + '" name="' + $(this).attr('name') + '"');
        });
        
        log('Alle relevanten Input-Felder:');
        $('input[type="text"]').each(function(i) {
            var name = $(this).attr('name');
            if (name && name.indexOf('alt') !== -1) {
                log('  ' + i + ': id="' + $(this).attr('id') + '" name="' + name + '"');
            }
        });
    }
    
    // Event-Handler für Generieren-Button
    $(document).on('click', '.ai-generate-btn', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var loading = $('#loading-' + attachmentId);
        var result = $('#result-' + attachmentId);
        var error = $('#error-' + attachmentId);
        var success = $('#success-' + attachmentId);
        var context = $('#context-' + attachmentId).val();
        
        log('Generiere für Attachment ID: ' + attachmentId);
        
        loading.show();
        result.hide();
        error.hide();
        success.hide();
        
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'generate_caption',
                attachment_id: attachmentId,
                context: context,
                nonce: aiCaptionAjax.nonce
            },
            success: function(response) {
                loading.hide();
                log('Generierung Antwort: ' + JSON.stringify(response));
                
                if (response.success) {
                    $('#caption-' + attachmentId).val(response.data.caption);
                    $('#alt-' + attachmentId).val(response.data.alt_text);
                    result.show();
                } else {
                    error.html('Fehler: ' + response.data).show();
                }
            },
            error: function(xhr, status, errorThrown) {
                loading.hide();
                log('AJAX-Fehler: ' + status + ' - ' + errorThrown);
                error.html('Verbindungsfehler: ' + errorThrown).show();
            }
        });
    });
    
    // Event-Handler für "Beides übernehmen"
    $(document).on('click', '.ai-save-btn', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var caption = $('#caption-' + attachmentId).val();
        var altText = $('#alt-' + attachmentId).val();
        
        saveCaption(attachmentId, caption, altText, true);
    });
    
    // Event-Handler für "Bildunterschrift übernehmen"
    $(document).on('click', '.ai-save-caption-only', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var caption = $('#caption-' + attachmentId).val();
        
        saveCaption(attachmentId, caption, null, false);
    });
    
    // Event-Handler für "Alt-Text übernehmen"
    $(document).on('click', '.ai-save-alt-only', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var altText = $('#alt-' + attachmentId).val();
        
        saveAltText(attachmentId, altText);
    });
    
    // Speicherfunktion für Bildunterschrift
    function saveCaption(attachmentId, caption, altText, saveBoth) {
        var success = $('#success-' + attachmentId);
        var error = $('#error-' + attachmentId);
        var result = $('#result-' + attachmentId);
        
        log('Speichere Caption für Attachment ID: ' + attachmentId);
        
        var data = {
            action: 'save_caption',
            attachment_id: attachmentId,
            caption: caption,
            save_both: saveBoth,
            nonce: aiCaptionAjax.nonce
        };
        
        if (saveBoth && altText !== null) {
            data.alt_text = altText;
        }
        
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(response) {
                log('Speichern Antwort: ' + JSON.stringify(response));
                
                if (response.success) {
                    // DEBUG: Zeige verfügbare Felder
                    debugMediaFields();
                    
                    // Aktualisiere die Anzeige
                    var fieldLabel = aiCaptionAjax.captionField === 'content' ? 'Beschreibung' : 'Bildunterschrift';
                    $('#current-caption-' + attachmentId).text(caption || 'Keine');
                    if (saveBoth) {
                        $('#current-alt-' + attachmentId).text(altText || 'Keiner');
                    }
                    
                    // Erfolg anzeigen
                    result.hide();
                    success.html('✓ ' + response.data.message).show();
                    
                    setTimeout(function() {
                        success.fadeOut();
                    }, 3000);
                    
                    // Mediathek-Felder aktualisieren
                    updateMediaLibraryFields(attachmentId, caption, altText, saveBoth);
                    
                } else {
                    error.html('Fehler beim Speichern: ' + response.data).show();
                }
            },
            error: function(xhr, status, errorThrown) {
                log('AJAX-Fehler beim Speichern: ' + status + ' - ' + errorThrown);
                error.html('Verbindungsfehler beim Speichern: ' + errorThrown).show();
            }
        });
    }
    
    // Speicherfunktion für Alt-Text
    function saveAltText(attachmentId, altText) {
        var success = $('#success-' + attachmentId);
        var error = $('#error-' + attachmentId);
        var result = $('#result-' + attachmentId);
        
        log('Speichere Alt-Text für Attachment ID: ' + attachmentId);
        
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'save_alt_text',
                attachment_id: attachmentId,
                alt_text: altText,
                nonce: aiCaptionAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // DEBUG: Zeige verfügbare Felder
                    debugMediaFields();
                    
                    $('#current-alt-' + attachmentId).text(altText || 'Keiner');
                    
                    result.hide();
                    success.html('✓ ' + response.data.message).show();
                    
                    setTimeout(function() {
                        success.fadeOut();
                    }, 3000);
                    
                    // Mediathek-Felder aktualisieren
                    updateMediaLibraryFields(attachmentId, null, altText, false);
                    
                } else {
                    error.html('Fehler beim Speichern: ' + response.data).show();
                }
            },
            error: function(xhr, status, errorThrown) {
                error.html('Verbindungsfehler beim Speichern: ' + errorThrown).show();
            }
        });
    }
    
   // Helper-Funktion zum Aktualisieren der Mediathek-Felder
function updateMediaLibraryFields(attachmentId, caption, altText, updateBoth) {
    log('=== Aktualisiere Felder ===');
    log('Caption: ' + caption);
    log('Alt-Text: ' + altText);
    
    // Bild-Detailseite (Edit Media Page) - Die Haupt-Felder
    if (altText !== null) {
        // Alt-Text Feld
        if ($('#attachment_alt').length) {
            log('Aktualisiere: #attachment_alt');
            $('#attachment_alt').val(altText).trigger('change');
        }
        if ($('input[name="_wp_attachment_image_alt"]').length) {
            log('Aktualisiere: input[name="_wp_attachment_image_alt"]');
            $('input[name="_wp_attachment_image_alt"]').val(altText).trigger('change');
        }
    }
    
    if (caption !== null) {
        // Prüfe welches Feld verwendet werden soll
        if (aiCaptionAjax.captionField === 'excerpt') {
            // Bildunterschrift (excerpt)
            if ($('#attachment_caption').length) {
                log('Aktualisiere: #attachment_caption (excerpt)');
                $('#attachment_caption').val(caption).trigger('change');
            }
            if ($('textarea[name="excerpt"]').length) {
                log('Aktualisiere: textarea[name="excerpt"]');
                $('textarea[name="excerpt"]').val(caption).trigger('change');
            }
        } else if (aiCaptionAjax.captionField === 'content') {
            // Beschreibung (content)
            if ($('#attachment_content').length) {
                log('Aktualisiere: #attachment_content (content)');
                $('#attachment_content').val(caption).trigger('change');
            }
            if ($('textarea[name="content"]').length) {
                log('Aktualisiere: textarea[name="content"]');
                $('textarea[name="content"]').val(caption).trigger('change');
            }
        }
    }
    
    // Standard Mediathek-Modal (Lightbox) - für den Fall dass es im Modal verwendet wird
    if (caption !== null && aiCaptionAjax.captionField === 'excerpt' && $('#attachment-details-caption').length) {
        log('Aktualisiere: #attachment-details-caption (Modal)');
        $('#attachment-details-caption').val(caption).trigger('change');
    }
    if (caption !== null && aiCaptionAjax.captionField === 'content' && $('#attachment-details-description').length) {
        log('Aktualisiere: #attachment-details-description (Modal)');
        $('#attachment-details-description').val(caption).trigger('change');
    }
    if (altText !== null && $('#attachment-details-alt-text').length) {
        log('Aktualisiere: #attachment-details-alt-text (Modal)');
        $('#attachment-details-alt-text').val(altText).trigger('change');
    }
}
    
    // Event-Handler für Abbrechen-Button
    $(document).on('click', '.ai-cancel-btn', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        $('#result-' + attachmentId).hide();
        $('#error-' + attachmentId).hide();
        $('#success-' + attachmentId).hide();
    });
    
    // Event-Handler für automatisch generierte Inhalte beim Upload
    
    // Bildunterschrift aus Upload-Vorschlag übernehmen
    $(document).on('click', '.ai-accept-caption', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var caption = $('#preview-caption-' + attachmentId).val();
        
        saveCaption(attachmentId, caption, null, false);
        
        var successDiv = $('#generated-success-' + attachmentId);
        successDiv.html('✓ Bildunterschrift wurde übernommen').show();
        $(this).prop('disabled', true);
    });
    
    // Alt-Text aus Upload-Vorschlag übernehmen
    $(document).on('click', '.ai-accept-alt', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var altText = $('#preview-alt-' + attachmentId).val();
        
        saveAltText(attachmentId, altText);
        
        var successDiv = $('#generated-success-' + attachmentId);
        successDiv.html('✓ Alt-Text wurde übernommen').show();
        $(this).prop('disabled', true);
    });
    
    // Beides aus Upload-Vorschlag übernehmen
    $(document).on('click', '.ai-accept-both', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var caption = $('#preview-caption-' + attachmentId).val();
        var altText = $('#preview-alt-' + attachmentId).val();
        
        saveCaption(attachmentId, caption, altText, true);
        
        var successDiv = $('#generated-success-' + attachmentId);
        successDiv.html('✓ Beide Texte wurden übernommen').show();
        $(this).parent().find('button').prop('disabled', true);
    });
    
    // Upload-Vorschlag verwerfen
    $(document).on('click', '.ai-reject-generated', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        
        // Lösche die temporären Metadaten
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_caption',
                attachment_id: attachmentId,
                caption: '',
                save_both: false,
                nonce: aiCaptionAjax.nonce
            }
        });
        
        // Verstecke die Vorschau-Box
        $(this).closest('div[style*="border: 2px solid"]').fadeOut();
    });
    
    log('AI Caption Generator JavaScript geladen');
    
    // Prüfe beim Laden der Seite auf automatisch generierte Inhalte
    if ($('.attachment-details').length || $('body').hasClass('upload-php')) {
        // Finde alle Attachment-IDs auf der Seite
        $('[data-attachment-id]').each(function() {
            var attachmentId = $(this).data('attachment-id');
            checkForAutoGeneration(attachmentId);
        });
        
        // Prüfe auch bei dynamisch geladenen Inhalten (Mediathek-Modal)
        $(document).on('DOMNodeInserted', function(e) {
            if ($(e.target).find('[data-attachment-id]').length) {
                $(e.target).find('[data-attachment-id]').each(function() {
                    var attachmentId = $(this).data('attachment-id');
                    checkForAutoGeneration(attachmentId);
                });
            }
        });
    }
    
    // Funktion um auf automatisch generierte Inhalte zu prüfen
    function checkForAutoGeneration(attachmentId) {
        if ($('#generated-check-' + attachmentId).length) {
            return; // Bereits geprüft
        }
        
        // Markiere als geprüft
        $('<div id="generated-check-' + attachmentId + '"></div>').appendTo('body');
        
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'check_auto_generation',
                attachment_id: attachmentId,
                nonce: aiCaptionAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.status === 'pending') {
                        // Warte und prüfe erneut
                        setTimeout(function() {
                            $('#generated-check-' + attachmentId).remove();
                            checkForAutoGeneration(attachmentId);
                        }, 3000);
                    } else if (response.data.status === 'ready') {
                        // Zeige die generierten Inhalte an
                        showAutoGeneratedContent(attachmentId, response.data.caption, response.data.alt_text);
                    }
                }
            }
        });
    }
    
    // Funktion um automatisch generierte Inhalte anzuzeigen
    function showAutoGeneratedContent(attachmentId, caption, altText) {
        // Suche nach dem richtigen Container
        var container = $('[data-attachment-id="' + attachmentId + '"]').closest('.attachment-info');
        if (!container.length) {
            container = $('#post-' + attachmentId);
        }
        if (!container.length) {
            return;
        }
        
        // Füge die Vorschau-Box hinzu, wenn sie noch nicht existiert
        if (!$('#ai-generated-preview-' + attachmentId).length) {
            var previewHtml = '<div id="ai-generated-preview-' + attachmentId + '" style="border: 2px solid #2271b1; padding: 10px; background: #f0f8ff; margin: 10px 0;">' +
                '<p style="color: #2271b1; font-weight: bold; margin-top: 0;">✨ KI-generierte Beschreibungen verfügbar!</p>' +
                '<div style="background: white; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px;">' +
                '<p><strong>Vorgeschlagene Bildunterschrift:</strong></p>' +
                '<textarea id="preview-caption-' + attachmentId + '" rows="2" style="width: 100%;">' + caption + '</textarea>' +
                '<p style="margin-top: 10px;"><strong>Vorgeschlagener Alt-Text:</strong></p>' +
                '<textarea id="preview-alt-' + attachmentId + '" rows="2" style="width: 100%;">' + altText + '</textarea>' +
                '</div>' +
                '<p>' +
                '<button type="button" class="button button-primary ai-accept-caption" data-attachment-id="' + attachmentId + '">Bildunterschrift übernehmen</button> ' +
                '<button type="button" class="button button-primary ai-accept-alt" data-attachment-id="' + attachmentId + '">Alt-Text übernehmen</button> ' +
                '<button type="button" class="button ai-accept-both" data-attachment-id="' + attachmentId + '">Beides übernehmen</button> ' +
                '<button type="button" class="button ai-reject-generated" data-attachment-id="' + attachmentId + '">Verwerfen</button>' +
                '</p>' +
                '<div id="generated-success-' + attachmentId + '" style="display:none; color: green; margin-top: 10px;"></div>' +
                '</div>';
            
            container.prepend(previewHtml);
        }
    }
});