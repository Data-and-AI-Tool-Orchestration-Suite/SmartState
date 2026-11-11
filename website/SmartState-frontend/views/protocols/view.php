<?php
/** @var User $user */
/** @var ProtocolDefinition|null $protocol */
$page = 'protocols-index';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
<?php if (is_null($protocol)): ?>
    <div class="alert alert-danger">No protocol definition found.</div>
<?php else: ?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">
            Protocols - <span class="text-muted"><?= htmlspecialchars($protocol->getName()) ?> v<?= $protocol->getVersion() ?></span>
            <?php if ($protocol->getStatus() === 'published'): ?>
                <span class="badge bg-success">Published</span>
            <?php elseif ($protocol->getStatus() === 'draft'): ?>
                <span class="badge bg-warning text-dark">Draft</span>
            <?php else: ?>
                <span class="badge bg-secondary">Archived</span>
            <?php endif; ?>
            <?php if (!is_null($protocol->getPublishedAt())): ?>
                <small class="text-muted"><?= htmlspecialchars((new DateTime($protocol->getPublishedAt(), new DateTimeZone('UTC')))->format('M j, Y g:i A T')) ?></small>
            <?php endif; ?>
        </h1>
        <a class="btn btn-secondary" href="<?= $rootURL ?>/protocols"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card mb-3">
                <div class="card-header">Diagram</div>
                <div class="card-body" style="overflow: auto;">
                    <div id="protocol-graph"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12" id="states-detail"></div>
    </div>

    <div class="row">
        <div class="col-md-12" id="surveys-detail"></div>
    </div>

    <script src="https://unpkg.com/@hpcc-js/wasm@2.20.0/dist/graphviz.umd.js"></script>
    <script src="<?= $rootURL ?>/js/protocol-graph.js"></script>

    <script type="text/javascript">
        var def = <?= json_encode($protocol->getDefinition()) ?>;

        function escAttr(value) {
            return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function describeAction(action) {
            if (!action || !action.type) return '';
            if (action.type === 'sendSms' || action.type === 'notifyAdmins') {
                return escAttr(action.template || '');
            }
            if (action.type === 'setTimer') {
                return 'timer: ' + escAttr(action.timer || '') + ', deadline: ' + escAttr(JSON.stringify(action.deadline || {}));
            }
            if (action.type === 'generateSurveyToken') {
                return 'surveyKey: ' + escAttr(action.surveyKey || '');
            }
            if (action.type === 'llmQuestion') {
                return 'question: ' + escAttr(action.question || '') + ', answerKey: ' + escAttr(action.answerKey || '');
            }
            if (action.type === 'sendResultSms') {
                return 'source: ' + escAttr(action.source || '') + ', messages: ' + escAttr(JSON.stringify(action.messages || {}));
            }
            return '';
        }

        function describeTrigger(transition) {
            if (transition.event) return escAttr(transition.event);
            if (transition.after) return 'after(' + escAttr(transition.after) + ')';
            return 'auto';
        }

        function renderStates() {
            var html = '';
            var states = def.states || {};
            Object.keys(states).forEach(function(name) {
                var state = states[name] || {};
                var badges = '';
                if (state.terminal) badges += ' <span class="badge bg-dark">terminal</span>';
                if (def.initialState === name) badges += ' <span class="badge bg-info text-dark">initial</span>';
                html += '<div class="card mb-3"><div class="card-header"><strong>' + escAttr(name) + '</strong>' + badges + '</div><div class="card-body">';
                html += '<div class="fw-bold small mb-1">Entry Actions</div>';
                if ((state.entry || []).length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead><tr><th>Type</th><th>Details</th></tr></thead><tbody>';
                    (state.entry || []).forEach(function(action) {
                        html += '<tr><td>' + escAttr(action.type) + '</td><td>' + describeAction(action) + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted small">None</p>';
                }
                html += '<div class="fw-bold small mb-1">Transitions</div>';
                if ((state.transitions || []).length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead><tr><th>Trigger</th><th>Target</th></tr></thead><tbody>';
                    (state.transitions || []).forEach(function(transition) {
                        html += '<tr><td>' + describeTrigger(transition) + '</td><td>' + escAttr(transition.to || '') + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted small">None</p>';
                }
                html += '<div class="fw-bold small mb-1">Manual Moves</div>';
                if ((state.manualMoves || []).length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Target</th><th>Via</th></tr></thead><tbody>';
                    (state.manualMoves || []).forEach(function(move) {
                        html += '<tr><td>' + escAttr(move.to || '') + '</td><td>' + escAttr(move.via || '') + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted small mb-0">None</p>';
                }
                html += '</div></div>';
            });
            $('#states-detail').html(html);
        }

        function renderSurveys() {
            var surveys = def.surveys || [];
            if (surveys.length === 0) {
                return;
            }
            var html = '';
            surveys.forEach(function(survey) {
                html += '<div class="card mb-3"><div class="card-header"><strong>' + escAttr(survey.title || survey.key) + '</strong> <small class="text-muted">' + escAttr(survey.key || '') + '</small></div><div class="card-body">';
                (survey.questions || []).forEach(function(question) {
                    html += '<div class="border rounded p-2 mb-2">';
                    html += '<div><strong>' + escAttr(question.label || question.id) + '</strong>' + (question.required ? ' <span class="text-danger">*</span>' : '') + '</div>';
                    html += '<div class="small text-muted">' + escAttr(question.id || '') + ' - ' + escAttr(question.type || '') + '</div>';
                    if ((question.options || []).length > 0) {
                        html += '<div class="small">Options: ' + escAttr((question.options || []).join(', ')) + '</div>';
                    }
                    html += '</div>';
                });
                html += '</div></div>';
            });
            $('#surveys-detail').html(html);
        }

        $(function() {
            window.renderProtocolGraph('#protocol-graph', window.definitionToDot(def, null));
            renderStates();
            renderSurveys();
        });
    </script>
<?php endif; ?>
<?php
include_once __DIR__ . '/../_footer.php';
