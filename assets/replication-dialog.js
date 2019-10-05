/**
 * Created by johannes on 4.1.2017.
 */

var percent_completed = 0;
var paused = false;
var terminated = true;
var batch_size = 10;

jQuery(function () {
    jQuery('#replicate-all-existing').click(function () {

        if (!isNaN(parseInt(jQuery('#msm-select-replication-source').val())) && !isNaN(parseInt(jQuery('#msm-select-replication-target').val()))) {
            terminated = false;
            // Create overlay element
            var overlay = jQuery('<div class="msm-overlay"></div>');
            jQuery('body').append(overlay);

            var modal = jQuery('<div class="msm-modal" id="msm-modal"></div>');
            var progressbar = jQuery('<div id="msm-progress-bar" class="msm-progress-bar"></div>');
            var progress_indicator = jQuery('<div id="msm-progress-indicator" class="msm-progress-indicator"></div>');
            var percent_label = jQuery('<span id="percent-label" class="msm-percent-label"></span>');
            var progress_heading = jQuery('<h2></h2>');
            var progress_total = jQuery('<span id="msm-replication-total" class="msm-replication-detail total"></span>');
            var progress_remaining = jQuery('<span id="msm-replication-remaining" class="msm-replication-detail remaining"></span>');
            var pause_button = jQuery('<button id="msm-pause-replication" class="button button-secondary"></button>').text(msm_strings.pause_btn_label);
            var end_button = jQuery('<button id="msm-end-replication" class="button button-secondary"></button>').text(msm_strings.end_btn_label);

            progress_heading.html(msm_strings.progress_heading);
            modal.append(progress_heading);

            progress_total.text(msm_strings.progress_total);
            progress_remaining.text(msm_strings.progress_remaining);

            modal.append(progress_total);
            modal.append(progress_remaining);
            modal.append(progressbar);

            progressbar.append(progress_indicator);
            progressbar.append(percent_label);

            modal.append(pause_button);
            modal.append(end_button);
            overlay.append(modal);

            overlay.fadeIn('fast');
            modal.fadeIn('fast');

            pause_button.click(function () {
                pause_process();
            });

            end_button.click(function () {
                var answer = confirm(msm_strings.confirm_termination);

                if (answer) {
                    terminated = true;
                    jQuery('#msm-modal').fadeOut('fast').remove();
                    jQuery('.msm-overlay').fadeOut('fast').remove();
                } else {
                    return false;
                }

            });

            request_replication();
        }
    });
});

function pause_process() {
    paused = true;

    var resume_button = jQuery('<button id="msm-resume-replication" class="button button-secondary"></button>').text(msm_strings.resume_btn_label);
    jQuery('#msm-pause-replication').fadeOut('fast').replaceWith(resume_button);
    resume_button.fadeIn('fast');
    resume_button.click(function () {
        resume_process();
    });
}

function resume_process() {
    paused = false;

    var pause_button = jQuery('<button id="msm-pause-replication" class="button button-secondary"></button>').text(msm_strings.pause_btn_label);
    jQuery('#msm-resume-replication').fadeOut('fast').replaceWith(pause_button);
    pause_button.fadeIn('fast');

    pause_button.click(function () {
        pause_process();
    });

    request_replication();
}

/* Handle the ajax call to replicate media. Also repeat call if process not complete. */
function request_replication(set_batch_size) {

    if (set_batch_size) {
        batch_size = set_batch_size;
    }

    if (!paused && !terminated) {
        var data = {
            'action': 'msm_replicate_all_existing',
            'batch_size': batch_size,
            'source':jQuery('#msm-select-replication-source').val(),
            'target':jQuery('#msm-select-replication-target').val()
        };

        jQuery.ajax({
            type: "POST",
            url: ajaxurl,
            data: data,
            success: function (response) {
                // handle response
                var new_percent_completed = update_replication_percentage(response);

                if (new_percent_completed < 100) {
                    request_replication();
                } else {

                    window.setTimeout(function () {
                        jQuery('#msm-pause-replication').fadeOut('fast').remove();
                        jQuery('#msm-end-replication').fadeOut('fast').remove();

                        var success_msg = jQuery('<div id="msm-replication-success" class="msm-admin-notice"></div>');
                        success_msg.text(msm_strings.replication_success_msg);
                        jQuery('#msm-modal').append(success_msg);
                        success_msg.fadeIn('fast');

                        var close_button = jQuery('<button id="msm-close-finished" class="button button-primary"></button>').text(msm_strings.close_btn_label);
                        success_msg.append(close_button);

                        close_button.click(function () {
                            jQuery('#msm-modal').fadeOut('fast').remove();
                            jQuery('.msm-overlay').fadeOut('fast').remove();
                        });
                    }, 2000);
                    percent_completed = 0;
                }
            },
            error: function (jqXHR, status, error) {
                var msg = msm_strings.replication_general_error_msg;

                if( 'Illegal source-target combination.' === jqXHR.responseText ){
                    msg = msm_strings.replication_illegal_pair_err_msg;
                }

                var reduced_batch_size = batch_size - 2; // try in smaller batches to avoid timeouts
                if (reduced_batch_size > 0) {
                    request_replication(reduced_batch_size);
                } else {
                    jQuery('#msm-pause-replication').fadeOut('fast').remove();
                    jQuery('#msm-end-replication').fadeOut('fast').remove();

                    var error_msg = jQuery('<div id="msm-replication-error" class="msm-admin-notice"></div>');
                    error_msg.text(msg);
                    jQuery('#msm-modal').append(error_msg);
                    error_msg.fadeIn('fast');

                    var close_button = jQuery('<button id="msm-close-finished" class="button button-primary"></button>').text(msm_strings.close_btn_label);
                    error_msg.append(close_button);

                    close_button.click(function () {
                        jQuery('#msm-modal').fadeOut('fast').remove();
                        jQuery('.msm-overlay').fadeOut('fast').remove();
                    });
                }
            },
            dataType: 'json'
        });

    }
}

/* Update variables and progress bar based on ajax response */
function update_replication_percentage(response) {
    var new_percent_completed;

    if( response.total !== response.not_replicated ){
        new_percent_completed = (response.total - response.not_replicated) / response.total * 100;
    } else {
        new_percent_completed = 100;
    }

    if (percent_completed === new_percent_completed && percent_completed < 100) {
        // If there is no progress, something is wrong.
        alert(msm_strings.replication_stagnated_err_msg);
    }

    jQuery('#percent-label').text(Math.round(new_percent_completed).toString() + " %");
    var distance_from_right = 100 - new_percent_completed;
    jQuery('#msm-progress-indicator').css('right', distance_from_right.toString() + '%');

    jQuery('#msm-replication-total').text(msm_strings.replication_total_label + ' ' + response.total.toString() + ' ' + msm_strings.replication_files_label);
    jQuery('#msm-replication-remaining').text(msm_strings.replication_remaining_label + ' ' + response.not_replicated.toString() + ' ' + msm_strings.replication_files_label);

    percent_completed = new_percent_completed;
    return percent_completed;
}