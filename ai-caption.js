jQuery(document).ready(function($) {
    // Debug-Modus
    var debug = aiCaptionAjax.debug || false;
    
    function log(message) {
        if (debug && console && console.log) {
            console.log('AI Caption Generator: ' + message);
        }
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
    
    // Event-Handler für Speichern-Button
    $(document).on('click', '.ai-save-btn', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        var caption = $('#caption-' + attachmentId).val();
        var altText = $('#alt-' + attachmentId).val();
        var success = $('#success-' + attachmentId);
        var error = $('#error-' + attachmentId);
        var result = $('#result-' + attachmentId);
        
        log('Speichere für Attachment ID: ' + attachmentId);
        log('Caption: ' + caption);
        log('Alt-Text: ' + altText);
        
        // Button deaktivieren während des Speicherns
        $(this).prop('disabled', true).text('Wird gespeichert...');
        var saveButton = $(this);
        
        $.ajax({
            url: aiCaptionAjax.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'save_caption',
                attachment_id: attachmentId,
                caption: caption,
                alt_text: altText,
                nonce: aiCaptionAjax.nonce
            },
            success: function(response) {
                log('Speichern Antwort: ' + JSON.stringify(response));
                
                if (response.success) {
                    // Aktualisiere die Anzeige der aktuellen Werte
                    $('#current-caption-' + attachmentId).text(caption || 'Keine');
                    $('#current-alt-' + attachmentId).text(altText || 'Keiner');
                    
                    // Erfolg anzeigen
                    result.hide();
                    success.html('✓ Texte wurden erfolgreich gespeichert!').show();
                    
                    // Erfolg nach 3 Sekunden ausblenden
                    setTimeout(function() {
                        success.fadeOut();
                    }, 3000);
                    
                    // Falls wir in der Mediathek sind, versuche die Felder zu aktualisieren
                    if ($('#attachment-details-caption').length) {
                        $('#attachment-details-caption').val(caption);
                    }
                    if ($('#attachment-details-alt-text').length) {
                        $('#attachment-details-alt-text').val(altText);
                    }
                    
                } else {
                    error.html('Fehler beim Speichern: ' + response.data).show();
                    saveButton.prop('disabled', false).text('Übernehmen');
                }
            },
            error: function(xhr, status, errorThrown) {
                log('AJAX-Fehler beim Speichern: ' + status + ' - ' + errorThrown);
                error.html('Verbindungsfehler beim Speichern: ' + errorThrown).show();
                saveButton.prop('disabled', false).text('Übernehmen');
            },
            complete: function() {
                // Button wieder aktivieren
                saveButton.prop('disabled', false).text('Übernehmen');
            }
        });
    });
    
    // Event-Handler für Abbrechen-Button
    $(document).on('click', '.ai-cancel-btn', function(e) {
        e.preventDefault();
        
        var attachmentId = $(this).data('attachment-id');
        $('#result-' + attachmentId).hide();
        $('#error-' + attachmentId).hide();
        $('#success-' + attachmentId).hide();
    });
    
    log('AI Caption Generator JavaScript geladen');
});