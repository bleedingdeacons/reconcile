(function ($) {
    'use strict';

    $(function () {
        var $form     = $('#reconcile-position-import-form');
        var $submit   = $('#reconcile-position-submit');
        var $spinner  = $('#reconcile-position-spinner');
        var $results  = $('#reconcile-position-results');

        $form.on('submit', function (e) {
            e.preventDefault();

            var fileInput = document.getElementById('reconcile-position-file');
            if (!fileInput || !fileInput.files.length) {
                alert('Please select a file to import.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'reconcile_position_import');
            formData.append('reconcile_position_nonce', $form.find('[name="reconcile_position_nonce"]').val());
            formData.append('import_file', fileInput.files[0]);

            if ($('#reconcile-position-dry-run').is(':checked')) {
                formData.append('dry_run', '1');
            }

            // Disable submit, show spinner
            $submit.prop('disabled', true).text('Importing…');
            $spinner.addClass('is-active');
            $results.hide();

            $.ajax({
                url: reconcilePositionAdmin.ajaxUrl,
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
                        var message;
                        if (xhr.status === 400 && xhr.responseText === '0') {
                            message = 'The import handler is not available. '
                                + 'Please check that the Unity plugin is active and fully configured, '
                                + 'then reload this page and try again.';
                        } else if (xhr.status === 0) {
                            message = 'Could not reach the server. Please check your connection and try again.';
                        } else if (xhr.status >= 500) {
                            message = 'A server error occurred. Please check the PHP error log for details.';
                        } else {
                            message = 'The server returned an unexpected response. '
                                + 'Please check the PHP error log for details.';
                        }
                        renderResults({
                            success: false,
                            summary: 'Import Failed',
                            errors: [message],
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
            var isDryRun = $('#reconcile-position-dry-run').is(':checked');
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
                    html += '<td>' + escHtml(String(sr.row)) + '</td>';
                    html += '<td>' + escHtml(sr.reason);
                    if (hasDetails) {
                        html += ' <a href="#" class="reconcile-toggle-details" data-index="' + k + '">Show details</a>';
                    }
                    html += '</td>';
                    html += '</tr>';
                    if (hasDetails) {
                        html += '<tr class="reconcile-details-row" id="reconcile-position-details-' + k + '" style="display:none;">';
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
            var $detailsRow = $('#reconcile-position-details-' + idx);
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
                + '<span class="reconcile-stat-value">' + escHtml(String(value)) + '</span>'
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