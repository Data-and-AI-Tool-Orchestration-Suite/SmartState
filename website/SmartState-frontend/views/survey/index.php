<?php
/** @var UserSession $userSession */
$page = 'survey';
global $rootURL;
$surveyTitle = !empty($surveyKey) ? ucwords(str_replace(['_', '-'], ' ', $surveyKey)) : 'Your Responses Matter';
include_once __DIR__ . '/../_header_no_sidebar.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Survey - <span class="text-muted"><?= htmlspecialchars($surveyTitle) ?></span></h1>
    </div>

    <div class="row">
        <div class="offset-md-1 col-md-10">
<?php if (empty($questions)): ?>
            <h4 class="text-center">There is nothing we need to collect from you right now.</h4>
            <br>
            <p class="text-center">Thank you! You can close this window now.</p>
<?php else: ?>
            <form id="survey-form">
                <?php foreach ($questions as $question): ?>
                    <?php include __DIR__ . '/_questions.php'; ?>
                <?php endforeach; ?>
                <button id="finished-btn" type="submit" class="btn btn-primary">Submit</button>
            </form>
<?php endif; ?>
        </div>
    </div>

<?php if (!empty($questions)): ?>
    <script type="text/javascript">
        var token = <?= json_encode($token) ?>;
        var surveyQuestions = <?= json_encode(array_values(array_map(function($q) {
            return [
                'id' => $q['id'] ?? '',
                'type' => $q['type'] ?? 'textarea',
                'required' => !empty($q['required'])
            ];
        }, $questions))) ?>;

        $('#finished-btn').click(function(event) {
            event.preventDefault();

            let answers = {};
            let missing = false;
            surveyQuestions.forEach(function(question) {
                if (!question.id) {
                    return;
                }
                let value;
                if (question.type === 'radio') {
                    value = $('input[name="' + question.id + '"]:checked').val();
                } else {
                    value = $('#' + question.id).val();
                }
                if (typeof value === 'string') {
                    value = value.trim();
                }
                answers[question.id] = value;
                if (question.required && !value) {
                    missing = true;
                }
            });

            if (missing) {
                showError("Please answer all questions before submitting.");
                return;
            }

            $.ajax({
                url: '<?= $rootURL ?>/survey/done',
                type: 'POST',
                data: {
                    'answers': answers,
                    'token': token
                },
                success: function(data) {
                    if (data.success) {
                        window.location.href = '<?= $rootURL ?>/survey/thank-you?token=' + encodeURIComponent(token);
                    } else {
                        showError(data.error_message);
                        return null;
                    }
                },
                error: function (xhr, status, error) {
                    console.error(xhr.responseText);
                    console.error(status);
                    console.error(error);
                    showError("Error communicating with the server.");
                    return null;
                }
            });
        });
    </script>
<?php endif; ?>

<?php
include_once __DIR__ . '/../_footer.php';
