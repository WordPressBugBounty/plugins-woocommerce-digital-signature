/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */



(function ($) {

    /**
     * Insert content into the document editor, handling both Visual and Code/Text modes.
     *
     * When the editor is in Code/Text mode tinymce.get() returns null, which
     * causes a fatal JS error. This helper falls back to direct textarea insertion
     * so shortcodes are always placed at the cursor position regardless of mode.
     *
     * @since  2.0.3
     *
     * @param  {string} content  Shortcode or HTML string to insert.
     * @return {void}
     */
    function esigInsertContent( content ) {
        var editor = ( typeof tinymce !== 'undefined' ) ? tinymce.get( 'document_content' ) : null;

        if ( editor && ! editor.isHidden() ) {
            // Visual (WYSIWYG) mode — use the TinyMCE API.
            editor.insertContent( content );
            return;
        }

        // Code/Text mode — write directly into the visible textarea.
        var textarea = document.getElementById( 'document_content' );
        if ( ! textarea ) {
            return;
        }

        var start = textarea.selectionStart || 0;
        var end   = textarea.selectionEnd   || start;
        var text  = textarea.value          || '';

        textarea.value = text.substring( 0, start ) + content + text.substring( end );
        textarea.selectionStart = textarea.selectionEnd = start + content.length;
        textarea.focus();

        // Notify WordPress auto-save and other listeners that the content changed.
        textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
    }

    // next step click from sif pop

    // gravity add to document button clicked 
    $("#esig-insert-woo-tag").click(function () {

        //var form_id= $('input[name="esig_gf_form_id"]').val() ;

        var tagValue = $('select[name="esig-woocommerce-tag"]').val();
        if (tagValue === "sddelect") {
            alert("Please select a tag");
            return;
        }
       
        var return_text ;
        // 
        if(tagValue === "woo-shortcode"){
            return_text = '[esig-woo-order-details]';
        }
        else {
            return_text = '{{' + tagValue + '}}';
        }
         esigInsertContent( return_text );

        tb_remove();
    });


    $('#select-woo-form-list').click(function () {

        $(".chosen-drop").show(0, function () {
            $(this).parents("div").css("overflow", "visible");
        });

    });

    var default_value = $("#esign_woo_logic").val();
    
    if (default_value == "after_checkout") {
        $("#esign_woo_after_checkout_logic").show();
    }

    $("#esign_woo_logic").change(function () {
        var this_value = $(this).val();
        if (this_value == "after_checkout") {
            $("#esign_woo_after_checkout_logic").show();
        }
        if (this_value == "before_checkout") {
            $("#esign_woo_after_checkout_logic").hide();
        }
    });

    /* $('#esign_woo_sign_logic').change(function () {
     
     var selected = $(this).val();
     if (selected == "after_checkout") {
     $("#esig-agreement-required-box").hide();
     } else {
     $("#esig-agreement-required-box").show();
     }
     });*/

    /* $('#esign_woo_logic').change(function () {
     
     var selected = $(this).val();
     if (selected == "after_checkout") {
     
     $("#esig_agreement_required").attr("checked", false);
     $("#esig_agreement_required").attr("disabled", true);
     //$('#esig_agreement_required').attr('readonly', true);
     } else {
     $("#esig_agreement_required").removeAttr("disabled");
     }
     });*/

    $('#esig-woo-unsigned-agreement-send').click(function (e) {
        e.preventDefault();

        var $btn = $( '#esig-woo-unsigned-agreement-send' );

        if ( $btn.prop( 'disabled' ) ) {
            return false;
        }

        var orderId  = $( '#esig_woo_order_id' ).val();
        var origText = $btn.text();

        // Disable immediately to block rapid double-clicks that would create
        // duplicate document copies before the guard meta is persisted.
        $btn.prop( 'disabled', true ).text( 'Sending…' );

        // Use the WordPress global ajaxurl rather than wc_enhanced_select_params
        // which may not be defined on all admin screens (e.g. HPOS order pages).
        $.post(
            ajaxurl,
            {
                action:          'esig_create_order_agreement',
                esig_woo_order:  orderId,
                esig_woo_nonce:  esig_woo_params.esig_woo_order_nonce
            }
        ).done(function ( response ) {
            if ( response && response.success ) {
                // Reload so the meta box reflects the new "Resend" state.
                window.location.reload();
            } else {
                alert( 'Could not send the agreement. Please try again.' );
                $btn.prop( 'disabled', false ).text( origText );
            }
        }).fail(function () {
            alert( 'Request failed. Please try again.' );
            $btn.prop( 'disabled', false ).text( origText );
        });
    });


      // display  gravity form option popup
        $("#wpesign__woocommerce-sif-popup").on("click", function(e) {

                e.preventDefault();
               
                tb_show( "+ Insert Woo Details", "#TB_inline?inlineId=esig-woocommerce-option", false );
                

        });


})(jQuery);
