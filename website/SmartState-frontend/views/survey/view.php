<?php
/** @var UserSession $userSession */
$page = 'survey-view';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4 mr-auto p-2">Survey - <span class="text-muted">View All</span></h1>
    </div>
    <div class="row">
        <div class="col">
            <table id="collection" class="table table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Token</th>
                    <th style="text-align: center;">Participant Name</th>
                    <th style="text-align: center;">Survey Key</th>
                    <th style="text-align: center;">Responses</th>
                    <th style="text-align: center;">Created At</th>
                    <th style="text-align: center;">Finished At</th>
                    <th style="text-align: center;">Time Zone</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>Token</th>
                    <th style="text-align: center;">Participant Name</th>
                    <th style="text-align: center;">Survey Key</th>
                    <th style="text-align: center;">Responses</th>
                    <th style="text-align: center;">Created At</th>
                    <th style="text-align: center;">Finished At</th>
                    <th style="text-align: center;">Time Zone</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <script type="text/javascript">
        var collectionTable = $('#collection');
        var collectionDataTable = null;

        function escapeCell(value) {
            return $('<div>').text(value).html();
        }

        function renderResponses(row) {
            if (row.survey_json == null) {
                return "";
            }
            var responses;
            try {
                responses = JSON.parse(row.survey_json);
            } catch (e) {
                return escapeCell(row.survey_json);
            }
            if (responses === null || typeof responses !== 'object') {
                return escapeCell(String(responses));
            }
            var items = '';
            $.each(responses, function(key, value) {
                if (value !== null && typeof value === 'object') {
                    value = JSON.stringify(value);
                }
                items += '<dt class="text-truncate">' + escapeCell(String(key)) + '</dt><dd class="mb-1">' + escapeCell(String(value)) + '</dd>';
            });
            return '<details class="text-start"><summary>View responses</summary><dl class="mb-0">' + items + '</dl></details>';
        }

        $(function() {
            collectionDataTable = collectionTable.DataTable({
                serverSide: false,
                ajax: {
                    url: "<?= $rootURL ?>/survey/list"
                },
                order: [[ 5, "desc" ]],
                responsive: true,
                dom: 'Bfrtip',
                buttons: [
                    'pageLength', 'colvis'
                ],
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: [0,1,2,3,4,5,6]
                    },
                    {
                        orderable: true,
                        targets: [0,1,2,4,5,6]
                    },
                    {
                        orderable: false,
                        targets: [3]
                    },
                    {
                        type: "date",
                        targets: [4, 5]
                    }
                ],
                language: {
                    emptyTable: "No surveys have been sent/received yet."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'token'
                    },
                    {
                        data: 'participant_name'
                    },
                    {
                        data: 'survey_key',
                        render: function(data) {
                            return data == null ? "" : escapeCell(data);
                        }
                    },
                    {
                        data: null,
                        render: function(data, type, row) {
                            return renderResponses(row);
                        }
                    },
                    {
                        data: 'created_at',
                    },
                    {
                        data: 'finished_at',
                    },
                    {
                        data: 'time_zone',
                    }
                ]
            });

            collectionDataTable.buttons().container().prependTo('#collection_filter');
            collectionDataTable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
        });
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
