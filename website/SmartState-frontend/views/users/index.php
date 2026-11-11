<?php
/** @var User $user */
$page = "users";
global $rootURL;
require_once MODELS_DIR . 'Study.php';
$studyOptions = [];
if ($user->isSuperAdmin()) {
    $studyOptions = Study::allForSelect();
} else {
    foreach ($user->getMemberships() as $membership) {
        if ($membership['study_uuid'] === $user->getCurrentStudy()) {
            $studyOptions[] = ['study_uuid' => $membership['study_uuid'], 'name' => $membership['study_name']];
        }
    }
}
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Users</h1>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userModal">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>
    <div class="row">
        <div class="col">
            <table id="users" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Vanity Email</th>
                    <th>Phone Number</th>
                    <th>Organization</th>
                    <th>Affiliation</th>
                    <th>IDP</th>
                    <th>Memberships</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Vanity Email</th>
                    <th>Phone Number</th>
                    <th>Organization</th>
                    <th>Affiliation</th>
                    <th>IDP</th>
                    <th>Memberships</th>
                    <th>Actions</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">User Management</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <input type="hidden" id="user-id" value="" />
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="user-email-label">Email</span>
                                <input id="user-email" type="text" class="form-control" placeholder="abc123@uky.edu" aria-label="Email" aria-describedby="user-email">
                            </div>
                        </div>
                    </div>
                    <?php if ($user->isSuperAdmin()) : ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="user-super-admin">
                                <label class="form-check-label" for="user-super-admin">Super Admin</label>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Study Memberships</h6>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="add_membership_row();">
                            <i class="fas fa-plus"></i> Add Membership
                        </button>
                    </div>
                    <div id="membership-rows"></div>
                    <div class="study-admin-extras" style="display:none">
                        <hr>
                        <h6>Please enter your phone number and timezone to receive alerts about participants.</h6>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="input-group mb-3">
                                    <span class="input-group-text" id="user-number-label">Phone Number</span>
                                    <input id="user-number" type="text" class="form-control" placeholder="5555555555" aria-label="Phone Number" aria-describedby="user-number">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-floating">
                                <select class="selectpicker form-control" title="Select Location" id="user-location" onchange="fillTimeZones()" data-live-search="true"></select>
                            </div>
                            <div class="col-md-6 form-floating">
                                <select class="selectpicker form-control" title="Select Time Zone" id="user-timezone" data-live-search="true"></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submit_user();">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var users_table = $('#users');
        var users_datatable = null;
        var users = {};
        var userModal = $('#userModal');
        var roles = {};
        var studyOptions = <?= json_encode(array_values($studyOptions)) ?>;
        var loggedInIsSuperAdmin = <?= $user->isSuperAdmin() ? 'true' : 'false' ?>;

        $(function() {
            users_datatable = users_table.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/users/list"
                },
                order: [[ 3, "asc" ]],
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
                        targets: [10]
                    },
                    {
                        visible: false,
                        targets: [0,1,2,5,8,9]
                    }
                ],
                language: {
                    emptyTable: "No users have been added."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'id'
                    },
                    {
                        data: 'firstname'
                    },
                    {
                        data: 'lastname'
                    },
                    {
                        data: null,
                        render: function (data) {
                            if (data.id.startsWith("notloggedin_")){
                                return "Waiting for user to log in..."
                            } else {
                                return data.fullname;
                            }
                        }
                    },
                    {
                        data: 'eppn'
                    },
                    {
                        data: 'email'
                    },
                    {
                        data: 'number'
                    },
                    {
                        data: 'idpname'
                    },
                    {
                        data: 'affiliation',
                        render: function (data) {
                            let html = "";
                            if (data !== null) {
                                let aff = data.split(';');
                                for (let i = 0; i < aff.length; i++) {
                                    if (i === aff.length - 1) {
                                        html += aff[i];
                                    } else {
                                        html += aff[i] + '<br>';
                                    }
                                }
                            }
                            return html;
                        }
                    },
                    {
                        data: 'idp'
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            if (data.is_super_admin) {
                                html += "<span class='badge bg-danger'>Super Admin</span><br>";
                            }
                            data.memberships.forEach(function(membership) {
                                html += `${membership.study_name} - ${membership.role_name}<br>`;
                            });
                            return html;
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            if (data.id !== "<?php echo $user->getId(); ?>") {
                                html +=`<button class='btn btn-primary btn-xs me-1' onclick='edit_user("${data.id}");'>
                                            <span class='fas fa-user-edit' data-toggle='tooltip' data-placement='left' title='Edit User'></span>
                                        </button>
                                        <button class='btn btn-danger btn-xs me-1' onclick='delete_user("${data.id}");'>
                                            <span class='fas fa-user-slash' data-toggle='tooltip' data-placement='left' title='Delete User'></span>
                                        </button>`;
                            }
                            return html;
                        }
                    }
                ]
            });

            $.ajax({
                    url: "<?= $rootURL ?>/users/get-roles",
                    type: "GET",
                    success:
                        function(result){
                            $.each(result.roles, function(index){
                                roles[result.roles[index][0]] = result.roles[index][1];
                            });
                        }
                }
            );
            $.ajax({
                url : '<?= $rootURL?>/participants/fill-location-dropdown',
                type : 'GET',

                success : function(data) {
                    $('#user-location').find('option').remove();
                    data['data'].forEach(function(currentValue, index, arr){
                        $('#user-location').append("<option value='"+currentValue+"'>"+ currentValue +"</option>");
                    });
                    $('#user-location').selectpicker('refresh');
                },
                error : function(request,error) {
                    console.error(error);
                    console.error(request);
                }
            });
        });

        function study_admin_role_id() {
            for (const [roleId, roleName] of Object.entries(roles)) {
                if (roleName === 'Study Admin') {
                    return String(roleId);
                }
            }
            return null;
        }

        function add_membership_row(selectedStudy, selectedRole) {
            let studyHtml = '';
            studyOptions.forEach(function(study) {
                let sel = (selectedStudy && String(selectedStudy) === String(study.study_uuid)) ? ' selected' : '';
                studyHtml += `<option value="${study.study_uuid}"${sel}>${study.name}</option>`;
            });
            let roleHtml = '';
            for (const [roleId, roleName] of Object.entries(roles)) {
                let sel = (selectedRole && String(selectedRole) === String(roleId)) ? ' selected' : '';
                roleHtml += `<option value="${roleId}"${sel}>${roleName}</option>`;
            }
            let row = $(`<div class="row membership-row mb-2">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text">Study</span>
                            <select class="form-select membership-study">${studyHtml}</select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text">Role</span>
                            <select class="form-select membership-role">${roleHtml}</select>
                        </div>
                    </div>
                    <div class="col-md-1 d-flex align-items-center">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-membership-row">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>`);
            row.find('.remove-membership-row').on('click', function() {
                row.remove();
                toggle_study_admin_extras();
            });
            row.find('.membership-role').on('change', toggle_study_admin_extras);
            $('#membership-rows').append(row);
            toggle_study_admin_extras();
        }

        function toggle_study_admin_extras() {
            let adminRoleId = study_admin_role_id();
            let hasStudyAdmin = false;
            $('#membership-rows .membership-role').each(function() {
                if (String($(this).val()) === String(adminRoleId)) {
                    hasStudyAdmin = true;
                }
            });
            if (hasStudyAdmin) {
                $('.study-admin-extras').show();
            } else {
                $('.study-admin-extras').hide();
            }
        }

        function collect_memberships() {
            let memberships = [];
            let seen = {};
            let valid = true;
            $('#membership-rows .membership-row').each(function() {
                let studyUuid = $(this).find('.membership-study').val();
                let roleId = $(this).find('.membership-role').val();
                if (!studyUuid || !roleId) {
                    valid = false;
                    return;
                }
                if (seen[studyUuid]) {
                    valid = false;
                    showError('A user may only have one role per study.');
                    return;
                }
                seen[studyUuid] = true;
                memberships.push({'study_uuid': studyUuid, 'role_id': roleId});
            });
            return valid ? memberships : null;
        }

        function fillTimeZones(selected) {
            let location = $("#user-location").val();
            $("#user-timezone").empty();
            $.ajax({
                url : '<?= $rootURL ?>/participants/fill-timezone-dropdown',
                type : 'GET',
                data: {
                    'location': location
                },

                success : function(data) {
                    let tzSelect = $('#user-timezone');
                    tzSelect.selectpicker('destroy');
                    data['data'].forEach(function(currentValue, index, arr){
                        tzSelect.append(`<option value="${currentValue}">${currentValue}</option>`);
                        if (selected === currentValue) {
                            tzSelect.selectpicker('refresh');
                            tzSelect.val(selected);
                        }
                    });
                    tzSelect.selectpicker('refresh');
                },
                error : function(request,error) {
                    console.error(error);
                    console.error(request);
                }
            });
        }

        function submit_user() {
            let user_id = $('#user-id').val();
            if (user_id === ""){
                user_id = null;
            }

            let user_email = $('#user-email').val();
            if (user_email === null || user_email === '') {
                showError('Please enter email for user.');
                return;
            }

            if (!validateEmail(user_email)) {
                showError("Please enter a valid email.");
                return;
            }

            let isSuperAdmin = loggedInIsSuperAdmin && $('#user-super-admin').is(':checked');

            let memberships = collect_memberships();
            if (memberships === null) {
                return;
            }
            if (memberships.length === 0 && !isSuperAdmin) {
                showError('Please add at least one study membership.');
                return;
            }

            let adminRoleId = study_admin_role_id();
            let hasStudyAdmin = memberships.some(function(membership) {
                return String(membership.role_id) === String(adminRoleId);
            });

            let number = "";
            let timezone = "";
            if (hasStudyAdmin) {
                timezone = $('#user-timezone').val();
                if (timezone === null) {
                    showError('Please choose this user\'s timezone.');
                    return;
                }

                number = $('#user-number').val();
                if (number.length !== 10) {
                    showError("Please enter a 10 digit phone number.");
                    return false;
                }

                if(/[a-z]/i.test(number)){
                    showError("Please enter a valid phone number.");
                    return false;
                }
                number = '+1' + number;
            }

            let formData = {
                'id': user_id,
                'email': user_email,
                'number': number,
                'timezone': timezone,
                'memberships': memberships,
                'is_super_admin': isSuperAdmin ? 'true' : 'false'
            };

            $.ajax({
                url: '<?= $rootURL ?>/users/submit',
                type: "POST",
                data: formData,
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    showSuccess('Successfully ' + data.action + 'd user.');
                    users_datatable.ajax.reload();
                    userModal.modal('hide');
                } else {
                    showError(data.error_message);
                }
            });
        }

        function delete_user(user_id) {
            let isExecuted = confirm("Are you sure to delete this user? This action is not reversible.");
            if (isExecuted) {
                $.post({
                    url: '<?= $rootURL; ?>/users/delete',
                    data: {'id': user_id},
                    dataType: 'json'
                }).done(function(data) {
                    if (data.success) {
                        showSuccess('Successfully deleted user.');
                        users_datatable.ajax.reload( null, false );
                    } else {
                        showError(data.error_message);
                    }
                });
            }
        }

        function fill_user_form(user) {
            if (user !== null) {
                $('#user-id').val(user.id);
                $('#user-email').val(user.eppn);
                if (loggedInIsSuperAdmin) {
                    $('#user-super-admin').prop('checked', user.is_super_admin === true);
                }

                $('#membership-rows').empty();
                user.memberships.forEach(function(membership) {
                    add_membership_row(membership.study_uuid, membership.role_id);
                });
                toggle_study_admin_extras();

                let adminRoleId = study_admin_role_id();
                let hasStudyAdmin = user.memberships.some(function(membership) {
                    return String(membership.role_id) === String(adminRoleId);
                });
                if (hasStudyAdmin) {
                    $('#user-number').val((user.number && user.number !== "") ? user.number.slice(2) : "");
                    if (user.timezone) {
                        $('#user-location').val(user.timezone.includes('/') ? user.timezone.split('/')[0] : user.timezone);
                        $('#user-location').selectpicker('refresh');
                        fillTimeZones(user.timezone);
                    }
                }
            }
        }

        function edit_user(user_id) {
            let row_data = users_datatable.rows().data().filter(function(data, index){
                return data['id'] === user_id;
            }).toArray();
            fill_user_form(row_data[0]);
            userModal.modal('show');
        }

        function clear_user_form() {
            $('#user-id').val('');
            $('#user-email').val('');
            $('#user-super-admin').prop('checked', false);
            $('#membership-rows').empty();
            $('#user-number').val('');
            $('#user-location').val('');
            $('#user-location').selectpicker('refresh');
            $('#user-timezone').val('');
            $('#user-timezone').selectpicker('refresh');
            $('.study-admin-extras').hide();
        }

        userModal.on('hidden.bs.modal', function() {
            clear_user_form();
        });

        userModal.on('show.bs.modal', function() {
            if ($('#membership-rows .membership-row').length === 0 && $('#user-id').val() === '') {
                add_membership_row();
            }
        });

        function validateEmail(email) {
            const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            return emailPattern.test(email);
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
