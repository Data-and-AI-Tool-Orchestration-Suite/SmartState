<?php
/** @var array $question */
$qid = $question['id'] ?? '';
$qtype = $question['type'] ?? 'textarea';
$qlabel = $question['label'] ?? '';
$qoptions = $question['options'] ?? [];
$qrequired = !empty($question['required']);
$qmin = $question['min'] ?? 1;
$qmax = $question['max'] ?? 10;
$qplaceholder = $question['placeholder'] ?? '';
if ($qid === '')
    return;
?>
<?php if ($qtype === 'select'): ?>
    <div class="form-group mb-2">
        <label for="<?= htmlspecialchars($qid) ?>"><?= htmlspecialchars($qlabel) ?><?php if ($qrequired): ?> <span class="text-danger">*</span><?php endif; ?></label>
        <select class="selectpicker form-control survey-question" id="<?= htmlspecialchars($qid) ?>" data-width="100%" title="Select an option">
            <?php foreach ($qoptions as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
<?php elseif ($qtype === 'radio'): ?>
    <div class="form-group mb-2">
        <label><?= htmlspecialchars($qlabel) ?><?php if ($qrequired): ?> <span class="text-danger">*</span><?php endif; ?></label>
        <?php foreach ($qoptions as $idx => $option):
            $optionLabel = $option;
            if (strpos($option, $qid . '-') === 0)
                $optionLabel = ucfirst(substr($option, strlen($qid) + 1));
        ?>
            <div class="form-check">
                <input class="form-check-input survey-question" type="radio" name="<?= htmlspecialchars($qid) ?>" id="<?= htmlspecialchars($qid . '-opt' . $idx) ?>" value="<?= htmlspecialchars($option) ?>">
                <label class="form-check-label" for="<?= htmlspecialchars($qid . '-opt' . $idx) ?>"><?= htmlspecialchars($optionLabel) ?></label>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($qtype === 'textarea'): ?>
    <div class="form-group mb-2">
        <label for="<?= htmlspecialchars($qid) ?>"><?= htmlspecialchars($qlabel) ?><?php if ($qrequired): ?> <span class="text-danger">*</span><?php endif; ?></label>
        <textarea class="form-control survey-question" id="<?= htmlspecialchars($qid) ?>" rows="3" placeholder="<?= htmlspecialchars($qplaceholder !== '' ? $qplaceholder : 'Enter your answer') ?>"></textarea>
    </div>
<?php elseif ($qtype === 'number'): ?>
    <div class="form-group mb-2">
        <label for="<?= htmlspecialchars($qid) ?>"><?= htmlspecialchars($qlabel) ?><?php if ($qrequired): ?> <span class="text-danger">*</span><?php endif; ?></label>
        <input type="number" class="form-control survey-question" id="<?= htmlspecialchars($qid) ?>"<?php if (isset($question['min'])): ?> min="<?= htmlspecialchars($question['min']) ?>"<?php endif; ?><?php if (isset($question['max'])): ?> max="<?= htmlspecialchars($question['max']) ?>"<?php endif; ?> placeholder="<?= htmlspecialchars($qplaceholder !== '' ? $qplaceholder : 'Enter a number') ?>">
    </div>
<?php elseif ($qtype === 'scale'): ?>
    <div class="form-group mb-2">
        <label for="<?= htmlspecialchars($qid) ?>"><?= htmlspecialchars($qlabel) ?><?php if ($qrequired): ?> <span class="text-danger">*</span><?php endif; ?></label>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted"><?= htmlspecialchars($qmin) ?></span>
            <input type="range" class="form-range survey-question" id="<?= htmlspecialchars($qid) ?>" min="<?= htmlspecialchars($qmin) ?>" max="<?= htmlspecialchars($qmax) ?>" step="1" value="<?= htmlspecialchars($qmin) ?>" oninput="document.getElementById('<?= htmlspecialchars($qid) ?>-value').textContent = this.value;">
            <span class="text-muted"><?= htmlspecialchars($qmax) ?></span>
            <span class="badge bg-primary" id="<?= htmlspecialchars($qid) ?>-value"><?= htmlspecialchars($qmin) ?></span>
        </div>
    </div>
<?php endif; ?>
