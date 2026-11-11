<?php
/** @var User $user */
$page = 'protocols-index';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Protocols - <span class="text-muted">Migrate Participants</span></h1>
        <a class="btn btn-secondary" href="<?= $rootURL ?>/protocols"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="row mb-3">
        <div class="col-md-8">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <select class="selectpicker" data-width="fit" id="migrate-protocol" data-none-selected-text="Select Protocol" data-live-search="true" data-live-search-placeholder="Search"></select>
                <select class="selectpicker" data-width="fit" id="migrate-target" data-none-selected-text="Select Target Version"></select>
            </div>
        </div>
        <div class="col-md-4 text-end">
            <button type="button" class="btn btn-success" onclick="migrate_selected();"><i class="fas fa-people-arrows"></i> Migrate Selected</button>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <table id="enrollments" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-enrollments"></th>
                    <th>Participant</th>
                    <th>Current Version</th>
                    <th>Current State</th>
                    <th>Enrolled At</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th></th>
                    <th>Participant</th>
                    <th>Current Version</th>
                    <th>Current State</th>
                    <th>Enrolled At</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="migrateConfirmModal" tabindex="-1" role="dialog" aria-labelledby="migrateConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="migrateConfirmModalLabel">Migrate Participants</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to migrate <strong id="migrate-count"></strong> enrollment(s) to <strong id="migrate-target-label"></strong>.</p>
                    <p class="text-danger">Each current enrollment will be ended and a new enrollment pinned to the target version will be created.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirm_migrate();">Migrate</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var enrollmentsTable = $('#enrollments');
        var enrollmentsDataTable = null;
        var migrateConfirmModal = $('#migrateConfirmModal');
        var preselectedName = <?= json_encode($_GET['name'] ?? '') ?>;

        function escAttr(value) {
            return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        $(function() {
            enrollmentsDataTable = enrollmentsTable.DataTable({
                data: [],
                order: [[ 1, "asc" ]],
                responsive: true,
                buttons: [
                    'pageLength','colvis'
                ],
                layout: {
                    topStart: 'buttons',
                },
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    {
                        orderable: false,
                        targets: [0]
                    }
                ],
                language: {
                    emptyTable: "Select a protocol to view its active enrollments."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'enrollment_uuid',
                        render: function (data) {
                            return `<input type='checkbox' class='migrate-check' value='${escAttr(data)}'>`;
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            return escAttr((data.first_name || '') + ' ' + (data.last_name || ''));
                        }
                    },
                    {
                        data: 'version',
                        render: function (data) {
                            return 'v' + data;
                        }
                    },
                    {
                        data: 'current_state',
                        render: function (data) {
                            return data === null ? '<span class="text-muted">Unknown</span>' : escAttr(data);
                        }
                    },
                    {
                        data: 'enrolled_at',
                        render: function (data) {
                            return escAttr(formatUtcTimestamp(data));
                        }
                    }
                ]
            });

            $('#select-all-enrollments').on('change', function() {
                var checked = $(this).is(':checked');
                $(enrollmentsDataTable.rows().nodes()).find('.migrate-check').prop('checked', checked);
            });

            $.ajax({
                url: '<?= $rootURL ?>/protocols/list',
                type: 'GET',
                dataType: 'json'
            }).done(function(data) {
                data.data.forEach(function(protocol) {
                    if (protocol.latest_published_uuid) {
                        $('#migrate-protocol').append("<option value='" + escAttr(protocol.name) + "'>" + escAttr(protocol.name) + "</option>");
                    }
                });
                if (preselectedName !== '') {
                    $('#migrate-protocol').val(preselectedName);
                }
                $('#migrate-protocol').selectpicker('refresh');
                if ($('#migrate-protocol').val()) {
                    load_protocol($('#migrate-protocol').val());
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });

            $('#migrate-protocol').on('changed.bs.select', function() {
                load_protocol($(this).val());
            });
        });

        function load_protocol(name) {
            if (!name) {
                return;
            }
            $.ajax({
                url: '<?= $rootURL ?>/protocols/versions',
                type: 'GET',
                data: {'name': name},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    $('#migrate-target').empty();
                    data.versions.forEach(function(v) {
                        if (v.status === 'published') {
                            $('#migrate-target').append("<option value='" + escAttr(v.definition_uuid) + "'>" + escAttr(v.name) + " v" + v.version + "</option>");
                        }
                    });
                    $('#migrate-target').selectpicker('refresh');
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
            $.ajax({
                url: '<?= $rootURL ?>/protocols/migrate-list',
                type: 'GET',
                data: {'name': name},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    $('#select-all-enrollments').prop('checked', false);
                    enrollmentsDataTable.clear();
                    enrollmentsDataTable.rows.add(data.enrollments);
                    enrollmentsDataTable.draw();
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function selected_enrollments() {
            var selected = [];
            $(enrollmentsDataTable.rows().nodes()).find('.migrate-check:checked').each(function() {
                selected.push($(this).val());
            });
            return selected;
        }

        function migrate_selected() {
            var selected = selected_enrollments();
            if (selected.length === 0) {
                showError('Please select at least one enrollment to migrate.');
                return;
            }
            var target = $('#migrate-target').val();
            if (!target) {
                showError('Please select a target version.');
                return;
            }
            $('#migrate-count').text(selected.length);
            $('#migrate-target-label').text($('#migrate-target').find('option:selected').text());
            migrateConfirmModal.modal('show');
        }

        function confirm_migrate() {
            var selected = selected_enrollments();
            var target = $('#migrate-target').val();
            $.post({
                url: '<?= $rootURL ?>/protocols/migrate-enrollments',
                data: {'enrollment_uuids': selected, 'target_definition_uuid': target},
                dataType: 'json'
            }).done(function(data) {
                migrateConfirmModal.modal('hide');
                if (data.success) {
                    showSuccess('Successfully migrated ' + data.migrated + ' enrollment(s).');
                } else {
                    showError(data.error_message);
                }
                load_protocol($('#migrate-protocol').val());
            }).fail(function() {
                migrateConfirmModal.modal('hide');
                showError('Error communicating with the server.');
            });
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
