<?php
/** @var UserSession $userSession */
$page = 'participants-state';
global $rootURL, $CONFIG;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Participants - <span class="text-muted">State Log</span></h1>
    </div>


    <div class="row">
        <div class="col-md-6 mb-3 form-floating">
            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                <select class="selectpicker" data-width="fit" id="select-participant-state" data-none-selected-text="Select Participant" data-live-search="true" data-live-search-placeholder="Search"></select>
                <select class="selectpicker" data-width="fit" id="select-participant-protocol" data-none-selected-text="Select Protocol" data-live-search="true" data-live-search-placeholder="Search"></select>
            </div>
        </div>
        <div class="col-md-6 mb-3 form-floating">
            <div id="state-info" class="float-end">
                <h5>Current State: <span id="current-state"></span></h5>
                <select class="selectpicker" data-width="fit" id="move-to-next-state" data-none-selected-text="Select State" data-live-search="false"></select>
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
                        <div id="state-machine-content" class="card card-body" style="min-height: 600px;"></div>
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


    <!-- These scripts read graphviz files -->
    <script src="https://d3js.org/d3.v5.min.js"></script>
    <script src="https://unpkg.com/@hpcc-js/wasm@1.20.1/dist/index.min.js"></script>
    <script src="https://unpkg.com/d3-graphviz@4.1.0/build/d3-graphviz.js"></script>
   
    <script type="text/javascript">
        let collection = {};
        let collectionTable = $('#collection');
        let collectionDataTable = null;

        function updateDT() {
            let uuid = $('#select-participant-state').val();
            let protocol = $('#select-participant-protocol').find("option:selected").text();
            if (protocol.includes("(active)")) {
                protocol = protocol.replace("(active)", "").trim();
            }

            // Destroy the previous instance if it exists
            if ($.fn.DataTable.isDataTable('#collection')) {
                $('#collection').DataTable().destroy();
            }

            collectionDataTable = $('#collection').DataTable({
                destroy: true,
                serverSide: true,
                processing: true,
                ajax: {
                    url: "/participants/get-state-log",
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
                        data: 'ts', // Timestamp column
                        className: 'text-center'
                    },
                    {
                        data: 'json', // JSON data column
                        render: function (data, type, row) {
                            // Format the JSON string as a readable object
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


        $(document).ready( function () {
            $.ajax({
                url : '<?= $rootURL ?>/participants/all',
                type : 'GET',

                success : function(data) {
                    if (data.success) {
                        data.participants.forEach(function(currentValue, index, arr){
                            // parse json;
                            let participant = JSON.parse(currentValue.json);
                            $('#select-participant-state').append("<option value='"+currentValue.uuid+"'>"+ participant.first_name + " "+ participant.last_name +"</option>");
                        });
                        $('#select-participant-state').selectpicker('refresh');
                        $.ajax({
                            url : '/protocol-types/all',
                            type : 'GET',
                            data: {
                                'uuid': $('#select-participant-state').val(),
                                'study': 'Default'
                            },
                            success : function(data) {
                                if (data.success) {
                                    let active_id = "";
                                    data.protocols.forEach(function(currentValue, index, arr){
                                        if (data.actives.includes(currentValue.name)) {
                                            $('#select-participant-protocol').append("<option value='"+currentValue.id+"'>"+ currentValue.name + " (active)</option>");
                                            if (active_id === "") {
                                                active_id = currentValue.id;
                                            }
                                        } else {
                                            $('#select-participant-protocol').append("<option value='"+currentValue.id+"'>"+ currentValue.name +"</option>");
                                        }
                                    });

                                    $("#select-participant-protocol").val(active_id);
                                    $('#select-participant-protocol').selectpicker('refresh');

                                    updateDT();
                                    updateCurrentState();
                                    updateNextStates();
                                } else {
                                    console.error(data.error_message);
                                }
                            },
                            error : function(request,error) {
                                console.error(error);
                            }
                        });
                    } else {
                        showError(data.error_message);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });

            $('#state-machine').on('shown.bs.collapse', function () {
                // Small delay to ensure the div has calculated its height
                setTimeout(function() {
                    loadGraphFile();
                }, 50); 
            });
        });

        $('#select-participant-state').on('changed.bs.select', function (e) {
            $('#collection').DataTable().destroy();
            updateDT();
            updateCurrentState();
        });

        $('#select-participant-protocol').on('changed.bs.select', function (){
            $('#collection').DataTable().destroy();
            updateDT();
            updateNextStates();
            updateCurrentState();
            
            // Only render if we can actually see the container
            if ($('#state-machine').hasClass('show')) {
                loadGraphFile();
            }
        });

        function updateCurrentState(){
            let uuid = $('#select-participant-state').val();
            let protocol = $('#select-participant-protocol').find("option:selected").text();
            if (protocol.includes("(active)")) {
                protocol = protocol.replace("(active)", "").trim();
            }
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
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        function updateNextStates() {
            let participant_uuid = $('#select-participant-state').val();
            let participant_study = 'Default';
            let protocol = $('#select-participant-protocol').find("option:selected").text();
            if (protocol.includes("(active)")) {
                protocol = protocol.replace("(active)", "").trim();
            }
            $.ajax ({
                url: '<?= $CONFIG['java_api_url'] ?>/api/get-valid-next-states/'+participant_uuid+'/'+participant_study+'/'+protocol,
                type: 'GET',
                success: function(data) {
                    if (data.status == "ok") {
                        let states = data.valid_states.split(',');
                        $('#move-to-next-state').empty();
                        states.forEach(function(currentValue, index, arr){
                            $('#move-to-next-state').append("<option value='"+currentValue+"'>"+currentValue+"</option>");
                        });
                        $('#move-to-next-state').selectpicker('refresh');
                        
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
            let protocol = $('#select-participant-protocol').find("option:selected").text();
            if (protocol.includes("(active)")) {
                protocol = protocol.replace("(active)", "").trim();
            }

            let time_for_state = -1; // this is for entering a time that a participant might text in to the system
            if (state === "some-state-here") {
                time_for_state = $('#time-start-end').val();
            }


            $.ajax({
                url : '<?= $CONFIG['java_api_url'] ?>/api/next-state/', //change to this when developing locally
                type : 'POST',
                data: {
                    'participantUUID': uuid, 
                    'toState': state,
                    'time': time_for_state,
                    'study': 'Default',
                    'protocol': protocol
                    },

                success : function(data) {
                    if (data.status === "ok") {
                        updateCurrentState();
                        updateNextStates();
                        $('#collection').DataTable().destroy();
                        updateDT();
                        loadGraphFile();
                    } else {
                        console.error(data.status_desc);
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }

        // graphviz stuff
        function loadGraphFile(){
            let protocol = $('#select-participant-protocol').find("option:selected").text();
            if (protocol.includes("(active)")) {
                protocol = protocol.replace("(active)", "").trim();
            }
            $.ajax({
                url : '<?= $rootURL ?>/participants/get-state-machine',
                type : 'POST',
                data: {'protocol': protocol},
                success : function(data) {
                    let content = data.content.replaceAll("\n", "");
                    if (data.success){
                        let currentState = $('#current-state').html();

                        $("#state-machine-content").empty();
                        
                        d3.select("#state-machine-content")
                        .graphviz()
                        .fit(true)
                        .zoom(true)
                        .attributer(function(d){
                                if (d.id.includes(currentState)) { //polygons are arrow points, text and g are text inside bubbles and connecting bubbles,
                                    if (d.tag == "path"){
                                        if (d.id.includes("->")){
                                            let drawArrows = d.id.split("->");
                                            if (drawArrows[0].includes(currentState)){
                                            d.attributes.stroke = "#ff2500";
                                        }
                                    } else {
                                        d.attributes.fill = "#62dfff";
                                    }
                                } else if (d.tag == "polygon") {
                                    let drawArrows = d.id.split("->");
                                    if (drawArrows[0].includes(currentState)){
                                        d.attributes.fill = "#ff2500";
                                        d.attributes.stroke = "#ff2500";
                                    }
                                }
                            }
                        }).renderDot(data.content));
                    }
                },
                error : function(request,error) {
                    console.error(error);
                }
            });
        }
    </script>
<?php
include_once __DIR__ . '/../_footer.php';