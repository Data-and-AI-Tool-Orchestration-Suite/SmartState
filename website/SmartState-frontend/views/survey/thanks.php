<?php
/** @var UserSession $userSession */
$page = 'thankyou-survey';
include_once __DIR__ . '/../_header_no_sidebar.php';
$contactPhone = is_array($contact ?? null) ? ($contact['contact_phone'] ?? null) : null;
$contactEmail = is_array($contact ?? null) ? ($contact['contact_email'] ?? null) : null;
$studyName = is_array($contact ?? null) ? ($contact['name'] ?? null) : null;
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4 mr-auto p-2">Survey - <span class="text-muted">Thank You</span></h1>
    </div>
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-center">Thank you for taking the survey.</h1>
            <br>
            <h4 class="text-center">Your responses have been recorded. You can close this window now.</h4>
            <br>
<?php if (!empty($contactPhone) || !empty($contactEmail)): ?>
            <h4 class="text-center">If you need any assistance, please contact the <?= htmlspecialchars($studyName ?: 'study') ?> team.</h4>
            <br>
            <p class="text-center">
<?php if (!empty($contactPhone)): ?>
                <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>" class="btn btn-primary"><i class="fa fa-phone"></i> <?= htmlspecialchars($contactPhone) ?></a>
<?php endif; ?>
<?php if (!empty($contactEmail)): ?>
                <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" class="btn btn-primary"><i class="fa fa-envelope"></i> <?= htmlspecialchars($contactEmail) ?></a>
<?php endif; ?>
            </p>
<?php else: ?>
            <h4 class="text-center">If you need any assistance, please contact your study administrator.</h4>
<?php endif; ?>
        </div>
    </div>


<?php
include_once __DIR__ . '/../_footer.php';
