<?php
/** @var User $user */
$page = 'protocols-index';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Protocols - <span class="text-muted" id="protocol-title">Edit Draft</span></h1>
        <div>
            <a class="btn btn-secondary me-1" href="<?= $rootURL ?>/protocols"><i class="fas fa-arrow-left"></i> Back</a>
            <button type="button" class="btn btn-primary me-1" id="btn-save" onclick="save_draft();"><i class="fas fa-save"></i> Save Draft</button>
            <button type="button" class="btn btn-info me-1" id="btn-validate" onclick="validate_definition();"><i class="fas fa-check"></i> Validate</button>
            <button type="button" class="btn btn-success" id="btn-publish" disabled data-bs-toggle="modal" data-bs-target="#publishModal"><i class="fas fa-upload"></i> Publish</button>
        </div>
    </div>

    <div id="not-draft-warning" class="alert alert-warning d-none">This version is not a draft and cannot be edited.</div>

    <div class="row">
        <div class="col-xl-6" id="builder">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="states-tab" data-bs-toggle="tab" data-bs-target="#tab-states" type="button" role="tab">States</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="timers-tab" data-bs-toggle="tab" data-bs-target="#tab-timers" type="button" role="tab">Timers</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="surveys-tab" data-bs-toggle="tab" data-bs-target="#tab-surveys" type="button" role="tab">Surveys</button>
                </li>
            </ul>
            <div class="tab-content border border-top-0 p-3">
                <div class="tab-pane fade show active" id="tab-states" role="tabpanel">
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text">Initial State</span>
                        <select id="initial-state" class="form-select"></select>
                    </div>
                    <div class="accordion mb-3" id="states-accordion"></div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">New State</span>
                        <input type="text" id="new-state-name" class="form-control" placeholder="stateName">
                        <button type="button" class="btn btn-success" data-act="add-state"><i class="fas fa-plus"></i> Add State</button>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-timers" role="tabpanel">
                    <div id="timers-list" class="mb-3"></div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">New Timer</span>
                        <input type="text" id="new-timer-name" class="form-control" placeholder="timerName">
                        <button type="button" class="btn btn-success" data-act="add-timer"><i class="fas fa-plus"></i> Add Timer</button>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-surveys" role="tabpanel">
                    <div id="surveys-list" class="mb-3"></div>
                    <button type="button" class="btn btn-success btn-sm" data-act="add-survey"><i class="fas fa-plus"></i> Add Survey</button>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="sticky-top" style="top: 70px; z-index: 1;">
                <div class="card mb-3">
                    <div class="card-header">Diagram</div>
                    <div class="card-body" style="overflow: auto; max-height: 60vh;">
                        <div id="protocol-graph"></div>
                    </div>
                </div>
                <div class="card mb-3" id="lint-card">
                    <div class="card-header">Structure Checks</div>
                    <div class="card-body py-2">
                        <ul class="mb-0 small" id="lint-list"></ul>
                    </div>
                </div>
                <div class="card mb-3 d-none" id="validate-card">
                    <div class="card-header">Validation Results</div>
                    <div class="card-body py-2">
                        <ul class="mb-0 small" id="validate-list"></ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="publishModal" tabindex="-1" role="dialog" aria-labelledby="publishModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="publishModalLabel">Publish Protocol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>You are about to publish <strong id="publish-label"></strong>.</p>
                    <p>The draft will be saved and validated by the engine before publishing. A published version can no longer be edited.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirm_publish();">Publish</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/@hpcc-js/wasm@2.20.0/dist/graphviz.umd.js"></script>
    <script src="<?= $rootURL ?>/js/protocol-graph.js"></script>

    <script type="text/javascript">
        var definitionUuid = <?= json_encode($_GET['definition'] ?? '') ?>;
        var def = null;
        var meta = {};
        var openState = null;
        var publishModal = $('#publishModal');

        var ACTION_TYPES = ['sendSms', 'notifyAdmins', 'setTimer', 'generateSurveyToken', 'llmQuestion', 'sendResultSms', 'resetDeviceWindow'];
        var DEADLINE_TYPES = ['fixedSeconds', 'secondsToClock', 'secondsToNextMinuteMark', 'secondsToHourBoundaryPlus', 'conditional'];
        var PREDICATE_TYPES = ['beforeClock', 'timerWithinMinuteWindow', 'always'];
        var QUESTION_TYPES = ['select', 'radio', 'textarea', 'number', 'scale'];

        function escAttr(value) {
            return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function pathAttr(path) {
            return 'data-path="' + escAttr(JSON.stringify(path)) + '"';
        }

        function getByPath(obj, path) {
            var target = obj;
            for (var i = 0; i < path.length; i++) {
                if (target == null) return undefined;
                target = target[path[i]];
            }
            return target;
        }

        function setByPath(obj, path, value) {
            var target = obj;
            for (var i = 0; i < path.length - 1; i++) {
                target = target[path[i]];
            }
            target[path[path.length - 1]] = value;
        }

        function stateNames() {
            return Object.keys(def.states || {});
        }

        function surveyKeys() {
            return (def.surveys || []).map(function(s) { return s.key; }).filter(function(k) { return k; });
        }

        function textInput(label, path, value, rr) {
            return '<div class="input-group input-group-sm mb-1"><span class="input-group-text">' + escAttr(label) + '</span>' +
                '<input type="text" class="form-control" ' + pathAttr(path) + (rr ? ' data-rr="1"' : '') + ' value="' + escAttr(value) + '"></div>';
        }

        function numberInput(label, path, value) {
            return '<div class="input-group input-group-sm mb-1"><span class="input-group-text">' + escAttr(label) + '</span>' +
                '<input type="number" class="form-control" data-dtype="int" ' + pathAttr(path) + ' value="' + escAttr(value == null ? 0 : value) + '"></div>';
        }

        function checkboxInput(label, path, checked, rr) {
            return '<label class="form-check small mb-1"><input class="form-check-input" type="checkbox" data-dtype="bool" ' + pathAttr(path) +
                (rr ? ' data-rr="1"' : '') + (checked ? ' checked' : '') + '> ' + escAttr(label) + '</label>';
        }

        function textareaInput(label, path, value, rows) {
            return '<div class="mb-1"><div class="form-text mt-0">' + escAttr(label) + '</div>' +
                '<textarea class="form-control form-control-sm" rows="' + (rows || 2) + '" ' + pathAttr(path) + '>' + escAttr(value) + '</textarea></div>';
        }

        function jsonTextarea(label, path, obj) {
            return '<div class="mb-1"><div class="form-text mt-0">' + escAttr(label) + ' (JSON object)</div>' +
                '<textarea class="form-control form-control-sm" rows="2" data-dtype="json" ' + pathAttr(path) + '>' + escAttr(JSON.stringify(obj || {})) + '</textarea></div>';
        }

        function csvInput(label, path, arr) {
            return '<div class="input-group input-group-sm mb-1"><span class="input-group-text">' + escAttr(label) + '</span>' +
                '<input type="text" class="form-control" data-dtype="csv" ' + pathAttr(path) + ' value="' + escAttr((arr || []).join(', ')) + '"></div>';
        }

        function selectOptions(options, value) {
            var opts = options.slice();
            if (value != null && value !== '' && opts.indexOf(value) === -1) {
                opts.push(value);
            }
            return opts.map(function(o) {
                return '<option value="' + escAttr(o) + '"' + (o === value ? ' selected' : '') + '>' + escAttr(o) + '</option>';
            }).join('');
        }

        function selectInput(label, path, value, options, rr, extra) {
            return '<div class="input-group input-group-sm mb-1"><span class="input-group-text">' + escAttr(label) + '</span>' +
                '<select class="form-select" ' + pathAttr(path) + (rr ? ' data-rr="1"' : '') + (extra || '') + '>' + selectOptions(options, value) + '</select></div>';
        }

        function defaultDeadline(type) {
            if (type === 'fixedSeconds') return {type: type, seconds: 60};
            if (type === 'secondsToClock') return {type: type, hour: 12, minute: 0, ifPastAddDay: true};
            if (type === 'secondsToNextMinuteMark') return {type: type, mark: 15};
            if (type === 'secondsToHourBoundaryPlus') return {type: type, hours: 1};
            return {type: 'conditional', 'if': {type: 'always'}, then: {type: 'fixedSeconds', seconds: 60}, 'else': {type: 'fixedSeconds', seconds: 60}};
        }

        function defaultPredicate(type) {
            if (type === 'beforeClock') return {type: type, hour: 12, minute: 0};
            if (type === 'timerWithinMinuteWindow') return {type: type, timer: (def.timers[0] || ''), fromMinute: 0, toMinute: 15};
            return {type: 'always'};
        }

        function defaultAction(type) {
            if (type === 'sendSms' || type === 'notifyAdmins') return {type: type, template: ''};
            if (type === 'setTimer') return {type: type, timer: (def.timers[0] || ''), deadline: defaultDeadline('fixedSeconds')};
            if (type === 'generateSurveyToken') return {type: type, surveyKey: (surveyKeys()[0] || '')};
            if (type === 'llmQuestion') return {type: type, question: '', systemPrompt: '', validCategories: [], answerKey: '', staticAnswers: {}, linkTemplate: ''};
            if (type === 'sendResultSms') return {type: type, source: 'deviceResult', messages: {}};
            return {type: 'resetDeviceWindow'};
        }

        function renderPredicateEditor(predicate, path) {
            predicate = predicate || {type: 'always'};
            var html = '<div class="border rounded p-2 mb-1">';
            html += '<div class="input-group input-group-sm mb-1"><span class="input-group-text">If</span>' +
                '<select class="form-select" data-op="predicate-type" ' + pathAttr(path) + '>' + selectOptions(PREDICATE_TYPES, predicate.type) + '</select></div>';
            if (predicate.type === 'beforeClock') {
                html += numberInput('Hour', path.concat('hour'), predicate.hour);
                html += numberInput('Minute', path.concat('minute'), predicate.minute);
            } else if (predicate.type === 'timerWithinMinuteWindow') {
                html += selectInput('Timer', path.concat('timer'), predicate.timer, def.timers);
                html += numberInput('From Minute', path.concat('fromMinute'), predicate.fromMinute);
                html += numberInput('To Minute', path.concat('toMinute'), predicate.toMinute);
            }
            html += '</div>';
            return html;
        }

        function renderDeadlineEditor(label, deadline, path, allowConditional) {
            deadline = deadline || defaultDeadline('fixedSeconds');
            var types = allowConditional ? DEADLINE_TYPES : DEADLINE_TYPES.filter(function(t) { return t !== 'conditional'; });
            var html = '<div class="border rounded p-2 mb-1">';
            html += '<div class="input-group input-group-sm mb-1"><span class="input-group-text">' + escAttr(label) + '</span>' +
                '<select class="form-select" data-op="deadline-type" ' + pathAttr(path) + '>' + selectOptions(types, deadline.type) + '</select></div>';
            if (deadline.type === 'fixedSeconds') {
                html += numberInput('Seconds', path.concat('seconds'), deadline.seconds);
            } else if (deadline.type === 'secondsToClock') {
                html += numberInput('Hour', path.concat('hour'), deadline.hour);
                html += numberInput('Minute', path.concat('minute'), deadline.minute);
                html += checkboxInput('If past, add a day', path.concat('ifPastAddDay'), !!deadline.ifPastAddDay);
            } else if (deadline.type === 'secondsToNextMinuteMark') {
                html += numberInput('Minute Mark', path.concat('mark'), deadline.mark);
            } else if (deadline.type === 'secondsToHourBoundaryPlus') {
                html += numberInput('Hours', path.concat('hours'), deadline.hours);
            } else if (deadline.type === 'conditional') {
                html += renderPredicateEditor(deadline['if'], path.concat('if'));
                html += renderDeadlineEditor('Then', deadline.then, path.concat('then'), false);
                html += renderDeadlineEditor('Else', deadline['else'], path.concat('else'), false);
            }
            html += '</div>';
            return html;
        }

        function renderActionEditor(stateName, action, index) {
            var path = ['states', stateName, 'entry', index];
            var html = '<div class="card mb-2"><div class="card-header py-1 d-flex justify-content-between align-items-center">' +
                '<small class="fw-bold">' + escAttr(action.type) + '</small>' +
                '<button type="button" class="btn btn-outline-danger btn-xs" data-act="remove-action" data-state="' + escAttr(stateName) + '" data-idx="' + index + '"><i class="fas fa-times"></i></button>' +
                '</div><div class="card-body py-2">';
            if (action.type === 'sendSms' || action.type === 'notifyAdmins') {
                html += textareaInput('Template', path.concat('template'), action.template || '');
            } else if (action.type === 'setTimer') {
                html += selectInput('Timer', path.concat('timer'), action.timer || '', def.timers);
                html += renderDeadlineEditor('Deadline', action.deadline, path.concat('deadline'), true);
            } else if (action.type === 'generateSurveyToken') {
                var keys = surveyKeys();
                if (keys.length > 0) {
                    html += selectInput('Survey Key', path.concat('surveyKey'), action.surveyKey || '', keys);
                } else {
                    html += textInput('Survey Key', path.concat('surveyKey'), action.surveyKey || '');
                }
            } else if (action.type === 'llmQuestion') {
                html += textInput('Question', path.concat('question'), action.question || '');
                html += textareaInput('System Prompt', path.concat('systemPrompt'), action.systemPrompt || '', 3);
                html += csvInput('Valid Categories', path.concat('validCategories'), action.validCategories);
                html += textInput('Answer Key', path.concat('answerKey'), action.answerKey || '');
                html += jsonTextarea('Static Answers', path.concat('staticAnswers'), action.staticAnswers);
                html += textInput('Link Template', path.concat('linkTemplate'), action.linkTemplate || '');
            } else if (action.type === 'sendResultSms') {
                html += textInput('Source', path.concat('source'), action.source || '');
                html += jsonTextarea('Messages', path.concat('messages'), action.messages);
            } else {
                html += '<span class="text-muted small">No options for this action.</span>';
            }
            html += '</div></div>';
            return html;
        }

        function viaOptionsForState(state) {
            var opts = [];
            (state.transitions || []).forEach(function(t) {
                var via = null;
                if (t.event) via = t.event;
                else if (t.after) via = 'timeout:' + t.after;
                else if (t.auto) via = 'auto';
                if (via && opts.indexOf(via) === -1) opts.push(via);
            });
            return opts;
        }

        function renderTransitionRow(stateName, transition, index) {
            var path = ['states', stateName, 'transitions', index];
            var kind = transition.event != null ? 'event' : (transition.after != null ? 'after' : 'auto');
            var html = '<div class="row g-1 mb-1 align-items-center">';
            html += '<div class="col-3"><select class="form-select form-select-sm" data-op="trigger-type" ' + pathAttr(path) + '>' + selectOptions(['event', 'after', 'auto'], kind) + '</select></div>';
            html += '<div class="col-4">';
            if (kind === 'event') {
                html += '<input type="text" class="form-control form-control-sm" placeholder="eventName" ' + pathAttr(path.concat('event')) + ' data-rr="1" value="' + escAttr(transition.event) + '">';
            } else if (kind === 'after') {
                html += '<select class="form-select form-select-sm" ' + pathAttr(path.concat('after')) + ' data-rr="1">' + selectOptions(def.timers, transition.after) + '</select>';
            } else {
                html += '<span class="text-muted small">automatic</span>';
            }
            html += '</div>';
            html += '<div class="col-1 text-center"><i class="fas fa-arrow-right"></i></div>';
            html += '<div class="col-3"><select class="form-select form-select-sm" ' + pathAttr(path.concat('to')) + ' data-rr="1">' + selectOptions(stateNames(), transition.to) + '</select></div>';
            html += '<div class="col-1"><button type="button" class="btn btn-outline-danger btn-xs" data-act="remove-transition" data-state="' + escAttr(stateName) + '" data-idx="' + index + '"><i class="fas fa-times"></i></button></div>';
            html += '</div>';
            return html;
        }

        function renderMoveRow(stateName, move, index) {
            var path = ['states', stateName, 'manualMoves', index];
            var html = '<div class="row g-1 mb-1 align-items-center">';
            html += '<div class="col-5"><select class="form-select form-select-sm" ' + pathAttr(path.concat('to')) + ' data-rr="1">' + selectOptions(stateNames(), move.to) + '</select></div>';
            html += '<div class="col-1 text-center"><small>via</small></div>';
            html += '<div class="col-5"><select class="form-select form-select-sm" ' + pathAttr(path.concat('via')) + '>' + selectOptions(viaOptionsForState(def.states[stateName]), move.via) + '</select></div>';
            html += '<div class="col-1"><button type="button" class="btn btn-outline-danger btn-xs" data-act="remove-move" data-state="' + escAttr(stateName) + '" data-idx="' + index + '"><i class="fas fa-times"></i></button></div>';
            html += '</div>';
            return html;
        }

        function renderStatesTab() {
            $('#initial-state').html(selectOptions(stateNames(), def.initialState || ''));
            $('#initial-state').attr('data-path', JSON.stringify(['initialState'])).attr('data-rr', '1');
            var html = '';
            stateNames().forEach(function(name, i) {
                var state = def.states[name];
                var isOpen = openState === name;
                var badges = '';
                if (state.terminal) badges += ' <span class="badge bg-dark">terminal</span>';
                if (def.initialState === name) badges += ' <span class="badge bg-info text-dark">initial</span>';
                html += '<div class="accordion-item">';
                html += '<h2 class="accordion-header">' +
                    '<button class="accordion-button' + (isOpen ? '' : ' collapsed') + '" type="button" data-bs-toggle="collapse" data-bs-target="#state-body-' + i + '">' +
                    '<span>' + escAttr(name) + badges + '</span></button></h2>';
                html += '<div id="state-body-' + i + '" class="accordion-collapse collapse' + (isOpen ? ' show' : '') + '" data-state="' + escAttr(name) + '" data-bs-parent="#states-accordion">';
                html += '<div class="accordion-body">';
                html += '<div class="d-flex justify-content-between align-items-start mb-2">';
                html += '<div class="input-group input-group-sm me-2"><span class="input-group-text">Name</span>' +
                    '<input type="text" class="form-control" data-op="state-rename" data-old="' + escAttr(name) + '" value="' + escAttr(name) + '"></div>';
                html += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="remove-state" data-state="' + escAttr(name) + '"><i class="fas fa-trash"></i></button>';
                html += '</div>';
                html += checkboxInput('Terminal state', ['states', name, 'terminal'], !!state.terminal, true);
                html += '<hr class="my-2"><div class="fw-bold small mb-1">Entry Actions</div>';
                (state.entry || []).forEach(function(action, j) {
                    html += renderActionEditor(name, action, j);
                });
                html += '<div class="input-group input-group-sm add-action-row mb-2">' +
                    '<select class="form-select">' + selectOptions(ACTION_TYPES, 'sendSms') + '</select>' +
                    '<button type="button" class="btn btn-outline-success" data-act="add-action" data-state="' + escAttr(name) + '"><i class="fas fa-plus"></i> Action</button></div>';
                html += '<hr class="my-2"><div class="fw-bold small mb-1">Transitions</div>';
                (state.transitions || []).forEach(function(transition, j) {
                    html += renderTransitionRow(name, transition, j);
                });
                html += '<button type="button" class="btn btn-outline-success btn-xs mb-2" data-act="add-transition" data-state="' + escAttr(name) + '"><i class="fas fa-plus"></i> Transition</button>';
                html += '<hr class="my-2"><div class="fw-bold small mb-1">Manual Moves</div>';
                (state.manualMoves || []).forEach(function(move, j) {
                    html += renderMoveRow(name, move, j);
                });
                html += '<button type="button" class="btn btn-outline-success btn-xs" data-act="add-move" data-state="' + escAttr(name) + '"><i class="fas fa-plus"></i> Manual Move</button>';
                html += '</div></div></div>';
            });
            $('#states-accordion').html(html);
        }

        function renderTimersTab() {
            var html = '';
            (def.timers || []).forEach(function(timer, i) {
                html += '<div class="input-group input-group-sm mb-1">' +
                    '<span class="input-group-text"><i class="fas fa-clock"></i></span>' +
                    '<input type="text" class="form-control" data-op="timer-rename" data-idx="' + i + '" value="' + escAttr(timer) + '">' +
                    '<button type="button" class="btn btn-outline-danger" data-act="remove-timer" data-idx="' + i + '"><i class="fas fa-times"></i></button></div>';
            });
            if (html === '') {
                html = '<span class="text-muted small">No timers defined.</span>';
            }
            $('#timers-list').html(html);
        }

        function renderSurveysTab() {
            var html = '';
            (def.surveys || []).forEach(function(survey, i) {
                html += '<div class="card mb-2"><div class="card-header py-1 d-flex justify-content-between align-items-center">' +
                    '<small class="fw-bold">' + escAttr(survey.key || 'survey') + '</small>' +
                    '<button type="button" class="btn btn-outline-danger btn-xs" data-act="remove-survey" data-idx="' + i + '"><i class="fas fa-times"></i></button>' +
                    '</div><div class="card-body py-2">';
                html += textInput('Key', ['surveys', i, 'key'], survey.key || '', true);
                html += textInput('Title', ['surveys', i, 'title'], survey.title || '');
                html += '<div class="fw-bold small mb-1 mt-2">Questions</div>';
                (survey.questions || []).forEach(function(question, j) {
                    var qpath = ['surveys', i, 'questions', j];
                    html += '<div class="border rounded p-2 mb-2">';
                    html += '<div class="d-flex justify-content-between align-items-start">';
                    html += '<div class="w-100 me-2">';
                    html += textInput('ID', qpath.concat('id'), question.id || '');
                    html += selectInput('Type', qpath.concat('type'), question.type || 'select', QUESTION_TYPES, true);
                    html += textInput('Label', qpath.concat('label'), question.label || '');
                    if (question.type === 'select' || question.type === 'radio' || question.type === 'scale') {
                        html += csvInput('Options', qpath.concat('options'), question.options);
                    }
                    html += checkboxInput('Required', qpath.concat('required'), !!question.required);
                    html += '</div>';
                    html += '<button type="button" class="btn btn-outline-danger btn-xs" data-act="remove-question" data-sidx="' + i + '" data-qidx="' + j + '"><i class="fas fa-times"></i></button>';
                    html += '</div></div>';
                });
                html += '<button type="button" class="btn btn-outline-success btn-xs" data-act="add-question" data-idx="' + i + '"><i class="fas fa-plus"></i> Question</button>';
                html += '</div></div>';
            });
            if (html === '') {
                html = '<span class="text-muted small">No surveys defined.</span>';
            }
            $('#surveys-list').html(html);
        }

        function renderGraph() {
            window.renderProtocolGraph('#protocol-graph', window.definitionToDot(def, null));
        }

        function runLint() {
            var errors = [];
            var states = def.states || {};
            if (!def.initialState) {
                errors.push('No initial state is set.');
            } else if (!states[def.initialState]) {
                errors.push('Initial state "' + def.initialState + '" does not exist.');
            }
            Object.keys(states).forEach(function(name) {
                (states[name].transitions || []).forEach(function(t) {
                    if (!t.to || !states[t.to]) {
                        errors.push('State "' + name + '" has a transition to unknown state "' + (t.to || '') + '".');
                    }
                });
                (states[name].manualMoves || []).forEach(function(m) {
                    if (!m.to || !states[m.to]) {
                        errors.push('State "' + name + '" has a manual move to unknown state "' + (m.to || '') + '".');
                    }
                });
            });
            if (def.initialState && states[def.initialState]) {
                var visited = {};
                var queue = [def.initialState];
                while (queue.length > 0) {
                    var current = queue.shift();
                    if (visited[current]) continue;
                    visited[current] = true;
                    ((states[current] && states[current].transitions) || []).forEach(function(t) {
                        if (t.to && states[t.to] && !visited[t.to]) queue.push(t.to);
                    });
                }
                Object.keys(states).forEach(function(name) {
                    if (!visited[name]) {
                        errors.push('State "' + name + '" is unreachable from the initial state.');
                    }
                });
            }
            var html = '';
            errors.forEach(function(error) {
                html += '<li class="text-danger">' + escAttr(error) + '</li>';
            });
            if (html === '') {
                html = '<li class="text-success">All structure checks pass.</li>';
            }
            $('#lint-list').html(html);
            $('#btn-publish').prop('disabled', errors.length > 0 || meta.status !== 'draft');
            return errors;
        }

        function refresh() {
            renderGraph();
            runLint();
        }

        function renderAll() {
            renderStatesTab();
            renderTimersTab();
            renderSurveysTab();
            refresh();
        }

        function renameState(oldName, newName) {
            newName = (newName || '').trim();
            if (newName === '' || newName === oldName) {
                renderAll();
                return;
            }
            if (def.states[newName]) {
                showError('A state named "' + newName + '" already exists.');
                renderAll();
                return;
            }
            var newStates = {};
            Object.keys(def.states).forEach(function(k) {
                newStates[k === oldName ? newName : k] = def.states[k];
            });
            def.states = newStates;
            if (def.initialState === oldName) {
                def.initialState = newName;
            }
            Object.keys(def.states).forEach(function(k) {
                (def.states[k].transitions || []).forEach(function(t) { if (t.to === oldName) t.to = newName; });
                (def.states[k].manualMoves || []).forEach(function(m) { if (m.to === oldName) m.to = newName; });
            });
            if (openState === oldName) {
                openState = newName;
            }
            renderAll();
        }

        function renameTimerReferences(oldName, newName) {
            function walkDeadline(deadline) {
                if (!deadline) return;
                if (deadline.type === 'conditional') {
                    if (deadline['if'] && deadline['if'].timer === oldName) deadline['if'].timer = newName;
                    walkDeadline(deadline.then);
                    walkDeadline(deadline['else']);
                }
            }
            Object.keys(def.states || {}).forEach(function(k) {
                var state = def.states[k];
                (state.transitions || []).forEach(function(t) { if (t.after === oldName) t.after = newName; });
                (state.manualMoves || []).forEach(function(m) { if (m.via === 'timeout:' + oldName) m.via = 'timeout:' + newName; });
                (state.entry || []).forEach(function(a) {
                    if (a.type === 'setTimer') {
                        if (a.timer === oldName) a.timer = newName;
                        walkDeadline(a.deadline);
                    }
                });
            });
        }

        $('#builder').on('change', '[data-path]', function() {
            var $el = $(this);
            if ($el.attr('data-op')) return;
            var path = JSON.parse($el.attr('data-path'));
            var dtype = $el.attr('data-dtype') || 'text';
            var value;
            if (dtype === 'bool') {
                value = $el.is(':checked');
            } else if (dtype === 'int') {
                value = parseInt($el.val(), 10);
                if (isNaN(value)) value = 0;
            } else if (dtype === 'csv') {
                value = $el.val().split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
            } else if (dtype === 'json') {
                try {
                    value = JSON.parse($el.val() === '' ? '{}' : $el.val());
                } catch (e) {
                    showError('Invalid JSON: ' + e.message);
                    return;
                }
            } else {
                value = $el.val();
            }
            setByPath(def, path, value);
            if ($el.attr('data-rr') === '1') {
                renderAll();
            } else {
                refresh();
            }
        });

        $('#builder').on('change', '[data-op="deadline-type"]', function() {
            var path = JSON.parse($(this).attr('data-path'));
            var current = getByPath(def, path);
            var type = $(this).val();
            if (!current || current.type !== type) {
                setByPath(def, path, defaultDeadline(type));
            }
            renderAll();
        });

        $('#builder').on('change', '[data-op="predicate-type"]', function() {
            var path = JSON.parse($(this).attr('data-path'));
            var current = getByPath(def, path);
            var type = $(this).val();
            if (!current || current.type !== type) {
                setByPath(def, path, defaultPredicate(type));
            }
            renderAll();
        });

        $('#builder').on('change', '[data-op="trigger-type"]', function() {
            var path = JSON.parse($(this).attr('data-path'));
            var current = getByPath(def, path) || {};
            var kind = $(this).val();
            var replacement = {to: current.to || stateNames()[0] || ''};
            if (kind === 'event') replacement.event = current.event || '';
            else if (kind === 'after') replacement.after = current.after || (def.timers[0] || '');
            else replacement.auto = true;
            setByPath(def, path, replacement);
            renderAll();
        });

        $('#builder').on('change', '[data-op="state-rename"]', function() {
            renameState($(this).attr('data-old'), $(this).val());
        });

        $('#builder').on('change', '[data-op="timer-rename"]', function() {
            var idx = parseInt($(this).attr('data-idx'), 10);
            var newName = $(this).val().trim();
            var oldName = def.timers[idx];
            if (newName === '' || newName === oldName) {
                renderAll();
                return;
            }
            if (def.timers.indexOf(newName) !== -1) {
                showError('A timer named "' + newName + '" already exists.');
                renderAll();
                return;
            }
            def.timers[idx] = newName;
            renameTimerReferences(oldName, newName);
            renderAll();
        });

        $('#builder').on('click', '[data-act]', function() {
            var $el = $(this);
            var act = $el.attr('data-act');
            var stateName = $el.attr('data-state');
            var idx = parseInt($el.attr('data-idx'), 10);
            if (act === 'add-state') {
                var name = $('#new-state-name').val().trim();
                if (name === '') {
                    showError('Please enter a state name.');
                    return;
                }
                if (def.states[name]) {
                    showError('A state named "' + name + '" already exists.');
                    return;
                }
                def.states[name] = {transitions: [], manualMoves: []};
                $('#new-state-name').val('');
                openState = name;
                renderAll();
            } else if (act === 'remove-state') {
                delete def.states[stateName];
                if (openState === stateName) openState = null;
                renderAll();
            } else if (act === 'add-action') {
                var type = $el.closest('.add-action-row').find('select').val();
                if (!def.states[stateName].entry) def.states[stateName].entry = [];
                def.states[stateName].entry.push(defaultAction(type));
                renderAll();
            } else if (act === 'remove-action') {
                def.states[stateName].entry.splice(idx, 1);
                renderAll();
            } else if (act === 'add-transition') {
                if (!def.states[stateName].transitions) def.states[stateName].transitions = [];
                def.states[stateName].transitions.push({event: '', to: stateNames()[0] || ''});
                renderAll();
            } else if (act === 'remove-transition') {
                def.states[stateName].transitions.splice(idx, 1);
                renderAll();
            } else if (act === 'add-move') {
                if (!def.states[stateName].manualMoves) def.states[stateName].manualMoves = [];
                def.states[stateName].manualMoves.push({to: stateNames()[0] || '', via: viaOptionsForState(def.states[stateName])[0] || ''});
                renderAll();
            } else if (act === 'remove-move') {
                def.states[stateName].manualMoves.splice(idx, 1);
                renderAll();
            } else if (act === 'add-timer') {
                var timerName = $('#new-timer-name').val().trim();
                if (timerName === '') {
                    showError('Please enter a timer name.');
                    return;
                }
                if (def.timers.indexOf(timerName) !== -1) {
                    showError('A timer named "' + timerName + '" already exists.');
                    return;
                }
                def.timers.push(timerName);
                $('#new-timer-name').val('');
                renderAll();
            } else if (act === 'remove-timer') {
                def.timers.splice(idx, 1);
                renderAll();
            } else if (act === 'add-survey') {
                def.surveys.push({key: 'survey_' + (def.surveys.length + 1), title: '', questions: []});
                renderAll();
            } else if (act === 'remove-survey') {
                def.surveys.splice(idx, 1);
                renderAll();
            } else if (act === 'add-question') {
                if (!def.surveys[idx].questions) def.surveys[idx].questions = [];
                def.surveys[idx].questions.push({id: '', type: 'select', label: '', options: [], required: true});
                renderAll();
            } else if (act === 'remove-question') {
                var sidx = parseInt($el.attr('data-sidx'), 10);
                var qidx = parseInt($el.attr('data-qidx'), 10);
                def.surveys[sidx].questions.splice(qidx, 1);
                renderAll();
            }
        });

        $('#states-accordion').on('shown.bs.collapse', '.accordion-collapse', function() {
            openState = $(this).attr('data-state');
        });
        $('#states-accordion').on('hidden.bs.collapse', '.accordion-collapse', function() {
            if (openState === $(this).attr('data-state')) openState = null;
        });

        function save_draft(callback) {
            $.post({
                url: '<?= $rootURL ?>/protocols/save-draft',
                data: {'definition_uuid': definitionUuid, 'definition': JSON.stringify(def)},
                dataType: 'json'
            }).done(function(data) {
                if (data.success) {
                    if (typeof callback === 'function') {
                        callback();
                    } else {
                        showSuccess('Draft saved.');
                    }
                } else {
                    showError(data.error_message);
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function show_validation_results(ok, errors) {
            var html = '';
            if (ok) {
                html = '<li class="text-success">The definition is valid.</li>';
            } else {
                (errors || []).forEach(function(error) {
                    html += '<li class="text-danger">' + escAttr(typeof error === 'string' ? error : JSON.stringify(error)) + '</li>';
                });
                if (html === '') {
                    html = '<li class="text-danger">The definition failed validation.</li>';
                }
            }
            $('#validate-list').html(html);
            $('#validate-card').removeClass('d-none');
        }

        function validate_definition() {
            $.post({
                url: '<?= $rootURL ?>/protocols/validate',
                data: {'definition': JSON.stringify(def)},
                dataType: 'json'
            }).done(function(data) {
                show_validation_results(data.ok, data.errors);
                if (data.ok) {
                    showSuccess('The definition is valid.');
                } else {
                    showError('The definition failed validation.');
                }
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        }

        function confirm_publish() {
            save_draft(function() {
                $.post({
                    url: '<?= $rootURL ?>/protocols/publish',
                    data: {'definition_uuid': definitionUuid},
                    dataType: 'json'
                }).done(function(data) {
                    publishModal.modal('hide');
                    if (data.success) {
                        showSuccess('Protocol published.');
                        window.location.href = '<?= $rootURL ?>/protocols';
                    } else {
                        show_validation_results(false, data.errors);
                        showError(data.error_message);
                    }
                }).fail(function() {
                    publishModal.modal('hide');
                    showError('Error communicating with the server.');
                });
            });
        }

        $(function() {
            let importWarnings = sessionStorage.getItem('protocol-import-warnings');
            if (importWarnings) {
                sessionStorage.removeItem('protocol-import-warnings');
                try {
                    let list = JSON.parse(importWarnings);
                    if (list.length > 0) {
                        let alert = $('<div class="alert alert-warning alert-dismissible fade show" role="alert">' +
                            '<strong>Imported with warnings:</strong><ul class="mb-0"></ul>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                        list.forEach(function(w) { $('<li>').text(w).appendTo(alert.find('ul')); });
                        $('main').prepend(alert);
                    }
                } catch (e) {}
            }
            if (definitionUuid === '') {
                showError('No protocol definition provided.');
                return;
            }
            $.ajax({
                url: '<?= $rootURL ?>/protocols/get',
                type: 'GET',
                data: {'definition': definitionUuid},
                dataType: 'json'
            }).done(function(data) {
                if (!data.success) {
                    showError(data.error_message);
                    return;
                }
                def = data.protocol.definition || {};
                if (!def.schemaVersion) def.schemaVersion = 1;
                if (!def.states) def.states = {};
                if (!def.timers) def.timers = [];
                if (!def.surveys) def.surveys = [];
                if (!def.settings) def.settings = {endProtocolEvent: 'receivedEndProtocol', collection: 'none'};
                meta = {name: data.protocol.name, version: data.protocol.version, status: data.protocol.status};
                $('#protocol-title').text('Edit Draft: ' + meta.name + ' v' + meta.version);
                $('#publish-label').text(meta.name + ' v' + meta.version);
                if (meta.status !== 'draft') {
                    $('#not-draft-warning').removeClass('d-none');
                    $('#btn-save').prop('disabled', true);
                    $('#btn-publish').prop('disabled', true);
                }
                renderAll();
            }).fail(function() {
                showError('Error communicating with the server.');
            });
        });
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
