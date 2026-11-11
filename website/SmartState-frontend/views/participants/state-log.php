<?php
/** @var User $user */
$page = 'participants-state';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Participants - <span class="text-muted">State Log</span></h1>
        <div class="card" id="success-rate-card" style="display:none;">
            <div class="card-body py-2">
                <span class="text-muted me-3"># After 8pm: <strong id="success-rate-after8" class="text-body"></strong></span>
                <span class="text-muted me-3">Success Rate: <strong id="success-rate-total" class="text-body"></strong></span>
                <button id="edit-success-rate" class="btn btn-sm btn-primary" onclick="editSuccessModal();"><i class="fas fa-pencil-alt"></i></button>
            </div>
        </div>
    </div>


    <div class="row">
        <div class="col-md-6 mb-3 form-floating">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <select class="selectpicker" data-width="fit" id="select-participant-state" data-none-selected-text="Select Participant" data-live-search="true" data-live-search-placeholder="Search"></select>
                <select class="selectpicker" data-width="fit" id="select-participant-protocol" data-none-selected-text="Select Enrollment" data-live-search="true" data-live-search-placeholder="Search"></select>
            </div>
        </div>
        <div class="col-md-6 mb-3 form-floating">
            <div id="state-info" class="float-end">
                <h5>Current State: <span id="current-state"></span></h5>
                <select class="selectpicker" data-width="fit" id="move-to-next-state" data-none-selected-text="Select State" data-live-search="false"></select>
                <input class="form-control form-control-sm" type="time" id="time-move-state" style="display:none;" value="<?= date('H:i'); ?>" />
                <button type="button" class="btn btn-sm btn-success" onclick=moveToState() id="btn-participant-state-refresh">Move State <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="collapse" data-bs-target="#state-machine" aria-expanded="false" aria-controls="collapseExample">Show/Hide State Machine</button>
            <div class="row">
                <div class="offset-md-1 col-md-10 offset-md-1">
                    <div class="collapse mb-2" id="state-machine">
                        <div id="state-machine-content" class="card card-body"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <table id="collection" class="table table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th style="text-align: center;">Timestamp</th>
                    <th style="text-align: center;">Log</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th style="text-align: center;">Timestamp</th>
                    <th style="text-align: center;">Log</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>


    <div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="successModalLabel">Edit Success Rate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6><strong>NOTE:</strong> This is the success rate that the participant sees. It is the combination of both success rates.</h6>
                    <br>
                    <div class="row">
                        <div class="col-sm-3 mb-3 form-floating">
                            <input class="form-control" type="text" style="pointer-events: auto;" id="successValue" placeholder="Success" />
                            <label for="successValue">Success</label>
                        </div>
                        <div class="col-sm-1 mb-3 mt-3 form-floating">
                            <i class="fas fa-divide"></i>
                        </div>
                        <div class="col-sm-3 mb-3 form-floating">
                            <input class="form-control" type="text" style="pointer-events: auto;" id="totalValue" placeholder="Total" />
                            <label for="totalValue">Total</label>
                        </div>
                        <div class="col-sm-2 mb-3 mt-3 form-floating">
                            <h4><strong>X</strong> 100</h4>
                        </div>
                        <div class="col-sm-3 mb-3 mt-3 form-floating">
                            <h4 style="text-align:center;">= <span id="calculatedSuccessModal"></span></h4>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-3 mb-3 form-floating">
                            <input class="form-control" type="text" style="pointer-events: auto;" id="after8pmValue" placeholder="After 8pm" />
                            <label for="after8pmValue"># After 8pm</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="updateSuccessRate();">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/@hpcc-js/wasm@2.20.0/dist/graphviz.umd.js"></script>
    <script src="<?= $rootURL ?>/js/protocol-graph.js"></script>

    <script type="text/javascript">
        let collection = {};
        let collectionTable = $('#collection');
        let collectionDataTable = null;
        let currentDefinition = null;
        let successModal = $('#successModal');
        let isPI = <?= (!$user->isSuperAdmin() && $user->roleInCurrentStudy() === 'PI') ? 'true' : 'false' ?>;
        let success = 0, total = 0, after8pm = 0, tempSuccess = 0, tempTotal = 0;

        function selectedProtocolName() {
            let selected = $('#select-participant-protocol').find("option:selected");
            if (selected.length === 0) {
                return null;
            }
            return selected.data('protocol');
        }

        function updateDT() {
            let uuid = $('#select-participant-state').val();
            let protocol = selectedProtocolName();

            if ($.fn.DataTable.isDataTable('#collection')) {
                $('#collection').DataTable().destroy();
            }

            collectionDataTable = $('#collection').DataTable({
                destroy: true,
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/participants/get-state-log",
                    type: "GET",
                    data: {
                        'uuid': uuid,
                        'protocol': protocol
                    },
                },
                order: [[0, "desc"]],
                responsive: true,
                buttons: [
                    'pageLength', 'colvis'
                ],
                layout: {
                    topStart: 'buttons',
                },
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: "_all"
                    },
                    {
                        type: "date",
                        targets: [0]
                    },
                    {
                        orderable: false,
                        targets: [1]
                    }
                ],
                language: {
                    emptyTable: "No logs for selected user."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'ts',
                        className: 'text-center'
                    },
                    {
                        data: 'json',
                        render: function (data, type, row) {
                            if (isPI) {
                                return "Not available for PI";
                            }
                            try {
                                const parsed = JSON.parse(data);
                                return JSON.stringify(parsed);
                            } catch (e) {
                                console.error("Error parsing JSON:", data);
                                return data;
                            }
                        }
                    }
                ]
            });
        }

        function loadEnrollments() {
            let uuid = $('#select-participant-state').val();
            $('#select-participant-protocol').empty();
            $.ajax({
                url : '<?= $rootURL ?>/participants/get-participant',
                type : 'GET',
                data: {
                    'id': uuid
                },
                success : function(data) {
                    if (data.success) {
                        let enrollments = data.enrollments || [];
                        let active_id = "";
                        enrollments.forEach(function(enrollment, index, arr){
                            let label = enrollment.protocol_name + " v" + enrollment.version;
                            if (enrollment.active) {
                                label += " (active)";
                                if (active_id === "") {
                                    active_id = enrollment.enrollment_uuid;
                                }
                            }
                            $('#select-participant-protocol').append(
                                $("<option></option>")
                                    .attr("value", enrollment.enrollment_uuid)
                                    .attr("data-protocol", enrollment.protocol_name)
                                    .text(label)
                            );
                        });

                        if (active_id !== "") {
                            $("#select-participant-protocol").val(active_id);
                        }
                        $('#select-participant-protocol').selectpicker('refresh');

                        updateDT();
                        updateCurrentState();
                        updateNextStates();
                        loadGraph();
                        getSuccessRate();
                    } else {
                        console.error(data.error_message);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        $(document).ready( function () {
            $.ajax({
                url : '<?= $rootURL ?>/participants/all',
                type : 'GET',

                success : function(data) {
                    if (data.success) {
                        data.participants.forEach(function(currentValue, index, arr){
                            let participant = JSON.parse(currentValue.json);
                            $('#select-participant-state').append("<option value='"+currentValue.uuid+"'>"+ participant.first_name + " "+ participant.last_name +"</option>");
                        });
                        $('#select-participant-state').selectpicker('refresh');
                        loadEnrollments();
                    } else {
                        showError(data.error_message);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        });

        $('#select-participant-state').on('changed.bs.select', function (e) {
            loadEnrollments();
        });

        $('#select-participant-protocol').on('changed.bs.select', function (){
            if ($.fn.DataTable.isDataTable('#collection')) {
                $('#collection').DataTable().destroy();
            }
            updateDT();
            updateNextStates();
            updateCurrentState();
            loadGraph();
            getSuccessRate();
        });

        $('#move-to-next-state').on('changed.bs.select', function () {
            updateTimeInputVisibility();
        });

        function targetHasTimeField(target) {
            if (!currentDefinition || !currentDefinition.states) {
                return false;
            }
            let currentState = $('#current-state').text();
            let stateDef = currentDefinition.states[currentState];
            if (!stateDef || !Array.isArray(stateDef.manualMoves)) {
                return false;
            }
            return stateDef.manualMoves.some(function(move) {
                return move.to === target && move.timeField;
            });
        }

        function updateTimeInputVisibility() {
            let target = $('#move-to-next-state').val();
            if (target && targetHasTimeField(target)) {
                $('#time-move-state').show();
            } else {
                $('#time-move-state').hide();
            }
        }

        function getSuccessRate() {
            let protocol = selectedProtocolName() || '';
            if (protocol.indexOf('TRE') === -1) {
                $('#success-rate-card').hide();
                return;
            }
            $('#success-rate-card').show();
            let uuid = $('#select-participant-state').val();
            $.ajax({
                url : '<?= $rootURL ?>/participants/success-rate',
                type : 'GET',
                data: {'uuid': uuid},
                success : function(data) {
                    if (data.success && data.success_json && data.success_json['total'] > 0) {
                        success = parseInt(data.success_json['success']);
                        after8pm = parseInt(data.success_json['after8pm']);
                        total = parseInt(data.success_json['total']);
                        tempSuccess = success;
                        tempTotal = total;
                        $('#success-rate-after8').html(after8pm);
                        $('#success-rate-total').html(success + "/" + total + " (" + ((success / total) * 100).toFixed(2) + "%)");
                    } else {
                        success = 0;
                        after8pm = 0;
                        total = 0;
                        tempSuccess = 0;
                        tempTotal = 0;
                        $('#success-rate-after8').html("N/A");
                        $('#success-rate-total').html("N/A");
                        if (!data.success) {
                            console.error(data.error_message);
                        }
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        $('#successValue').keyup(function() {
            tempSuccess = $(this).val();
            recalculateSuccessRate();
        });

        $('#totalValue').keyup(function() {
            tempTotal = $(this).val();
            recalculateSuccessRate();
        });

        function recalculateSuccessRate() {
            let percentage;
            if (tempTotal == 0) {
                percentage = "0.00";
            } else {
                percentage = ((tempSuccess / tempTotal) * 100).toFixed(2);
            }
            $('#calculatedSuccessModal').html(percentage + "%");
        }

        function editSuccessModal() {
            $('#successValue').val(success);
            $('#totalValue').val(total);
            $('#after8pmValue').val(after8pm);
            tempSuccess = success;
            tempTotal = total;
            recalculateSuccessRate();
            successModal.modal('show');
        }

        successModal.on('hidden.bs.modal', function() {
            $('#successValue').val(0);
            $('#totalValue').val(0);
            $('#after8pmValue').val(0);
        });

        function updateSuccessRate() {
            let uuid = $('#select-participant-state').val();
            let successful = $('#successValue').val();
            let totalValue = $('#totalValue').val();
            let after8pmValue = $('#after8pmValue').val();

            if (!/^\d+$/.test(successful) || !/^\d+$/.test(totalValue) || !/^\d+$/.test(after8pmValue)) {
                showError("Please put whole numbers in the success, total, and after 8pm fields.");
                return;
            }

            $.ajax({
                url : '<?= $rootURL ?>/participants/update-success-rate',
                type : 'POST',
                data: {
                    'uuid': uuid,
                    'successful': successful,
                    'total': totalValue,
                    'after8pm': after8pmValue
                },
                success : function(data) {
                    if (data.success) {
                        getSuccessRate();
                        if ($.fn.DataTable.isDataTable('#collection')) {
                            $('#collection').DataTable().destroy();
                        }
                        updateDT();
                        successModal.modal('hide');
                        showSuccess("Updated success rate.");
                    } else {
                        showError(data.error_message);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        function updateCurrentState(callback){
            let uuid = $('#select-participant-state').val();
            let protocol = selectedProtocolName();
            $.ajax({
                url : '<?= $rootURL ?>/participants/get-current-state',
                type : 'GET',
                data: {
                    'uuid': uuid,
                    'protocol': protocol
                },
                success : function(data) {
                    if (data.success) {
                        $('#current-state').html(data.state);
                    } else {
                        $('#current-state').html("Unknown");
                        console.error(data.error_message);
                    }
                    if (typeof callback === 'function') {
                        callback();
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        function updateNextStates() {
            let participant_uuid = $('#select-participant-state').val();
            let protocol = selectedProtocolName();
            $.ajax ({
                url: '<?= $rootURL ?>/participants/next-states',
                type: 'GET',
                data: {
                    'uuid': participant_uuid,
                    'protocol': protocol
                },
                success: function(data) {
                    if (data.status == "ok") {
                        let states = data.valid_states.split(',');
                        $('#move-to-next-state').empty();
                        states.forEach(function(currentValue, index, arr){
                            $('#move-to-next-state').append("<option value='"+currentValue+"'>"+currentValue+"</option>");
                        });
                        $('#move-to-next-state').selectpicker('refresh');
                        updateTimeInputVisibility();
                    } else {
                        console.error(data.status_desc);
                    }
                },
                error: function(request,error) {
                    console.error(error);
                }
            });
        }

        function moveToState() {
            let uuid = $('#select-participant-state').val();
            let state = $('#move-to-next-state').val();
            let protocol = selectedProtocolName();

            let time_for_state = -1;
            if (state && targetHasTimeField(state)) {
                time_for_state = $('#time-move-state').val();
            }

            $.ajax({
                url : '<?= $rootURL ?>/participants/move-state',
                type : 'POST',
                data: {
                    'uuid': uuid,
                    'toState': state,
                    'time': time_for_state,
                    'protocol': protocol
                    },

                success : function(data) {
                    if (data.status === "ok") {
                        updateCurrentState();
                        updateNextStates();
                        if ($.fn.DataTable.isDataTable('#collection')) {
                            $('#collection').DataTable().destroy();
                        }
                        updateDT();
                        loadGraph();
                        getSuccessRate();
                    } else {
                        console.error(data.status_desc);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        function loadGraph(){
            let enrollment = $('#select-participant-protocol').val();
            if (!enrollment) {
                return;
            }
            $.ajax({
                url : '<?= $rootURL ?>/protocols/definition',
                type : 'GET',
                data: {'enrollment': enrollment},
                success : function(data) {
                    let def = data.definition || data.data || data;
                    if (typeof def === 'string') {
                        try {
                            def = JSON.parse(def);
                        } catch (e) {
                            console.error("Error parsing definition JSON:", e);
                            return;
                        }
                    }
                    currentDefinition = def;
                    updateTimeInputVisibility();
                    let currentState = $('#current-state').html();
                    let dot = definitionToDot(def, currentState);
                    window.renderProtocolGraph("#state-machine-content", dot);
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
