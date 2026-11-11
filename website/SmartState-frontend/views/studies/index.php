<?php
/** @var User $user */
$page = 'studies';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Studies</h1>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#studyModal">
            <i class="fas fa-plus"></i> Add Study
        </button>
    </div>
    <div class="row">
        <div class="col">
            <table id="studies" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact Phone</th>
                    <th>Contact Email</th>
                    <th>Twilio From Number</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>Name</th>
                    <th>Contact Phone</th>
                    <th>Contact Email</th>
                    <th>Twilio From Number</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="studyModal" tabindex="-1" role="dialog" aria-labelledby="studyModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="studyModalLabel">Add Study</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="study-uuid" value="" />
                    <div class="row">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="study-name-label">Name</span>
                                <input id="study-name" type="text" class="form-control" placeholder="Study Name" aria-label="Name" aria-describedby="study-name-label">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="study-contact-phone-label">Contact Phone</span>
                                <input id="study-contact-phone" type="text" class="form-control" placeholder="+15555555555" aria-label="Contact Phone" aria-describedby="study-contact-phone-label">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="study-contact-email-label">Contact Email</span>
                                <input id="study-contact-email" type="text" class="form-control" placeholder="abc123@uky.edu" aria-label="Contact Email" aria-describedby="study-contact-email-label">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="study-twilio-label">Twilio From Number</span>
                                <input id="study-twilio" type="text" class="form-control" placeholder="+15555555555" aria-label="Twilio From Number" aria-describedby="study-twilio-label">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button id="study-submit-button" type="button" class="btn btn-primary" onclick="submit_study();">Submit Study</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteStudyModal" tabindex="-1" role="dialog" aria-labelledby="deleteStudyModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteStudyModalLabel">Delete Study</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="delete-study-uuid" value="" />
                    <p>You are about to delete the study <strong id="delete-study-name"></strong>.</p>
                    <p class="text-danger fw-bold">This permanently deletes ALL data for this study: participants, enrollments, messages, protocols, surveys, and metrics. This action is not reversible.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirm_delete_study();">Delete Study</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var studiesTable = $('#studies');
        var studiesDataTable = null;
        var studies = {};
        var studyModal = $('#studyModal');
        var deleteStudyModal = $('#deleteStudyModal');

        $(function() {
            studiesDataTable = studiesTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/studies/list"
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
                        targets: [3,5]
                    }
                ],
                language: {
                    emptyTable: "No studies have been added."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'name'
                    },
                    {
                        data: 'contact_phone'
                    },
                    {
                        data: 'contact_email'
                    },
                    {
                        data: 'twilio_from_number'
                    },
                    {
                        data: 'created_at',
                        render: function (data) {
                            return formatUtcTimestamp(data);
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            return `<button class='btn btn-primary btn-xs me-1' onclick='edit_study("${data.study_uuid}");'>
                                        <span class='fas fa-edit' data-toggle='tooltip' data-placement='left' title='Edit Study'></span>
                                    </button>
                                    <button class='btn btn-danger btn-xs me-1' onclick='delete_study("${data.study_uuid}");'>
                                        <span class='fas fa-trash' data-toggle='tooltip' data-placement='left' title='Delete Study'></span>
                                    </button>`;
                        }
                    }
                ]
            });
            studiesTable.on('xhr.dt', function (e, settings, data) {
                studies = {};
                $.each(data.data, function(i, v) {
                    studies[v.study_uuid] = v;
                });
            });
        });

        function clear_study_form() {
            $('#study-uuid').val('');
            $('#study-name').val('');
            $('#study-contact-phone').val('');
            $('#study-contact-email').val('');
            $('#study-twilio').val('');
            $('#studyModalLabel').text('Add Study');
            $('#study-submit-button').text('Submit Study');
        }

        studyModal.on('hidden.bs.modal', function() {
            clear_study_form();
        });

        function edit_study(study_uuid) {
            let study = studies[study_uuid];
            if (!study) {
                showError('Study not found.');
                return;
            }
            $('#study-uuid').val(study.study_uuid);
            $('#study-name').val(study.name);
            $('#study-contact-phone').val(study.contact_phone);
            $('#study-contact-email').val(study.contact_email);
            $('#study-twilio').val(study.twilio_from_number);
            $('#studyModalLabel').text('Edit Study');
            $('#study-submit-button').text('Update Study');
            studyModal.modal('show');
        }

        function submit_study() {
            let studyUuid = $('#study-uuid').val();
            let name = $('#study-name').val().trim();
            if (name.length === 0) {
                showError('Please enter a name for the study.');
                return;
            }
            let formData = {
                'name': name,
                'contact_phone': $('#study-contact-phone').val(),
                'contact_email': $('#study-contact-email').val(),
                'twilio_from_number': $('#study-twilio').val()
            };
            let url = '<?= $rootURL ?>/studies/create';
            if (studyUuid !== '') {
                url = '<?= $rootURL ?>/studies/update';
                formData['study_uuid'] = studyUuid;
            }
            $.ajax({
                url: url,
                type: 'POST',
                data: formData,
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    showSuccess(studyUuid === '' ? 'Successfully created study.' : 'Successfully updated study.');
                    studiesDataTable.ajax.reload();
                    studyModal.modal('hide');
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function delete_study(study_uuid) {
            let study = studies[study_uuid];
            if (!study) {
                showError('Study not found.');
                return;
            }
            $('#delete-study-uuid').val(study.study_uuid);
            $('#delete-study-name').text(study.name);
            deleteStudyModal.modal('show');
        }

        function confirm_delete_study() {
            let studyUuid = $('#delete-study-uuid').val();
            $.post({
                url: '<?= $rootURL ?>/studies/delete',
                data: {'study_uuid': studyUuid},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    showSuccess('Successfully deleted study.');
                    studiesDataTable.ajax.reload(null, false);
                    deleteStudyModal.modal('hide');
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
