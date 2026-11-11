function buildToastContent(msg, title) {
    const content = document.createElement('div');
    content.style.display = 'flex'; // Apply flexbox to align elements
    content.style.justifyContent = 'space-between'; // Space between content and close button
    content.style.alignItems = 'center'; // Align items vertically
    const inner = document.createElement('div');
    const heading = document.createElement('h6');
    heading.className = 'text-white m-0';
    heading.textContent = title;
    const text = document.createElement('span');
    text.className = 'text-white';
    text.textContent = msg;
    inner.appendChild(heading);
    inner.appendChild(text);
    content.appendChild(inner);
    return content;
}

function showNotification(msg, title) {
    const content = buildToastContent(msg, title);

    Toastify({
        node: content,
        style: {
            background: '#0dcaf0',
            display: "flex",
        },
        close: true
    }).showToast();
}

function showSuccess(msg, title = 'Success') {
    const content = buildToastContent(msg, title);

    Toastify({
        node: content,
        style: {
            background: '#28a745',
            display: "flex",
        },
        close: true
    }).showToast();
}


function showWarning(msg, title = 'Warning') {
    const content = buildToastContent(msg, title);

    Toastify({
        node: content,
        style: {
            background: '#ffc107',
            display: "flex",
        },
        close: true
    }).showToast();
}

function showError(msg, title = 'Error') {
    const content = buildToastContent(msg, title);

    Toastify({
        node: content,
        style: {
            background: '#dc3545',
            display: "flex",
        },
        close: true
    }).showToast();
}

var confirmModal = $('#confirmModal');
var confirmModalTitle = $('#confirmModalLabel');
var confirmModalInternalId = $('#confirmModalInternalId');
var confirmModalTextSpan = $('#confirmModalTextSpan');
var confirmModalButton = $('#confirmModalConfirmButton');

confirmModal.on('hidden.bs.modal', function() {
    confirmModalTitle.html('');
    confirmModalInternalId.val('');
    confirmModalTextSpan.html('');
});


function showConfirmModal(){
    $('#confirmModal').modal("show");
}
function hideConfirmModal(){
    $('#confirmModal').modal("hide");
}

function formatUtcTimestamp(ts) {
    if (!ts) {
        return '';
    }
    var normalized = String(ts).replace(' ', 'T');
    if (!/[Zz]$|[+-]\d{2}:?\d{2}$/.test(normalized)) {
        normalized += 'Z';
    }
    var date = new Date(normalized);
    if (isNaN(date.getTime())) {
        return String(ts);
    }
    return date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

function emptyStateHtml(icon, title, hint) {
    return '<div class="ss-empty-state">' +
        '<div class="ss-empty-state-icon"><i class="fa ' + icon + '"></i></div>' +
        '<div class="ss-empty-state-title">' + title + '</div>' +
        '<div class="ss-empty-state-hint">' + hint + '</div>' +
        '</div>';
}