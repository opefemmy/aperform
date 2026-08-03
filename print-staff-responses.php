<?php
/**
 * Print Staff Self-Evaluation Responses
 * Renders every question the staff answered (or skipped) in their first-stage
 * self-evaluation along with the actual data they filled in for each question
 * type (rating, scale, yes/no, true/false, single_choice, multiple_choice,
 * short_answer, long_answer, file_upload).
 *
 * Source of truth: `evaluations.responses` JSON keyed by question id,
 * matched against `evaluation_questions` rows filtered by the staff's category.
 *
 * NOTE: This page is intentionally separate from print-summary.php so the
 * existing "Print Summary (Evidence of Participation)" page stays untouched.
 */
require_once 'config.php';
startSession();

// Check if evaluation ID is provided
$evalId = $_GET['id'] ?? 0;
if (!$evalId) {
    die('Evaluation ID required');
}

$pdo = getDBConnection();

// Get evaluation with staff details
$stmt = $pdo->prepare("SELECT e.*, s.staff_id AS staff_identifier, s.surname, s.first_name,
        s.department, s.faculty, s.designation, s.grade_level, s.staff_category
    FROM evaluations e
    LEFT JOIN staff s ON e.staff_id = s.id
    WHERE e.id = ?");
$stmt->execute([$evalId]);
$eval = $stmt->fetch();

if (!$eval) {
    die('Evaluation not found. ID: ' . htmlspecialchars($evalId));
}

// Access control: staff can only print their own; admins can print any
if (!isAdminLoggedIn() && !isStaffLoggedIn()) {
    redirect(SITE_URL . '/unified-login.php');
}
if (isStaffLoggedIn() && $_SESSION['staff_id'] != $eval['staff_id']) {
    die('Access denied: You can only print your own evaluation');
}

// Decode stored responses (id => answer)
$staffResponses = [];
if (isset($eval['responses']) && !empty($eval['responses'])) {
    $staffResponses = is_array($eval['responses'])
        ? $eval['responses']
        : json_decode($eval['responses'], true);
}
if (!is_array($staffResponses)) {
    $staffResponses = [];
}

$staffCategory = $eval['staff_category'] ?: 'academic';

// Settings for header
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$instName    = $settings['institution_name'] ?? 'Institution';
$instAddress = $settings['institution_address'] ?? '';
$logo        = $settings['institution_logo'] ?? '';

// Academic session
$stmt = $pdo->prepare("SELECT * FROM academic_sessions WHERE id = ?");
$stmt->execute([$eval['academic_session_id']]);
$session = $stmt->fetch();

// Load all active questions targeted at this staff's category (plus 'both')
$stmt = $pdo->prepare("SELECT * FROM evaluation_questions
    WHERE is_active = 1
      AND (target_staff_category = ? OR target_staff_category = 'both')
    ORDER BY COALESCE(category_order, 99999),
             COALESCE(question_order, 99999),
             category, id");
$stmt->execute([$staffCategory]);
$questions = $stmt->fetchAll();

// Group questions by category, preserving order
$byCategory = [];
foreach ($questions as $q) {
    $cat = $q['category'] ?: 'General';
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = [];
    }
    $byCategory[$cat][] = $q;
}

/**
 * Render the staff's answer for a single question according to its type.
 */
function renderAnswer($q, $answer) {
    $type = $q['question_type'];

    if ($answer === null || $answer === '' || $answer === []) {
        return '<span class="answer-empty">— No response —</span>';
    }

    switch ($type) {
        case 'rating':
        case 'scale':
            $score = intval($answer);
            $labels = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];
            $label  = $labels[$score] ?? 'N/A';
            return '<span class="badge bg-success" style="font-size:1rem;">' . $score . '/5 (' . htmlspecialchars($label) . ')</span>';

        case 'yes_no':
            $val = is_string($answer) ? strtolower(trim($answer)) : $answer;
            $isYes = in_array($val, ['yes', '1', 1, 'true', true], true);
            $cls   = $isYes ? 'bg-success' : 'bg-secondary';
            $txt   = $isYes ? 'Yes' : 'No';
            return '<span class="badge ' . $cls . '" style="font-size:1rem;">' . $txt . '</span>';

        case 'true_false':
            $val = is_string($answer) ? strtolower(trim($answer)) : $answer;
            $isTrue = in_array($val, ['true', '1', 1], true);
            $cls    = $isTrue ? 'bg-success' : 'bg-secondary';
            $txt    = $isTrue ? 'True' : 'False';
            return '<span class="badge ' . $cls . '" style="font-size:1rem;">' . $txt . '</span>';

        case 'single_choice':
            // options is text like "1=Poor,2=Fair,3=Good,4=Very Good,5=Excellent"
            $options = [];
            if (!empty($q['options'])) {
                foreach (explode(',', $q['options']) as $opt) {
                    $parts = explode('=', $opt, 2);
                    if (count($parts) === 2) {
                        $options[trim($parts[0])] = trim($parts[1]);
                    } else {
                        $options[] = trim($opt);
                    }
                }
            }
            $display = isset($options[$answer]) ? $options[$answer] : $answer;
            return '<span class="answer-text">' . htmlspecialchars((string)$display) . '</span>';

        case 'multiple_choice':
            if (!is_array($answer)) {
                $answer = [$answer];
            }
            $options = [];
            if (!empty($q['options'])) {
                foreach (explode(',', $q['options']) as $opt) {
                    $parts = explode('=', $opt, 2);
                    if (count($parts) === 2) {
                        $options[trim($parts[0])] = trim($parts[1]);
                    } else {
                        $options[] = trim($opt);
                    }
                }
            }
            $items = [];
            foreach ($answer as $a) {
                $items[] = htmlspecialchars($options[$a] ?? $a);
            }
            return '<ul class="answer-list"><li>' . implode('</li><li>', $items) . '</li></ul>';

        case 'short_answer':
        case 'long_answer':
            return '<div class="answer-text" style="white-space:pre-wrap;">' . nl2br(htmlspecialchars((string)$answer)) . '</div>';

        case 'file_upload':
            $path = (string)$answer;
            $exists = $path !== '' && file_exists($path);
            $name  = $exists ? basename($path) : $path;
            if ($exists) {
                return '<a href="' . htmlspecialchars($path) . '" target="_blank" rel="noopener">'
                     . '<i class="fas fa-paperclip me-1"></i>' . htmlspecialchars($name) . '</a>'
                     . ' <small class="text-muted">(' . htmlspecialchars($path) . ')</small>';
            }
            return '<span class="answer-text">' . htmlspecialchars($path) . '</span>';

        default:
            return '<span class="answer-text">' . htmlspecialchars((string)$answer) . '</span>';
    }
}

function categoryLabel($cat) {
    $labels = [
        'Teaching'         => 'Teaching Performance',
        'Research'         => 'Research Performance',
        'Administrative'   => 'Administrative Duties',
        'Admin'            => 'Administrative Duties',
        'Community'        => 'Community Service',
        'Professional'     => 'Professional Development',
        'Leadership'       => 'Leadership',
        'Curriculum'       => 'Curriculum',
        'Staff Development'=> 'Staff Development',
        'Meetings'         => 'Meetings',
        'Records'          => 'Records',
        'Timetable'        => 'Timetable',
    ];
    return $labels[$cat] ?? ucwords(str_replace('_', ' ', $cat));
}

// Stats
$answered = 0;
$totalQs  = count($questions);
foreach ($questions as $q) {
    $a = $staffResponses[$q['id']] ?? null;
    if ($a !== null && $a !== '' && $a !== []) {
        $answered++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Self-Evaluation Responses - <?php echo htmlspecialchars($instName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="theme-overrides.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; background: white !important; }
            .container { max-width: 100% !important; }
            .watermark, .background-logo { opacity: 0.06 !important; }
            .question-section { page-break-inside: avoid; }
        }
        body { background: white; padding: 20px; position: relative; font-family: 'Poppins', sans-serif; }
        .background-logo {
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 80%; max-width: 600px; height: auto;
            opacity: 0.10; z-index: 0; pointer-events: none;
        }
        .watermark {
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 400px; height: 400px;
            opacity: 0.08; z-index: 0; pointer-events: none;
        }
        .watermark img { width: 100%; height: 100%; object-fit: contain; }
        .print-header {
            text-align: center; border-bottom: 3px solid #247d57;
            padding-bottom: 15px; margin-bottom: 20px;
            position: relative; z-index: 1;
        }
        .print-header img.logo-img { max-height: 70px; margin-bottom: 10px; }
        .staff-info {
            background: #f8f9fa; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; border-left: 4px solid #247d57;
            position: relative; z-index: 1;
        }
        .meta-strip {
            background: linear-gradient(135deg, #247d57, #1a5238);
            color: white; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; position: relative; z-index: 1;
        }
        .meta-strip .pill {
            background: rgba(255,255,255,0.18);
            padding: 6px 12px; border-radius: 20px;
            display: inline-block; margin-right: 8px; font-size: 0.9rem;
        }
        .question-section {
            margin-bottom: 20px; position: relative; z-index: 1;
            background: #fff; border: 1px solid #e5e7eb;
            border-radius: 8px; padding: 15px 18px;
        }
        .question-section h5 {
            border-bottom: 2px solid #247d57; padding-bottom: 8px;
            color: #247d57; font-weight: bold; margin-bottom: 12px;
        }
        .qa-row {
            display: grid; grid-template-columns: 1fr;
            gap: 6px; padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .qa-row:last-child { border-bottom: none; }
        .qa-q { font-weight: 600; color: #1f2937; }
        .qa-q .q-num {
            display: inline-block; min-width: 26px; height: 26px; line-height: 26px;
            text-align: center; background: #247d57; color: white;
            border-radius: 50%; font-size: 0.8rem; margin-right: 8px;
        }
        .qa-q .q-type {
            font-size: 0.7rem; background: #e0e7ff; color: #3730a3;
            padding: 2px 8px; border-radius: 10px; margin-left: 6px;
            text-transform: uppercase; font-weight: 600; letter-spacing: 0.3px;
        }
        .qa-a { padding-left: 34px; color: #111827; }
        .answer-empty { color: #9ca3af; font-style: italic; }
        .answer-list { margin: 4px 0 0 18px; padding: 0; }
        .answer-list li { margin-bottom: 2px; }
        .footer {
            margin-top: 30px; padding-top: 15px;
            border-top: 1px solid #dee2e6; font-size: 0.9rem;
            position: relative; z-index: 1;
        }
    </style>
</head>
<body>
    <?php if (!empty($logo)): ?>
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Background Logo" class="background-logo">
        <div class="watermark"><img src="<?php echo htmlspecialchars($logo); ?>" alt="Watermark"></div>
    <?php endif; ?>

    <div class="container">
        <div class="no-print text-center mb-4">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print My Responses
            </button>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>

        <div class="print-header">
            <?php if (!empty($logo)): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="logo-img">
            <?php endif; ?>
            <h2 class="text-success"><?php echo htmlspecialchars($instName); ?></h2>
            <?php if (!empty($instAddress)): ?>
                <p class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($instAddress); ?></p>
            <?php endif; ?>
            <h4 class="mt-3">Self-Evaluation Responses (Stage 1)</h4>
            <p class="text-muted mb-0"><small>Printed on <?php echo date('F j, Y, g:i A'); ?></small></p>
        </div>

        <div class="staff-info">
            <div class="row">
                <div class="col-md-4"><strong>Staff ID:</strong> <?php echo htmlspecialchars($eval['staff_identifier'] ?? $eval['staff_id']); ?></div>
                <div class="col-md-4"><strong>Name:</strong> <?php echo htmlspecialchars($eval['first_name'] . ' ' . $eval['surname']); ?></div>
                <div class="col-md-4">
                    <strong>Category:</strong>
                    <?php
                    $catLabel = $eval['staff_category'] == 'academic' ? 'Academic Staff'
                        : ($eval['staff_category'] == 'non-teaching-junior' ? 'Junior Staff'
                            : ($eval['staff_category'] == 'hod' ? 'Supervising Officer' : 'Non-Teaching Staff'));
                    echo htmlspecialchars($catLabel);
                    ?>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4"><strong>Department:</strong> <?php echo htmlspecialchars($eval['department'] ?? 'N/A'); ?></div>
                <div class="col-md-4"><strong>Designation:</strong> <?php echo htmlspecialchars($eval['designation'] ?? 'N/A'); ?></div>
                <div class="col-md-4"><strong>Grade Level:</strong> <?php echo htmlspecialchars($eval['grade_level'] ?? 'N/A'); ?></div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4"><strong>Academic Session:</strong> <?php echo htmlspecialchars($session['session_name'] ?? 'N/A'); ?></div>
                <div class="col-md-4"><strong>Evaluation Year:</strong> <?php echo htmlspecialchars($eval['evaluation_year']); ?></div>
                <div class="col-md-4">
                    <strong>Submission Status:</strong>
                    <span class="badge bg-info text-dark"><?php echo htmlspecialchars(ucfirst($eval['status'])); ?></span>
                </div>
            </div>
        </div>

        <div class="meta-strip text-center">
            <span class="pill"><i class="fas fa-list me-1"></i><?php echo $answered; ?> of <?php echo $totalQs; ?> questions answered</span>
            <span class="pill"><i class="fas fa-percentage me-1"></i>Self Score: <?php echo htmlspecialchars($eval['percentage']); ?>%</span>
            <span class="pill"><i class="fas fa-star me-1"></i>Grade: <?php echo htmlspecialchars($eval['performance_grade']); ?></span>
            <span class="pill"><i class="fas fa-info-circle me-1"></i>Stage: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $eval['evaluation_stage'] ?? 'pending'))); ?></span>
        </div>

        <?php if (empty($questions)): ?>
            <div class="alert alert-warning">No active questions are configured for your staff category.</div>
        <?php else: ?>
            <?php
            $qNum = 0;
            foreach ($byCategory as $cat => $catQuestions):
                $catAnswered = 0;
                foreach ($catQuestions as $q) {
                    $a = $staffResponses[$q['id']] ?? null;
                    if ($a !== null && $a !== '' && $a !== []) {
                        $catAnswered++;
                    }
                }
            ?>
                <div class="question-section">
                    <h5>
                        <i class="fas fa-folder-open me-2"></i>
                        <?php echo htmlspecialchars(categoryLabel($cat)); ?>
                        <span class="text-muted" style="font-size: 0.85rem; font-weight: normal;">
                            (<?php echo $catAnswered; ?> / <?php echo count($catQuestions); ?> answered)
                        </span>
                    </h5>

                    <?php foreach ($catQuestions as $q):
                        $qNum++;
                        $answer = $staffResponses[$q['id']] ?? null;
                    ?>
                        <div class="qa-row">
                            <div class="qa-q">
                                <span class="q-num"><?php echo $qNum; ?></span>
                                <?php echo htmlspecialchars($q['question_text']); ?>
                                <span class="q-type"><?php echo htmlspecialchars(str_replace('_', ' ', $q['question_type'])); ?></span>
                            </div>
                            <div class="qa-a">
                                <strong class="text-muted" style="font-size: 0.85rem;">Your Response:</strong><br>
                                <?php echo renderAnswer($q, $answer); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="footer">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Submitted on:</strong> <?php echo date('F j, Y, g:i A', strtotime($eval['created_at'])); ?></p>
                    <?php if (!empty($eval['updated_at']) && $eval['updated_at'] !== $eval['created_at']): ?>
                        <p><strong>Last updated:</strong> <?php echo date('F j, Y, g:i A', strtotime($eval['updated_at'])); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-end">
                    <p><strong>Self-Assessed Status:</strong> <?php echo htmlspecialchars($eval['performance_status']); ?></p>
                </div>
            </div>
            <p class="text-center text-muted"><?php echo !empty($settings['copyright_text']) ? htmlspecialchars($settings['copyright_text']) : htmlspecialchars($instName) . ' - Self-Evaluation Responses'; ?></p>
            <p class="text-center text-muted" style="font-size: 0.8rem;">This is a computer-generated document showing the data the staff entered during Stage 1 (self-evaluation).</p>
        </div>
    </div>
</body>
</html>