<?php
/** @var UserSession $userSession */
$page = 'home';
include_once __DIR__ . '/_header.php';
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Dashboard</h1>
    </div>

    <div class="row mb-4 gy-3">
        <div class="col-md-4">
            <a href="<?= $rootURL ?>/participants" class="text-decoration-none">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Active Participants</h5>
                        <h2 id="active-part"></h2>
                        <p class="card-text" id="total-part"></p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= $rootURL ?>/participants" class="text-decoration-none">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Active Devices</h5>
                        <h2 id="active-dev"></h2>
                        <p class="card-text">&nbsp;</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= $rootURL ?>/survey/view" class="text-decoration-none">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Surveys Completed</h5>
                        <h2 id="survey-complete"></h2>
                        <p id="survey-total" class="card-text"></p>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Protocol Compliance (Last 7 Days)</h5>
                    <div id="complianceChart"></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Participant State Distribution</h5>
                    <div id="stateDistributionChart"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Messages Per Day (Last 14 Days)</h5>
                    <div id="messageVolumeChart"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.plot.ly/plotly-2.35.2.min.js" charset="utf-8"></script>
<?php include_once __DIR__ . '/_footer.php'; ?>

<script>
    $(document).ready(function() {
        updateStats();
        updateCompliance();
        updateStateDistribution();
        updateMessageVolume();
    });

    function showChartEmptyState(containerId, message) {
        $('#' + containerId).html('<div class="d-flex align-items-center justify-content-center text-muted" style="height: 300px;">' + message + '</div>');
    }

    function updateStats() {
        $.ajax({
            url: '<?= $rootURL ?>/participants/home-stats',
            type: 'GET',
            success: function(data) {
                if (data.success) {
                    let patientStats = data.patientStats;
                    let surveyStats = data.surveyStats;
                    $('#active-part').text(patientStats['active']);
                    $('#total-part').text(`Total: ${patientStats['total']}`);

                    $('#active-dev').text(patientStats['devices']);

                    $('#survey-complete').text(surveyStats['completed']);
                    $('#survey-total').text(`Total: ${surveyStats['total']}`);
                } else {
                    showError(data.error_message);
                }
            },
            error: function (xhr, status, error) {
                console.error(xhr.responseText);
                console.error(status);
                console.error(error);
                showError("Error communicating with the server.");
            }
        });
    }

    function updateCompliance() {
        $.ajax({
            url: '<?= $rootURL ?>/metrics/compliance',
            type: 'GET',
            success: function(data) {
                if (!data.success) {
                    showChartEmptyState('complianceChart', data.error_message || 'Unable to load compliance data.');
                    return;
                }
                if (!data.values || data.values.length === 0) {
                    showChartEmptyState('complianceChart', 'No compliance data recorded for this study yet.');
                    return;
                }

                const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                const todayIndex = new Date().getDay();
                const reorderedDays = [...weekDays.slice(todayIndex + 1), ...weekDays.slice(0, todayIndex + 1)];

                const complianceLookup = {};
                data.values.forEach(item => {
                    if (item.total_total > 0) {
                        complianceLookup[item.day_of_week] = (item.total_good / item.total_total) * 100;
                    }
                });

                const complianceRates = reorderedDays.map(day => {
                    return complianceLookup[day] !== undefined ? complianceLookup[day] : null;
                });

                const complianceTrace = {
                    x: reorderedDays,
                    y: complianceRates,
                    type: 'scatter',
                    mode: 'lines+markers',
                    name: 'Compliance Rate (%)'
                };

                const complianceLayout = {
                    yaxis: { range: [0, 105], title: { text: 'Compliance Rate (%)' } },
                    height: 300,
                    margin: { t: 20, r: 20 }
                };

                Plotly.newPlot('complianceChart', [complianceTrace], complianceLayout, { responsive: true, displayModeBar: false });
            },
            error: function(request, error) {
                console.error("AJAX Error:", error);
                showChartEmptyState('complianceChart', 'Unable to load compliance data.');
            }
        });
    }

    function updateStateDistribution() {
        $.ajax({
            url: '<?= $rootURL ?>/metrics/state-distribution',
            type: 'GET',
            success: function(data) {
                if (!data.success) {
                    showChartEmptyState('stateDistributionChart', data.error_message || 'Unable to load state distribution.');
                    return;
                }
                if (!data.values || data.values.length === 0) {
                    showChartEmptyState('stateDistributionChart', 'No participant state data recorded for this study yet.');
                    return;
                }

                const rows = data.values.slice().reverse();
                const stateTrace = {
                    type: 'bar',
                    orientation: 'h',
                    x: rows.map(item => item.count),
                    y: rows.map(item => item.state)
                };

                const stateLayout = {
                    xaxis: { title: { text: 'Participants' }, tickformat: ',d' },
                    yaxis: { automargin: true },
                    height: 300,
                    margin: { t: 20, r: 20 }
                };

                Plotly.newPlot('stateDistributionChart', [stateTrace], stateLayout, { responsive: true, displayModeBar: false });
            },
            error: function(request, error) {
                console.error("AJAX Error:", error);
                showChartEmptyState('stateDistributionChart', 'Unable to load state distribution.');
            }
        });
    }

    function updateMessageVolume() {
        $.ajax({
            url: '<?= $rootURL ?>/metrics/message-volume',
            type: 'GET',
            success: function(data) {
                if (!data.success) {
                    showChartEmptyState('messageVolumeChart', data.error_message || 'Unable to load message volume.');
                    return;
                }
                if (!data.values || data.values.length === 0) {
                    showChartEmptyState('messageVolumeChart', 'No messages recorded for this study in the last 14 days.');
                    return;
                }

                const days = [];
                const now = new Date();
                for (let i = 13; i >= 0; i--) {
                    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - i);
                    const month = String(d.getMonth() + 1).padStart(2, '0');
                    const dayOfMonth = String(d.getDate()).padStart(2, '0');
                    days.push(`${d.getFullYear()}-${month}-${dayOfMonth}`);
                }

                const incomingLookup = {};
                const outgoingLookup = {};
                data.values.forEach(item => {
                    if (item.direction === 'incoming') {
                        incomingLookup[item.day] = item.count;
                    } else if (item.direction === 'outgoing') {
                        outgoingLookup[item.day] = item.count;
                    }
                    if (days.indexOf(item.day) === -1) {
                        days.push(item.day);
                    }
                });
                days.sort();

                const incomingTrace = {
                    x: days,
                    y: days.map(day => incomingLookup[day] || 0),
                    type: 'scatter',
                    mode: 'lines+markers',
                    name: 'Incoming'
                };

                const outgoingTrace = {
                    x: days,
                    y: days.map(day => outgoingLookup[day] || 0),
                    type: 'scatter',
                    mode: 'lines+markers',
                    name: 'Outgoing'
                };

                const volumeLayout = {
                    yaxis: { title: { text: 'Messages' }, rangemode: 'tozero', tickformat: ',d' },
                    xaxis: { type: 'category' },
                    height: 300,
                    margin: { t: 20, r: 20 },
                    legend: { orientation: 'h' }
                };

                Plotly.newPlot('messageVolumeChart', [incomingTrace, outgoingTrace], volumeLayout, { responsive: true, displayModeBar: false });
            },
            error: function(request, error) {
                console.error("AJAX Error:", error);
                showChartEmptyState('messageVolumeChart', 'Unable to load message volume.');
            }
        });
    }
</script>
