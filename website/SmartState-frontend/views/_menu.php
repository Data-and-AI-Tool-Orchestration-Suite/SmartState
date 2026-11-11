<?php
/** @var User $user */
/** @var string $page */
global $rootURL;
require_once MODELS_DIR . 'Study.php';
$menuStudies = [];
$currentStudyName = null;
if (isset($user) && $user->getId() != "") {
    if ($user->isSuperAdmin()) {
        foreach (Study::allForSelect() as $menuStudy) {
            $menuStudies[$menuStudy['study_uuid']] = $menuStudy['name'];
        }
    } else {
        foreach ($user->getMemberships() as $membership) {
            $menuStudies[$membership['study_uuid']] = $membership['study_name'];
        }
    }
    $menuCurrentStudy = $user->getCurrentStudy();
    if (!is_null($menuCurrentStudy) && isset($menuStudies[$menuCurrentStudy])) {
        $currentStudyName = $menuStudies[$menuCurrentStudy];
    }
}
?>
<div class="offcanvas-lg offcanvas-end nav-drawer" tabindex="-1" id="navbarSupportedContent" aria-labelledby="navDrawerLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="navDrawerLabel">Menu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#navbarSupportedContent" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
    <ul class="navbar-nav dflex">
        <!-- Menu Items -->
        <!-- Home page -->
        <li class="nav-item">
            <a class="nav-link <?= $page=='home'?'active':'' ?>" href="<?= $rootURL ?>/">Dashboard</a>
        </li>
        <!-- /Home page -->
        <!-- Messaging Dropdown -->
        <li class="nav-item dropdown">
            <a class="nav-link <?= $page=='messages-index'||$page=='messages-log'?'active':''?>" href="#" id="messageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                Messaging
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="messageDropdown">
                <li><a class="dropdown-item <?= $page=='messages-index'?'active':''?>" href="<?= $rootURL ?>/messages">All Messages</a></li>
                <li><a class="dropdown-item <?= $page=='messages-log'?'active':''?>" href="<?= $rootURL ?>/messages/log">Participant Message Log</a></li>
            </ul>
        </li>
        <!-- /Dropdown -->

        <!-- Survey link -->
        <li class="nav-item">
            <a class="nav-link <?= $page=='survey-view'?'active':''?>" aria-current="false" href="<?= $rootURL ?>/survey/view">Surveys</a>
        </li>
        <!-- /Survey link -->

        <!-- Participant Dropdown -->
        <li class="nav-item dropdown">
            <a class="nav-link <?= $page=='participants-index'||$page=='participants-state'?'active':''?>" href="#" id="participantDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                Participants
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="participantDropdown">
                <li><a class="dropdown-item <?= $page=='participants-index'?'active':''?>" href="<?= $rootURL ?>/participants">Management</a></li>
                <li><a class="dropdown-item <?= $page=='participants-state'?'active':''?>" href="<?= $rootURL ?>/participants/state-log">Audit Log</a></li>
            </ul>
        </li>
        <!-- /Dropdown -->

        <li class="nav-item">
            <a class="nav-link <?= $page=='protocols-index'?'active':''?>" aria-current="false" href="<?= $rootURL ?>/protocols">Protocols</a>
        </li>

        <?php if (isset($user) && ($user->hasRole("Study Admin") || $user->hasRole("Super Admin"))) : ?>
            <li class="nav-item">
                <a class="nav-link <?= $page=='users'?'active':''?>" aria-current="false" href="<?= $rootURL ?>/users">Users</a>
            </li>
        <?php endif; ?>

        <?php if (isset($user) && $user->isSuperAdmin()) : ?>
            <li class="nav-item">
                <a class="nav-link <?= $page=='studies'?'active':''?>" aria-current="false" href="<?= $rootURL ?>/studies">Studies</a>
            </li>
        <?php endif; ?>

        <?php if (isset($user) && $user->getId() != ""): ?>
            <li class="nav-item dropdown">
                <a class="nav-link" href="#" id="studySwitcherDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-flask"></i> <?= htmlspecialchars($currentStudyName ?? 'Select Study') ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="studySwitcherDropdown">
                    <?php if (count($menuStudies) == 0) : ?>
                        <li><span class="dropdown-item disabled">No studies available</span></li>
                    <?php else : ?>
                        <?php foreach ($menuStudies as $menuStudyUuid => $menuStudyName) : ?>
                            <li>
                                <a class="dropdown-item study-switch-item <?= $menuStudyUuid == $user->getCurrentStudy() ? 'active' : '' ?>" href="#" data-study-uuid="<?= htmlspecialchars($menuStudyUuid) ?>">
                                    <?= htmlspecialchars($menuStudyName) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </li>
            <script type="text/javascript">
                $(function() {
                    $('.study-switch-item').on('click', function(e) {
                        e.preventDefault();
                        let studyUuid = $(this).data('study-uuid');
                        $.post({
                            url: '<?= $rootURL ?>/study/select',
                            data: {'study_uuid': studyUuid},
                            dataType: 'json'
                        }).done(function(data) {
                            if (data.status === 'ok') {
                                location.reload();
                            } else if (typeof showError === 'function') {
                                showError(data.error_message);
                            }
                        }).fail(function() {
                            if (typeof showError === 'function') {
                                showError('Could not switch study.');
                            }
                        });
                    });
                });
            </script>
        <?php endif; ?>

        <!-- My Acct Dropdown -->
        <?php if ($user->getId() != ""): ?>
            <li class="nav-item dropdown ">
                <a class="nav-link" href="#" id="myAcctDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-regular fa-circle-user"></i><span class="d-lg-none ms-2">Account</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end acct-menu" aria-labelledby="myAcctDropdown">
                    <?php if (!is_null($user)) : ?>
                        <?php if (is_null($user->getFullName()) || empty($user->getFullName())) : ?>
                            <li class="nav-item"><p class="fw-bold"><?php echo $user->getEPPN(); ?></p></li>
                        <?php else : ?>
                            <li class="nav-item"><p class="fw-bold"><?php echo $user->getFullName(); ?></p></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= $rootURL ?>/logout">Logout</a></li>
                </ul>
            </li>
        <?php endif; ?>
        <!-- /My Acct Dropdown -->
    </ul> <!-- /Menu Items -->
    </div>
</div>
