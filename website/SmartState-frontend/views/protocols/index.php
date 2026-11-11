<?php
/** @var User $user */
$page = 'protocols-index';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Protocols</h1>
        <div>
            <button type="button" class="btn btn-outline-success me-1" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-import"></i> Import Umple
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newProtocolModal">
                <i class="fas fa-plus"></i> New Protocol
            </button>
        </div>
    </div>
    <div class="row">
        <div class="col">
            <table id="protocols" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Latest Published</th>
                    <th>Published At</th>
                    <th>Draft</th>
                    <th>Active Enrollments</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>Name</th>
                    <th>Latest Published</th>
                    <th>Published At</th>
                    <th>Draft</th>
                    <th>Active Enrollments</th>
                    <th>Actions</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="newProtocolModal" tabindex="-1" role="dialog" aria-labelledby="newProtocolModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newProtocolModalLabel">New Protocol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <span class="input-group-text" id="new-protocol-name-label">Name</span>
                        <input id="new-protocol-name" type="text" class="form-control" placeholder="Protocol Name" aria-label="Name" aria-describedby="new-protocol-name-label" autofocus>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="create_protocol();">Create Draft</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="versionsModal" tabindex="-1" role="dialog" aria-labelledby="versionsModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="versionsModalLabel">Versions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="list-group" id="versions-list"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete Protocol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="delete-protocol-name" value="" />
                    <p>You are about to delete <strong id="delete-label"></strong> (<span id="delete-version-count"></span>).</p>
                    <p class="text-danger">This permanently deletes the protocol and every one of its versions, drafts included. This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="delete-confirm-btn" onclick="confirm_delete();">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Import Umple Protocol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Upload a plain-text Umple state machine (any extension — .txt, .ump, .uml, ...). Its states, transitions, and after(...) timers become a draft you can edit and publish here.</p>
                    <div class="mb-3">
                        <label for="import-file" class="form-label">State machine file</label>
                        <input id="import-file" type="file" class="form-control">
                    </div>
                    <div class="input-group mb-3">
                        <span class="input-group-text" id="import-protocol-name-label">Name</span>
                        <input id="import-protocol-name" type="text" class="form-control" placeholder="Protocol Name" aria-label="Name" aria-describedby="import-protocol-name-label">
                    </div>
                    <div id="import-errors" class="alert alert-danger d-none mb-0">
                        <strong>Import failed:</strong>
                        <ul class="mb-0" id="import-errors-list"></ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="import-submit" onclick="import_protocol();">Import as Draft</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var protocolsTable = $('#protocols');
        var protocolsDataTable = null;
        var protocols = {};
        var newProtocolModal = $('#newProtocolModal');
        var versionsModal = $('#versionsModal');
        var deleteModal = $('#deleteModal');
        var importModal = $('#importModal');

        function escAttr(value) {
            return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        $(function() {
            protocolsDataTable = protocolsTable.DataTable({
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/protocols/list",
                    dataSrc: 'data'
                },
                order: [[ 0, "asc" ]],
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
                        targets: [5]
                    }
                ],
                language: {
                    emptyTable: "No protocols have been created."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'name',
                        render: $.fn.dataTable.render.text()
                    },
                    {
                        data: 'latest_published_version',
                        render: function (data) {
                            return data === null ? '<span class="text-muted">None</span>' : 'v' + data;
                        }
                    },
                    {
                        data: 'latest_published_at',
                        render: function (data) {
                            return data === null ? '<span class="text-muted">-</span>' : escAttr(formatUtcTimestamp(data));
                        }
                    },
                    {
                        data: 'has_draft',
                        render: function (data) {
                            return data ? '<span class="badge bg-warning text-dark">Draft</span>' : '<span class="text-muted">-</span>';
                        }
                    },
                    {
                        data: 'active_enrollments'
                    },
                    {
                        data: null,
                        render: function (data) {
                            var buttons = '';
                            if (data.has_draft) {
                                buttons += `<a class='btn btn-primary btn-xs me-1' href='<?= $rootURL ?>/protocols/edit?definition=${data.draft_uuid}'>
                                                <span class='fas fa-edit' data-toggle='tooltip' data-placement='left' title='Edit Draft'></span>
                                            </a>`;
                            } else if (data.latest_published_uuid) {
                                buttons += `<button class='btn btn-primary btn-xs me-1' onclick='new_draft("${data.latest_published_uuid}");'>
                                                <span class='fas fa-code-branch' data-toggle='tooltip' data-placement='left' title='New Draft'></span>
                                            </button>`;
                            }
                            if (data.latest_published_uuid) {
                                buttons += `<a class='btn btn-info btn-xs me-1' href='<?= $rootURL ?>/protocols/view?definition=${data.latest_published_uuid}'>
                                                <span class='fas fa-eye' data-toggle='tooltip' data-placement='left' title='View'></span>
                                            </a>`;
                            }
                            buttons += `<button class='btn btn-secondary btn-xs me-1 btn-show-versions' data-name="${escAttr(data.name)}">
                                            <span class='fas fa-layer-group' data-toggle='tooltip' data-placement='left' title='Versions'></span>
                                        </button>`;
                            if (data.latest_published_uuid) {
                                buttons += `<a class='btn btn-warning btn-xs me-1' href='<?= $rootURL ?>/protocols/migrate?name=${encodeURIComponent(data.name)}'>
                                                <span class='fas fa-people-arrows' data-toggle='tooltip' data-placement='left' title='Migrate Participants'></span>
                                            </a>`;
                            }
                            buttons += `<button class='btn btn-danger btn-xs me-1 btn-delete-protocol' data-name="${escAttr(data.name)}">
                                            <span class='fas fa-trash' data-toggle='tooltip' data-placement='left' title='Delete'></span>
                                        </button>`;
                            return buttons;
                        }
                    }
                ]
            });
            protocolsTable.on('xhr.dt', function (e, settings, data) {
                protocols = {};
                $.each(data.data, function(i, v) {
                    protocols[v.name] = v;
                });
            });
            protocolsTable.on('click', '.btn-show-versions', function() {
                show_versions($(this).attr('data-name'));
            });
            protocolsTable.on('click', '.btn-delete-protocol', function() {
                delete_protocol($(this).attr('data-name'));
            });
        });

        newProtocolModal.on('hidden.bs.modal', function() {
            $('#new-protocol-name').val('');
        });

        function create_protocol() {
            let name = $('#new-protocol-name').val().trim();
            if (name.length === 0) {
                showError('Please enter a name for the protocol.');
                return;
            }
            $.post({
                url: '<?= $rootURL ?>/protocols/create',
                data: {'name': name},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    window.location.href = '<?= $rootURL ?>/protocols/edit?definition=' + data.definition_uuid;
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function new_draft(definition_uuid) {
            $.post({
                url: '<?= $rootURL ?>/protocols/clone',
                data: {'definition_uuid': definition_uuid},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    window.location.href = '<?= $rootURL ?>/protocols/edit?definition=' + data.definition_uuid;
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function status_badge(status) {
            if (status === 'published') return '<span class="badge bg-success">Published</span>';
            if (status === 'draft') return '<span class="badge bg-warning text-dark">Draft</span>';
            return '<span class="badge bg-secondary">Archived</span>';
        }

        function show_versions(name) {
            $('#versionsModalLabel').text('Versions - ' + name);
            $('#versions-list').empty();
            $.ajax({
                url: '<?= $rootURL ?>/protocols/versions',
                type: 'GET',
                data: {'name': name},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    data.versions.forEach(function(v) {
                        let links = '';
                        if (v.status === 'draft') {
                            links = `<a class='btn btn-primary btn-xs' href='<?= $rootURL ?>/protocols/edit?definition=${v.definition_uuid}'>Edit</a>`;
                        } else {
                            links = `<a class='btn btn-info btn-xs' href='<?= $rootURL ?>/protocols/view?definition=${v.definition_uuid}'>View</a>`;
                        }
                        let published = v.published_at ? `<small class='text-muted ms-2'>${escAttr(formatUtcTimestamp(v.published_at))}</small>` : '';
                        $('#versions-list').append(
                            `<li class='list-group-item d-flex justify-content-between align-items-center'>
                                <span>v${v.version} ${status_badge(v.status)}${published}</span>
                                <span>${links}</span>
                            </li>`
                        );
                    });
                    versionsModal.modal('show');
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function delete_protocol(name) {
            let protocol = protocols[name];
            if (!protocol) {
                showError('Protocol not found.');
                return;
            }
            if (protocol.active_enrollments > 0) {
                showError('This protocol has active enrollments. Migrate or end them before deleting it.');
                return;
            }
            $('#delete-protocol-name').val(name);
            $('#delete-label').text(name);
            $('#delete-version-count').text(protocol.version_count === 1 ? '1 version' : protocol.version_count + ' versions');
            deleteModal.modal('show');
        }

        function confirm_delete() {
            let name = $('#delete-protocol-name').val();
            $('#delete-confirm-btn').prop('disabled', true);
            $.post({
                url: '<?= $rootURL ?>/protocols/delete',
                data: {'name': name},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    showSuccess('Protocol deleted.');
                    protocolsDataTable.ajax.reload(null, false);
                    deleteModal.modal('hide');
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            }).always(function() {
                $('#delete-confirm-btn').prop('disabled', false);
            });
        }

        $('#import-file').on('change', function() {
            let nameInput = $('#import-protocol-name');
            if (this.files.length > 0 && nameInput.val().trim() === '') {
                nameInput.val(this.files[0].name.replace(/\.[^.]+$/, ''));
            }
        });

        var importXhr = null;

        importModal.on('hidden.bs.modal', function() {
            if (importXhr) {
                importXhr.abort();
                importXhr = null;
            }
            $('#import-file').val('');
            $('#import-protocol-name').val('');
            $('#import-submit').prop('disabled', false);
            $('#import-errors').addClass('d-none');
            $('#import-errors-list').empty();
        });

        function import_protocol() {
            let fileInput = $('#import-file')[0];
            let name = $('#import-protocol-name').val().trim();
            if (fileInput.files.length === 0) {
                showError('Please choose a file to import.');
                return;
            }
            if (name.length === 0) {
                showError('Please enter a name for the protocol.');
                return;
            }
            let formData = new FormData();
            formData.append('file', fileInput.files[0]);
            formData.append('name', name);
            $('#import-submit').prop('disabled', true);
            $('#import-errors').addClass('d-none');
            $('#import-errors-list').empty();
            importXhr = $.ajax({
                url: '<?= $rootURL ?>/protocols/import',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    if (data.warnings && data.warnings.length > 0) {
                        sessionStorage.setItem('protocol-import-warnings', JSON.stringify(data.warnings));
                    }
                    window.location.href = '<?= $rootURL ?>/protocols/edit?definition=' + data.definition_uuid;
                } else {
                    $('#import-submit').prop('disabled', false);
                    [data.error_message].concat(data.errors || []).forEach(function(e) {
                        $('<li>').text(e).appendTo('#import-errors-list');
                    });
                    $('#import-errors').removeClass('d-none');
                }
            }).fail(function(jqXHR, textStatus) {
                if (textStatus === 'abort')
                    return;
                $('#import-submit').prop('disabled', false);
                showError('Error communicating with the server.');
            }).always(function() {
                importXhr = null;
            });
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
