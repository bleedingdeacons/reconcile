(function ($) {
    'use strict';

    $(function () {
        var $form     = $('#reconcile-import-form');
        var $submit   = $('#reconcile-submit');
        var $spinner  = $('#reconcile-spinner');
        var $results  = $('#reconcile-results');

        $form.on('submit', function (e) {
            e.preventDefault();

            var fileInput = document.getElementById('reconcile-file');
            if (!fileInput || !fileInput.files.length) {
                alert('Please select a file to import.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'reconcile_import');
            formData.append('reconcile_nonce', $form.find('[name="reconcile_nonce"]').val());
            formData.append('import_file', fileInput.files[0]);

            if ($('#reconcile-dry-run').is(':checked')) {
                formData.append('dry_run', '1');
            }

            // Disable submit, show spinner
            $submit.prop('disabled', true).text('Importing…');
            $spinner.addClass('is-active');
            $results.hide();

            $.ajax({
                url: reconcileAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function (response) {
                    renderResults(response.data, true);
                },
                error: function (xhr) {
                    var data = xhr.responseJSON ? xhr.responseJSON.data : null;
                    if (data && (data.errors || data.message)) {
                        renderResults(data, false);
                    } else {
                        var detail = 'HTTP ' + xhr.status;
                        if (xhr.responseText) {
                            var body = xhr.responseText.substring(0, 500);
                            detail += ' — Response: ' + body;
                        }
                        renderResults({
                            success: false,
                            summary: 'An unexpected error occurred.',
                            errors: [detail],
                            warnings: []
                        }, false);
                    }
                },
                complete: function () {
                    $submit.prop('disabled', false).text('Import');
                    $spinner.removeClass('is-active');
                }
            });
        });

        function renderResults(data, isSuccess) {
            var isDryRun = $('#reconcile-dry-run').is(':checked');
            var html = '';
            var cssClass = 'reconcile-results ';

            if (!isSuccess || (data.errors && data.errors.length)) {
                cssClass += 'error';
            } else if (isDryRun) {
                cssClass += 'dry-run';
            } else {
                cssClass += 'success';
            }

            // Title
            if (isDryRun && isSuccess) {
                html += '<h3>🔍 Dry Run Complete</h3>';
                html += '<p>No changes were made. Uncheck "Dry run" and import again to apply.</p>';
            } else if (isSuccess) {
                html += '<h3>✓ Import Complete</h3>';
            } else {
                html += '<h3>Import Failed</h3>';
            }

            // Summary
            if (data.summary) {
                html += '<div class="reconcile-summary">' + escHtml(data.summary) + '</div>';
            }

            // Stats
            if (typeof data.total_rows !== 'undefined') {
                html += '<div class="reconcile-stats">';
                html += stat(data.total_rows, 'Total Rows');
                html += stat(data.created || 0, isDryRun ? 'Would Create' : 'Created');
                html += stat(data.updated || 0, isDryRun ? 'Would Update' : 'Updated');
                html += stat(data.skipped || 0, 'Skipped');
                html += '</div>';
            }

            // Errors
            if (data.errors && data.errors.length) {
                html += '<ul class="reconcile-errors">';
                for (var i = 0; i < data.errors.length; i++) {
                    html += '<li>' + escHtml(data.errors[i]) + '</li>';
                }
                html += '</ul>';
            }

            // Single message fallback
            if (data.message && (!data.errors || !data.errors.length)) {
                html += '<p>' + escHtml(data.message) + '</p>';
            }

            // Skipped rows table
            if (data.skipped_rows && data.skipped_rows.length) {
                html += '<div class="reconcile-skipped">';
                html += '<h4>Skipped Rows (' + data.skipped_rows.length + ')</h4>';
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Row</th><th>Reason</th></tr></thead>';
                html += '<tbody>';
                for (var k = 0; k < data.skipped_rows.length; k++) {
                    var sr = data.skipped_rows[k];
                    var hasDetails = sr.details && Object.keys(sr.details).length > 0;
                    html += '<tr>';
                    html += '<td>' + sr.row + '</td>';
                    html += '<td>' + escHtml(sr.reason);
                    if (hasDetails) {
                        html += ' <a href="#" class="reconcile-toggle-details" data-index="' + k + '">Show details</a>';
                    }
                    html += '</td>';
                    html += '</tr>';
                    if (hasDetails) {
                        html += '<tr class="reconcile-details-row" id="reconcile-details-' + k + '" style="display:none;">';
                        html += '<td></td>';
                        html += '<td><dl class="reconcile-details-list">';
                        for (var key in sr.details) {
                            if (sr.details.hasOwnProperty(key)) {
                                html += '<dt>' + escHtml(key) + '</dt>';
                                html += '<dd>' + escHtml(sr.details[key]) + '</dd>';
                            }
                        }
                        html += '</dl></td>';
                        html += '</tr>';
                    }
                }
                html += '</tbody></table>';
                html += '</div>';
            }

            // Warnings
            if (data.warnings && data.warnings.length) {
                html += '<ul class="reconcile-warnings">';
                for (var j = 0; j < data.warnings.length; j++) {
                    html += '<li>' + escHtml(data.warnings[j]) + '</li>';
                }
                html += '</ul>';
            }

            $results.attr('class', cssClass).html(html).show();
        }

        // Delegated click handler for expanding/collapsing skipped row details
        $results.on('click', '.reconcile-toggle-details', function (e) {
            e.preventDefault();
            var idx = $(this).data('index');
            var $detailsRow = $('#reconcile-details-' + idx);
            if ($detailsRow.is(':visible')) {
                $detailsRow.hide();
                $(this).text('Show details');
            } else {
                $detailsRow.show();
                $(this).text('Hide details');
            }
        });

        function stat(value, label) {
            return '<div class="reconcile-stat">'
                + '<span class="reconcile-stat-value">' + value + '</span>'
                + '<span class="reconcile-stat-label">' + escHtml(label) + '</span>'
                + '</div>';
        }

        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    });
})(jQuery);
