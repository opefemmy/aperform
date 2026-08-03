<?php
require_once 'config.php';
requireStaffLogin();
require_once 'mail.php';

$pdo = getDBConnection();
$staff = getCurrentStaff();

// Get staff category from database
$stmt = $pdo->prepare("SELECT staff_category FROM staff WHERE id = ?");
$stmt->execute([$staff['id']]);
$staffRow = $stmt->fetch();
$staffCategory = $staffRow['staff_category'] ?? 'academic';

// Get settings
$stmt = $pdo->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$primaryColor = $settings['primary_color'] ?? '#247d57';
$secondaryColor = $settings['secondary_color'] ?? '#1a5238';

// Check if evaluation already exists for this staff
$stmt = $pdo->prepare("SELECT * FROM evaluations WHERE staff_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$staff['id']]);
$existingEval = $stmt->fetch();

// Handle Staff Consent/Rejection - New workflow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_consent_action'])) {
    if ($existingEval && $existingEval['evaluation_stage'] === 'staff_review') {
        $consentAction = $_POST['staff_consent_action'];
        $rejectionReason = sanitize($_POST['rejection_reason'] ?? '');

        if ($consentAction === 'consent') {
            // Staff consents - move to registrar
            $updateStmt = $pdo->prepare("UPDATE evaluations SET
                staff_consent = 'consented',
                staff_consent_date = NOW(),
                evaluation_stage = 'registrar'
                WHERE id = ?");
            $updateStmt->execute([$existingEval['id']]);
            $consentMessage = 'Thank you! You have consented to the evaluation. It will now be forwarded to the Registrar for final approval.';

            // Send email notification to registrar
            try {
                sendEvaluationStageNotification('registrar', $existingEval, $staff);
            } catch (Exception $e) {
                error_log("Email notification error: " . $e->getMessage());
            }
        } elseif ($consentAction === 'reject') {
            if (empty($rejectionReason)) {
                $consentError = 'Please provide a reason for not consenting to the evaluation.';
            } else {
                // Staff rejects - move back to supervising officer for review
                $updateStmt = $pdo->prepare("UPDATE evaluations SET
                    staff_consent = 'rejected',
                    staff_rejection_reason = ?,
                    staff_consent_date = NOW(),
                    evaluation_stage = 'supervising_officer_reject'
                    WHERE id = ?");
                $updateStmt->execute([$rejectionReason, $existingEval['id']]);
                $consentMessage = 'Your feedback has been submitted. The Supervising Officer will review your concerns and add comments before it goes to the Registrar.';

                // Send email notification to supervising officer
                try {
                    $soStmt = $pdo->prepare("SELECT * FROM staff WHERE department = ? AND (evaluator_type = 'Supervising Officer' OR evaluator_type = 'supervisor') LIMIT 1");
                    $soStmt->execute([$staff['department']]);
                    $supervisor = $soStmt->fetch();

                    if ($supervisor) {
                        sendEvaluationStageNotification('supervising_officer_reject', $existingEval, $staff, $supervisor);
                    }
                } catch (Exception $e) {
                    error_log("Email notification error: " . $e->getMessage());
                }
            }
        }

        // Refresh evaluation data
        $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE id = ?");
        $stmt->execute([$existingEval['id']]);
        $existingEval = $stmt->fetch();
    }
}

// Get active academic session
$stmt = $pdo->query("SELECT * FROM academic_sessions WHERE is_active = 1 LIMIT 1");
$activeSession = $stmt->fetch();

// Get questions from database - Include 'both' for all staff categories
// Junior Staff (non-teaching-junior) gets junior questions + 'both'
// Non-Teaching Staff gets non-teaching questions + 'both'
// Academic Staff gets academic questions + 'both'
if ($staffCategory === 'non-teaching-junior') {
    // Junior Staff - get junior specific questions + 'both' (all staff questions)
    $stmt = $pdo->prepare("SELECT * FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = 'non-teaching-junior' OR target_staff_category = 'both') ORDER BY COALESCE(category_order, 99999), COALESCE(question_order, 99999), category, id");
    $stmt->execute();
} elseif ($staffCategory === 'non-teaching') {
    // Non-Teaching Staff (senior) - get non-teaching specific questions + 'both'
    $stmt = $pdo->prepare("SELECT * FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = 'non-teaching' OR target_staff_category = 'both') ORDER BY COALESCE(category_order, 99999), COALESCE(question_order, 99999), category, id");
    $stmt->execute();
} else {
    // Academic staff or others - use exact match + 'both'
    $stmt = $pdo->prepare("SELECT * FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = ? OR target_staff_category = 'both') ORDER BY COALESCE(category_order, 99999), COALESCE(question_order, 99999), category, id");
    $stmt->execute([$staffCategory]);
}
$dbQuestions = $stmt->fetchAll();

// Group questions by category
$questionsByCategory = [];
foreach ($dbQuestions as $q) {
    $questionsByCategory[$q['category']][] = $q;
}

// Calculate grade function
function calculateGrade($percentage) {
    if ($percentage >= 90) return ['Outstanding', 'Excellent Performance'];
    if ($percentage >= 80) return ['Excellent', 'Very Good Performance'];
    if ($percentage >= 70) return ['Very Good', 'Good Performance'];
    if ($percentage >= 60) return ['Good', 'Satisfactory'];
    if ($percentage >= 50) return ['Fair', 'Needs Improvement'];
    return ['Poor', 'Unsatisfactory'];
}

// Handle form submission
$submitMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $staffId = $_POST['staff_id'] ?? 0;
    $academicSessionId = $_POST['academic_session_id'] ?? 0;
    $evaluationYear = $_POST['evaluation_year'] ?? date('Y');

    // Collect dynamic responses from the database questions
    $responses = [];
    $numericScore = 0;
    $ratingQuestionCount = 0;
    $uploadDir = 'uploads/question_documents/';

    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    foreach ($dbQuestions as $q) {
        $fieldName = 'q_' . $q['id'];

        if ($q['question_type'] === 'multiple_choice') {
            // Multiple choice - checkbox array
            $responses[$q['id']] = $_POST[$fieldName] ?? [];
        } elseif ($q['question_type'] === 'file_upload') {
            // Handle file upload
            $existingFile = $_POST[$fieldName . '_existing'] ?? '';
            $newFile = $_FILES[$fieldName] ?? ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE];

            if ($newFile['error'] === UPLOAD_ERR_OK && !empty($newFile['name'])) {
                // Process new file upload
                $allowedTypes = explode(',', str_replace(' ', '', $q['allowed_file_types'] ?? 'pdf,doc,docx'));
                $fileExt = strtolower(pathinfo($newFile['name'], PATHINFO_EXTENSION));
                $maxSize = ($q['max_file_size'] ?? 5) * 1024 * 1024;

                if (in_array($fileExt, $allowedTypes) && $newFile['size'] <= $maxSize) {
                    $newFileName = 'staff_' . $staff['id'] . '_question_' . $q['id'] . '_' . time() . '.' . $fileExt;
                    $targetPath = $uploadDir . $newFileName;

                    if (move_uploaded_file($newFile['tmp_name'], $targetPath)) {
                        // Delete old file if exists
                        if (!empty($existingFile) && file_exists($existingFile)) {
                            @unlink($existingFile);
                        }
                        $responses[$q['id']] = $targetPath;
                    } else {
                        $responses[$q['id']] = $existingFile;
                    }
                } else {
                    $responses[$q['id']] = $existingFile;
                }
            } else {
                // Keep existing file
                $responses[$q['id']] = $existingFile;
            }
        } else {
            // All other types
            $responses[$q['id']] = $_POST[$fieldName] ?? '';
        }

        // For numeric rating/scale questions, calculate score
        if (($q['question_type'] === 'rating' || $q['question_type'] === 'scale') && !empty($responses[$q['id']])) {
            $numericScore += intval($responses[$q['id']]);
            $ratingQuestionCount++;
        }
    }

    // Count ALL answered questions (for 100% based scoring)
    // Every question type gets full marks (5 points) if answered
    $answeredCount = 0;
    $nonRatingScore = 0;
    foreach ($dbQuestions as $q) {
        $answer = $responses[$q['id']] ?? '';
        // Check if question was answered (any non-empty value)
        if (!empty($answer) && $answer !== '') {
            $answeredCount++;
            // Non-rating questions get 5 points if answered
            if ($q['question_type'] !== 'rating' && $q['question_type'] !== 'scale') {
                $nonRatingScore += 5;
            }
        }
    }

    // Calculate totals - MAXIMUM IS ALWAYS 100%
    // Each question is worth 5 points, but we normalize to 100
    $totalQuestions = count($dbQuestions);
    $totalScore = $numericScore + $nonRatingScore;
    $questionCount = $totalQuestions;
    $averageScore = $questionCount > 0 ? round($totalScore / $questionCount, 2) : 0;
    $maxPossible = $questionCount * 5;

    // Fixed max of 100 - normalize score to always be out of 100
    // Each question answered gets points, but total is capped at 100
    if ($totalScore > 0 && $maxPossible > 0) {
        // Calculate percentage based on questions answered, but cap at 100
        $rawPercentage = ($totalScore / $maxPossible) * 100;
        $percentage = min(round($rawPercentage, 2), 100); // Cap at 100
    } else {
        $percentage = 0;
    }

    // Total score display - normalize to 100 scale
    $totalScoreOutOf100 = min($totalScore, 100); // Cap at 100

    // Calculate grade
    $gradeResult = calculateGrade($percentage);

    try {
        // Check if responses column exists, add if not
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM evaluations LIKE 'responses'");
            $columnExists = $colStmt->fetch() !== false;
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE evaluations ADD COLUMN responses JSON AFTER staff_category");
            }
        } catch (Exception $e) {
            // Column creation might have failed, continue anyway
        }

        // Check if evaluation exists
        $checkStmt = $pdo->prepare("SELECT id, status, responses FROM evaluations WHERE staff_id = ? AND academic_session_id = ? AND evaluation_year = ?");
        $checkStmt->execute([$staffId, $academicSessionId, $evaluationYear]);
        $existingId = $checkStmt->fetch();

        // Only allow submission if not already submitted (or if it's a draft)
        if ($existingId && $existingId['status'] === 'submitted') {
            $submitMessage = 'You have already submitted your evaluation for this session. Please contact the administrator if you need to make changes.';
        } elseif ($existingId) {
            // Update existing - allow resubmission
            $newStatus = 'submitted';

            // Get legacy scores from existing columns if needed
            $legacyScores = [];
            if (isset($existingId['responses']) && $existingId['responses']) {
                $legacyScores = is_array($existingId['responses']) ? $existingId['responses'] : json_decode($existingId['responses'], true);
            }

            // Merge new dynamic responses with any legacy data
            $allResponses = array_merge($legacyScores ?? [], $responses);

            $updateStmt = $pdo->prepare("UPDATE evaluations SET
                responses = ?,
                total_score = ?,
                average_score = ?,
                percentage = ?,
                performance_grade = ?,
                performance_status = ?,
                status = ?,
                staff_category = ?,
                updated_at = NOW()
                WHERE id = ?");
            $updateStmt->execute([
                json_encode($allResponses),
                $totalScoreOutOf100,
                $averageScore,
                $percentage,
                $gradeResult[0],
                $gradeResult[1],
                $newStatus,
                $staffCategory,
                $existingId['id']
            ]);
            $submitMessage = 'Evaluation updated and submitted successfully!';

            // Refresh the evaluation data
            $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE staff_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$staff['id']]);
            $existingEval = $stmt->fetch();
        } elseif (!$existingId) {
            // Insert new (first submission)
            $insertStmt = $pdo->prepare("INSERT INTO evaluations (
                staff_id, academic_session_id, evaluation_year,
                responses,
                total_score, average_score, percentage, performance_grade, performance_status, status, evaluation_stage, staff_category
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending', ?)");
            $insertStmt->execute([
                $staffId,
                $academicSessionId,
                $evaluationYear,
                json_encode($responses),
                $totalScoreOutOf100,
                $averageScore,
                $percentage,
                $gradeResult[0],
                $gradeResult[1],
                $staffCategory
            ]);
            $newEvalId = $pdo->lastInsertId();
            $submitMessage = 'Evaluation submitted successfully! <a href="print-summary.php?id=' . $newEvalId . '" target="_blank" class="btn btn-sm btn-outline-primary ms-2"><i class="fas fa-print"></i> Print Summary</a>';

            // Send email notification to supervising officer
            try {
                // Get the supervising officer for this staff
                $soStmt = $pdo->prepare("SELECT * FROM staff WHERE department = ? AND (evaluator_type = 'Supervising Officer' OR evaluator_type = 'supervisor') LIMIT 1");
                $soStmt->execute([$staff['department']]);
                $supervisor = $soStmt->fetch();

                if ($supervisor) {
                    sendEvaluationStageNotification('pending', $existingEval, $staff, $supervisor);
                }
            } catch (Exception $e) {
                // Silent fail for email - don't interrupt the flow
                error_log("Email notification error: " . $e->getMessage());
            }

            // Send confirmation email to staff
            try {
                sendEvaluationStageNotification('submitted', $existingEval, $staff);
            } catch (Exception $e) {
                // Silent fail for email - don't interrupt the flow
                error_log("Email notification error: " . $e->getMessage());
            }

            // Refresh the evaluation data
            $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE staff_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$staff['id']]);
            $existingEval = $stmt->fetch();
        }
        // If already submitted, don't do anything - message is already set above
    } catch (Exception $e) {
        $submitMessage = 'Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Evaluation - Staff Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="theme-overrides.css" rel="stylesheet">
    <style>
        :root { --primary-blue: <?php echo $primaryColor; ?>; --secondary-blue: <?php echo $secondaryColor; ?>; }
        body { background: #f3f4f6; }
        .top-bar { background: linear-gradient(135deg, <?php echo $primaryColor; ?> 0%, <?php echo $secondaryColor; ?> 100%); color: white; padding: 1rem 0; }
        .staff-info-card { background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .score-card { background: linear-gradient(135deg, <?php echo $primaryColor; ?>, <?php echo $secondaryColor; ?>); color: white; padding: 1.5rem; border-radius: 16px; text-align: center; box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.3); min-height: 120px; display: flex; flex-direction: column; justify-content: center; }
        .score-card .value { font-size: 2rem; font-weight: 700; line-height: 1.2; }
        .score-card > div:last-child { font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem; }
        .question-item { background: white; padding: 1.25rem; border-radius: 12px; margin-bottom: 1rem; border: 1px solid #e5e7eb; transition: all 0.3s ease; }
        .question-item:hover { border-color: <?php echo $secondaryColor; ?>; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .rating-label { padding: 0.5rem 0.75rem; background: #f8fafc; border-radius: 20px; cursor: pointer; margin-right: 0.25rem; display: inline-block; text-align: center; min-width: 45px; }
        .rating-label:hover { background: #dbeafe; }
        .rating-label input:checked + span { background: <?php echo $primaryColor; ?>; color: white; border-radius: 15px; padding: 2px 8px; }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .top-bar .container { flex-direction: column; align-items: flex-start !important; }
            .top-bar .text-end { margin-top: 10px; }
            .score-card { padding: 1rem; margin-bottom: 0; min-height: 100px; }
            .score-card .value { font-size: 1.5rem; }
            .staff-info-card .row > div { margin-bottom: 5px; }
        }

        /* Dark Mode Styles */
        body.dark-mode { background: #1a1a2e; color: #e0e0e0; }
        body.dark-mode .staff-info-card { background: #16213e; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        body.dark-mode .question-item { background: #16213e; border-color: #2a2a4a; }
        body.dark-mode .question-item label { color: #e0e0e0; }
        body.dark-mode .rating-label { background: #2a2a4a; color: #e0e0e0; }
        body.dark-mode .rating-label:hover { background: #3a3a5a; }
        body.dark-mode .card { background: #16213e; border-color: #2a2a4a; }
        body.dark-mode .card-header { background: #1e3a8a !important; }
        body.dark-mode .form-control { background: #2a2a4a; border-color: #3a3a5a; color: #e0e0e0; }
        body.dark-mode .form-control::placeholder { color: #888; }
        body.dark-mode .form-select { background: #2a2a4a; border-color: #3a3a5a; color: #e0e0e0; }
        body.dark-mode .form-check-label { color: #e0e0e0; }
        body.dark-mode .alert-info { background: #1e3a8a; border-color: #2563eb; color: #e0e0e0; }
        body.dark-mode .text-muted { color: #aaa !important; }
        body.dark-mode .table { color: #e0e0e0; }
        body.dark-mode footer { background: linear-gradient(180deg, #1e3a8a 0%, #2563eb 100%) !important; }

        /* Dark mode toggle button */
        .dark-mode-toggle {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .dark-mode-toggle:hover { background: rgba(255,255,255,0.3); }

        /* Section navigation styles */
        .question-section { display: none; }
        .question-section.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .section-nav {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 2px dashed #cbd5e1;
        }
        .section-progress {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .section-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s;
        }
        .section-dot.active { background: <?php echo $primaryColor; ?>; transform: scale(1.3); }
        .section-dot.completed { background: #10b981; }
        .section-divider {
            height: 3px;
            background: linear-gradient(90deg, <?php echo $primaryColor; ?>, <?php echo $secondaryColor; ?>);
            border-radius: 2px;
            margin: 1.5rem 0;
            position: relative;
        }
        .section-divider::after {
            content: 'Next Section';
            position: absolute;
            right: 0;
            top: -10px;
            background: white;
            padding: 0 10px;
            font-size: 12px;
            color: <?php echo $primaryColor; ?>;
            font-weight: 600;
        }
        body.dark-mode .section-nav { background: linear-gradient(135deg, #1e293b, #334155); border-color: #475569; }
        body.dark-mode .section-divider::after { background: #0f172a; }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <?php if (!empty($settings['institution_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($settings['institution_logo']); ?>" alt="Logo" style="max-height: 50px; margin-right: 15px; border: 2px solid white; border-radius: 5px; padding: 3px; background: rgba(255,255,255,0.2);">
                    <?php endif; ?>
                    <div>
                        <h3 class="mb-0 fw-bold"><?php echo htmlspecialchars($settings['institution_name'] ?? 'Institution'); ?></h3>
                        <?php if (!empty($settings['institution_address'])): ?>
                        <small><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($settings['institution_address']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fas fa-user me-2"></i><?php echo $staff['name']; ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                        <button class="dark-mode-toggle ms-2" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                            <i class="fas fa-moon"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($submitMessage): ?>
        <div class="alert alert-<?php echo strpos($submitMessage, 'Error') !== false ? 'danger' : 'success'; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo strpos($submitMessage, 'Error') !== false ? 'exclamation-circle' : 'check-circle'; ?> me-2"></i>
            <?php echo $submitMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Staff Info -->
        <div class="staff-info-card mb-4">
            <div class="row">
                <div class="col-md-2"><strong>Staff ID:</strong> <?php echo htmlspecialchars($staff['staff_number']); ?></div>
                <div class="col-md-3"><strong>Name:</strong> <?php echo htmlspecialchars($staff['name']); ?></div>
                <div class="col-md-2"><strong>Department:</strong> <?php echo htmlspecialchars($staff['department'] ?? 'N/A'); ?></div>
                <div class="col-md-2"><strong>Level:</strong> <?php echo htmlspecialchars($staff['grade_level'] ?? 'N/A'); ?></div>
                <div class="col-md-2"><strong>Category:</strong> <?php echo $staffCategory == 'academic' ? 'Academic' : ($staffCategory == 'non-teaching-junior' ? 'Non-Teaching Junior' : 'Non-Teaching'); ?></div>
                <div class="col-md-1"><strong>Session:</strong> <?php echo htmlspecialchars($activeSession['session_name'] ?? 'N/A'); ?></div>
            </div>
        </div>

        <!-- Evaluation Status -->
        <?php if ($existingEval):
            $totalQuestionsCount = count($dbQuestions);
            $maxPoints = $totalQuestionsCount * 5;
        ?>
        <div class="row g-3 mb-4 text-center">
            <div class="col-6 col-md-2">
                <div class="score-card h-100">
                    <div class="value"><?php echo min($existingEval['total_score'], 100); ?>/100</div>
                    <div>Points</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="score-card h-100">
                    <div class="value"><?php echo $existingEval['percentage']; ?>%</div>
                    <div>Percentage</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="score-card h-100" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="value"><?php echo $existingEval['performance_grade']; ?></div>
                    <div>Grade</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="score-card h-100" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="value"><?php echo ucfirst($existingEval['status']); ?></div>
                    <div>Status</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="score-card h-100" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <?php
                    $stageLabels = [
                        'pending' => 'Pending SO Review',
                        'staff_review' => 'Awaiting Your Review',
                        'supervising_officer_reject' => 'SO Re-Evaluating',
                        'registrar' => 'Awaiting Registrar',
                        'completed' => 'Completed'
                    ];
                    $stage = $existingEval['evaluation_stage'] ?? 'pending';
                    ?>
                    <div class="value"><?php echo $stageLabels[$stage] ?? ucfirst($stage); ?></div>
                    <div>Stage</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($existingEval && is_array($existingEval) && ($existingEval['status'] ?? '') !== 'draft'): ?>
        <?php
        // Show appropriate message based on evaluation stage
        $stage = $existingEval['evaluation_stage'] ?? 'pending';
        if ($stage === 'pending'): ?>
        <div class="alert alert-info">
            <i class="fas fa-clock me-2"></i>
            Your evaluation has been submitted and is currently being reviewed by your Supervising Officer. You will be notified when it's ready for your review.
        </div>
        <?php elseif ($stage === 'staff_review'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-circle me-2"></i>
            Your Supervising Officer has completed your evaluation. Please review and consent to the evaluation grade.
        </div>
        <?php elseif ($stage === 'registrar'): ?>
        <div class="alert alert-info">
            <i class="fas fa-check-circle me-2"></i>
            You have consented to the evaluation. It is now awaiting final approval from the Registrar.
        </div>
        <?php elseif ($stage === 'completed'): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            Your evaluation has been fully completed and approved.
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-check-circle me-2"></i>
            Your evaluation has been submitted. You can still update and resubmit at any time.
        </div>
        <?php endif; ?>

        <!-- Print Summary - Available immediately after submission (evidence of participation) -->
        <?php if ($existingEval && isset($existingEval['id'])): ?>
        <div class="text-center mb-3">
            <a href="print-summary.php?id=<?php echo $existingEval['id']; ?>" target="_blank" class="btn btn-primary btn-lg">
                <i class="fas fa-print me-2"></i>Print Summary (Evidence of Participation)
            </a>
            <p class="text-muted mt-2"><small>Print this as evidence that you have completed your evaluation</small></p>
        </div>

        <!-- Print My Self-Evaluation Responses - Print the actual data the staff filled in during Stage 1 -->
        <div class="text-center mb-3">
            <a href="print-staff-responses.php?id=<?php echo $existingEval['id']; ?>" target="_blank" class="btn btn-outline-success btn-lg">
                <i class="fas fa-file-alt me-2"></i>Print My Self-Evaluation Responses
            </a>
            <p class="text-muted mt-2"><small>Print a copy of the answers you provided during your self-evaluation submission</small></p>
        </div>
        <?php endif; ?>

        <!-- Staff Review Section - Show when Supervising Officer has evaluated and passed to staff -->
        <?php if ($existingEval && $existingEval['evaluation_stage'] === 'staff_review'): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Review Your Evaluation</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="score-display">
                            <div class="value"><?php echo $existingEval['total_score']; ?></div>
                            <div>Total Score</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="score-display">
                            <div class="value"><?php echo $existingEval['percentage']; ?>%</div>
                            <div>Percentage</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="score-display" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <div class="value"><?php echo $existingEval['performance_grade']; ?></div>
                            <div>Grade</div>
                        </div>
                    </div>
                </div>

                <!-- Button to view detailed review - only show when SO has passed to staff -->
                <?php if ($existingEval['evaluation_stage'] === 'staff_review'): ?>
                <div class="text-center mb-3">
                    <a href="staff-review.php" class="btn btn-warning btn-lg">
                        <i class="fas fa-search me-2"></i>View Detailed Review
                    </a>
                    <p class="text-muted mt-2"><small>Click to see how your Supervising Officer graded each question</small></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($existingEval['supervisor_remarks'])): ?>
                <div class="alert alert-info">
                    <strong><i class="fas fa-comment me-2"></i>Supervising Officer Remarks:</strong><br>
                    <?php echo nl2br(htmlspecialchars($existingEval['supervisor_remarks'])); ?>
                </div>
                <?php endif; ?>

                <hr>

                <h5><i class="fas fa-question-circle me-2"></i>Do you consent to this evaluation grade?</h5>

                <?php if (isset($consentMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo $consentMessage; ?>
                </div>
                <?php endif; ?>

                <?php if (isset($consentError)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $consentError; ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="mb-3">
                    <div class="mb-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="staff_consent_action" id="consent_yes" value="consent" required>
                            <label class="form-check-label" for="consent_yes">
                                <i class="fas fa-check-circle text-success me-1"></i>
                                <strong>Yes, I consent to the grade of my Supervising Officer</strong>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="staff_consent_action" id="consent_no" value="reject" required>
                            <label class="form-check-label" for="consent_no">
                                <i class="fas fa-times-circle text-danger me-1"></i>
                                <strong>No, I do not consent</strong>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3" id="rejection_reason_div" style="display:none;">
                        <label class="form-label">Please provide your reasons for not consenting:</label>
                        <textarea class="form-control" name="rejection_reason" rows="4" placeholder="Explain your concerns about the evaluation..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane me-2"></i>Submit Your Response
                    </button>
                </form>

                <script>
                document.querySelectorAll('input[name="staff_consent_action"]').forEach(function(radio) {
                    radio.addEventListener('change', function() {
                        var reasonDiv = document.getElementById('rejection_reason_div');
                        if (this.value === 'reject') {
                            reasonDiv.style.display = 'block';
                        } else {
                            reasonDiv.style.display = 'none';
                        }
                    });
                });
                </script>
            </div>
        </div>
        <?php endif; ?>

        <!-- Show status if staff has already responded -->
        <?php if ($existingEval && in_array($existingEval['evaluation_stage'], ['staff_review', 'supervising_officer_final', 'registrar', 'completed'])): ?>
        <div class="card mb-4 <?php echo $existingEval['staff_consent'] === 'consented' ? 'border-success' : 'border-danger'; ?>">
            <div class="card-header <?php echo $existingEval['staff_consent'] === 'consented' ? 'bg-success' : 'bg-danger'; ?> text-white">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Your Response to Evaluation</h5>
            </div>
            <div class="card-body">
                <?php if ($existingEval['staff_consent'] === 'consented'): ?>
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>You have consented</strong> to the evaluation grade. It has been forwarded to the Registrar for final approval.
                </div>
                <?php elseif ($existingEval['staff_consent'] === 'rejected'): ?>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <strong>You did not consent</strong> to the evaluation. Your reasons were:<br>
                    <em><?php echo nl2br(htmlspecialchars($existingEval['staff_rejection_reason'])); ?></em>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Uploaded Files Display - View Only (No Download for Staff) -->
        <?php
        $uploadedFiles = [];
        if (!empty($existingEval['responses'])) {
            $responses = is_array($existingEval['responses']) ? $existingEval['responses'] : json_decode($existingEval['responses'], true);
            if (is_array($responses)) {
                foreach ($responses as $qId => $response) {
                    if (!empty($response) && is_string($response) && file_exists($response)) {
                        $uploadedFiles[$qId] = $response;
                    }
                }
            }
        }
        ?>
        <?php if (!empty($uploadedFiles)): ?>
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-paperclip me-2"></i>My Uploaded Documents</h5>
            </div>
            <div class="card-body">
                <p class="text-muted"><small>Your uploaded files are saved. Contact the administrator to access them.</small></p>
                <div class="row">
                    <?php foreach ($uploadedFiles as $qId => $filePath): ?>
                    <?php
                    $questionText = '';
                    foreach ($dbQuestions as $q) {
                        if ($q['id'] == $qId) {
                            $questionText = $q['question_text'];
                            break;
                        }
                    }
                    $fileName = basename($filePath);
                    ?>
                    <div class="col-md-6 mb-2">
                        <div class="border rounded p-2 bg-light">
                            <i class="fas fa-file me-2"></i>
                            <strong><?php echo htmlspecialchars($questionText); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($fileName); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($existingEval && is_array($existingEval) && ($existingEval['status'] ?? '') === 'approved'): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <strong>Congratulations!</strong> Your evaluation has been fully approved. You can now download your official evaluation report.
        </div>
        <div class="text-center mb-4">
            <a href="pdf-report.php?id=<?php echo $existingEval['id']; ?>" target="_blank" class="btn btn-success btn-lg">
                <i class="fas fa-file-pdf me-2"></i>Download Official Report (Final Grade)
            </a>
        </div>
        <?php endif; ?>

        <!-- Self Evaluation Form - Always show for editing -->
        <form method="POST" id="evalForm" enctype="multipart/form-data">
            <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
            <input type="hidden" name="academic_session_id" value="<?php echo $activeSession['id'] ?? 0; ?>">
            <input type="hidden" name="evaluation_year" value="<?php echo $settings['evaluation_year'] ?? date('Y'); ?>">

            <!-- Live Score Display -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="score-card" style="background: linear-gradient(135deg, #247d57, #1a5238);">
                        <div class="value" id="liveTotalScore">0</div>
                        <div>Points</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="score-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <div class="value" id="liveAvgScore">0</div>
                        <div>Average</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="score-card" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <div class="value" id="livePercentScore">0%</div>
                        <div>Percentage</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Self-Evaluation Form</h5>
                </div>
                <div class="card-body">

                    <?php if (empty($questionsByCategory)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No evaluation questions have been configured yet. Please contact the administrator.
                    </div>
                    <?php else: ?>

                    <?php
                    // Define category display names
                    $categoryNames = [
                        'Teaching' => 'Teaching Performance',
                        'Research' => 'Research Performance',
                        'Administrative' => 'Administrative Duties',
                        'Community' => 'Community Service',
                        'Professional' => 'Professional Development'
                    ];

                    // Load existing responses from JSON column
                    $existingResponses = [];
                    if (!empty($existingEval['responses'])) {
                        if (is_array($existingEval['responses'])) {
                            $existingResponses = $existingEval['responses'];
                        } else {
                            $existingResponses = json_decode($existingEval['responses'], true) ?? [];
                        }
                    }

                    // Get all categories
                    $allCategories = array_keys($questionsByCategory);
                    $totalSections = count($allCategories);
                    ?>

                    <!-- Section Progress Indicator -->
                    <div class="section-nav">
                        <div class="section-progress" id="sectionProgress">
                            <?php foreach ($allCategories as $index => $cat): ?>
                            <div class="section-dot <?php echo $index === 0 ? 'active' : ''; ?>"
                                 onclick="goToSection(<?php echo $index; ?>)"
                                 title="<?php echo htmlspecialchars($categoryNames[$cat] ?? $cat); ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center">
                            <small class="text-muted">Section <span id="currentSectionNum">1</span> of <?php echo $totalSections; ?></small>
                        </div>
                    </div>

                    <!-- Render each category as a section -->
                    <?php foreach ($questionsByCategory as $category => $categoryQuestions):
                        $sectionIndex = array_search($category, $allCategories);
                        $isFirst = ($sectionIndex === 0);
                        $isLast = ($sectionIndex === $totalSections - 1);
                    ?>
                    <div class="question-section <?php echo $isFirst ? 'active' : ''; ?>" id="section_<?php echo $sectionIndex; ?>">
                        <!-- Previous Button (except first section) -->
                        <?php if (!$isFirst): ?>
                        <div class="d-flex justify-content-start mb-3">
                            <button type="button" class="btn btn-outline-primary" onclick="goToSection(<?php echo $sectionIndex - 1; ?>);">
                                <i class="fas fa-arrow-left me-2"></i>Previous Section
                            </button>
                        </div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <h6 class="text-primary border-bottom pb-2">
                                <i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($categoryNames[$category] ?? $category); ?>
                                <span class="badge bg-secondary float-end"><?php echo count($categoryQuestions); ?> questions</span>
                            </h6>
                            <?php foreach ($categoryQuestions as $index => $q):
                                $fieldName = 'q_' . $q['id'];
                                $existingValue = $existingResponses[$q['id']] ?? '';
                            ?>
                            <div class="question-item">
                                <label class="form-label fw-bold">
                                    <?php if (!empty($q['question_label'])): ?>
                                        <span class="text-primary">(<?php echo htmlspecialchars($q['question_label']); ?>)</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($q['question_text']); ?>
                                </label>
                                <div>
                                    <?php
                                    // Render based on question type
                                    if ($q['question_type'] === 'rating' || $q['question_type'] === 'scale'):
                                        for ($i = 5; $i >= 1; $i--): ?>
                                    <label class="rating-label">
                                        <input type="radio" name="<?php echo $fieldName; ?>" value="<?php echo $i; ?>" onchange="calculateScores()" <?php echo $existingValue == $i ? 'checked' : ''; ?>>
                                        <span><?php echo $i; ?></span>
                                    </label>
                                        <?php endfor;
                                    elseif ($q['question_type'] === 'yes_no'): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="<?php echo $fieldName; ?>" value="yes" <?php echo $existingValue == 'yes' ? 'checked' : ''; ?>>
                                        <label class="form-check-label">Yes</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="<?php echo $fieldName; ?>" value="no" <?php echo $existingValue == 'no' ? 'checked' : ''; ?>>
                                        <label class="form-check-label">No</label>
                                    </div>
                                    <?php elseif ($q['question_type'] === 'true_false'): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="<?php echo $fieldName; ?>" value="true" <?php echo $existingValue == 'true' ? 'checked' : ''; ?>>
                                        <label class="form-check-label">True</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="<?php echo $fieldName; ?>" value="false" <?php echo $existingValue == 'false' ? 'checked' : ''; ?>>
                                        <label class="form-check-label">False</label>
                                    </div>
                                    <?php elseif ($q['question_type'] === 'single_choice' && !empty($q['options'])):
                                        $options = explode("\n", $q['options']); ?>
                                    <select class="form-select" name="<?php echo $fieldName; ?>" onchange="calculateScores()">
                                        <option value="">Select an option</option>
                                        <?php foreach ($options as $opt): ?>
                                        <option value="<?php echo htmlspecialchars(trim($opt)); ?>" <?php echo $existingValue == trim($opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim($opt)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php elseif ($q['question_type'] === 'multiple_choice' && !empty($q['options'])):
                                        $options = explode("\n", $q['options']);
                                        // Handle both array and string (legacy) formats
                                        if (is_array($existingValue)) {
                                            $existingMulti = $existingValue;
                                        } else {
                                            $existingMulti = explode(',', $existingValue ?? '');
                                        } ?>
                                    <?php foreach ($options as $opt): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="<?php echo $fieldName; ?>[]" value="<?php echo htmlspecialchars(trim($opt)); ?>" <?php echo in_array(trim($opt), $existingMulti) ? 'checked' : ''; ?>>
                                        <label class="form-check-label"><?php echo htmlspecialchars(trim($opt)); ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                    <input type="text" class="form-control" name="<?php echo $fieldName; ?>" value="<?php echo htmlspecialchars($existingValue ?? ''); ?>" placeholder="Enter your answer">
                                    <?php elseif ($q['question_type'] === 'long_answer'): ?>
                                    <textarea class="form-control" name="<?php echo $fieldName; ?>" rows="3" placeholder="Enter your answer"><?php echo htmlspecialchars($existingValue ?? ''); ?></textarea>
                                    <?php elseif ($q['question_type'] === 'file_upload'): ?>
                                    <div class="file-upload-area">
                                        <?php
                                        $allowedTypes = $q['allowed_file_types'] ?? 'pdf,doc,docx';
                                        $maxSize = ($q['max_file_size'] ?? 5) * 1024 * 1024; // Convert MB to bytes
                                        $existingFile = $existingValue ?? '';
                                        ?>
                                        <input type="hidden" name="<?php echo $fieldName; ?>_existing" value="<?php echo htmlspecialchars($existingFile); ?>">
                                        <input type="file" class="form-control" name="<?php echo $fieldName; ?>"
                                               accept="<?php echo str_replace(',', ',', $allowedTypes); ?>"
                                               data-max-size="<?php echo $maxSize; ?>">
                                        <small class="text-muted">
                                            Allowed: <?php echo htmlspecialchars($allowedTypes); ?> | Max size: <?php echo $q['max_file_size'] ?? 5; ?>MB
                                        </small>
                                        <?php if (!empty($existingFile)): ?>
                                        <div class="mt-2 p-2 bg-light rounded">
                                            <i class="fas fa-file-alt me-2"></i>
                                            <strong>Previously uploaded:</strong> <?php echo htmlspecialchars(basename($existingFile)); ?>
                                            <input type="hidden" name="<?php echo $fieldName; ?>_existing" value="<?php echo htmlspecialchars($existingFile); ?>">
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <?php
                                    $ratingLabels = [
                                        5 => 'Excellent',
                                        4 => 'Very Good',
                                        3 => 'Good',
                                        2 => 'Fair',
                                        1 => 'Poor'
                                    ];
                                    for ($i = 5; $i >= 1; $i--): ?>
                                    <label class="rating-label" title="<?php echo $ratingLabels[$i]; ?>">
                                        <input type="radio" name="<?php echo $fieldName; ?>" value="<?php echo $i; ?>" onchange="calculateScores()" <?php echo $existingValue == $i ? 'checked' : ''; ?>>
                                        <span><?php echo $i; ?></span>
                                        <small class="d-block" style="font-size: 9px;"><?php echo $ratingLabels[$i]; ?></small>
                                    </label>
                                    <?php endfor; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Next/Submit Button -->
                        <div class="section-divider"></div>
                        <div class="d-flex justify-content-<?php echo $isLast ? 'center' : 'end'; ?> mb-4">
                            <?php if ($isLast): ?>
                                <?php if ($existingEval && $existingEval['status'] === 'submitted'): ?>
                                <div class="alert alert-info w-100">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>Already Submitted:</strong> You have already submitted your evaluation for this session. Contact the administrator to make changes.
                                </div>
                                <?php else: ?>
                                <button type="submit" name="submit_evaluation" class="btn btn-success btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Evaluation
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                            <button type="button" class="btn btn-primary btn-lg" onclick="goToSection(<?php echo $sectionIndex + 1; ?>);">
                                Next Section<i class="fas fa-arrow-right ms-2"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function calculateScores() {
        let total = 0;
        let count = 0;
        const radios = document.querySelectorAll('input[type="radio"]:checked');
        radios.forEach(radio => {
            total += parseInt(radio.value);
            count++;
        });

        // Count ALL answered questions (rating, yes_no, true_false, text inputs, etc.)
        // Each answered question gets 5 points (full marks)
        const allInputs = document.querySelectorAll('.question-input, input[type="radio"], input[type="checkbox"], textarea, input[type="text"]');
        allInputs.forEach(input => {
            // Skip rating/scale radios (already counted above)
            if (input.type === 'radio' && input.name.includes('rating')) return;

            // Check if answered
            if (input.type === 'checkbox') {
                if (input.checked) {
                    total += 5;
                    count++;
                }
            } else if (input.value && input.value.trim() !== '') {
                // Text inputs, selects, etc. - get 5 points if answered
                total += 5;
                count++;
            }
        });

        const avg = count > 0 ? (total / count).toFixed(2) : 0;
        // Dynamic max possible based on ALL questions
        const maxPossible = window.questionCount ? window.questionCount * 5 : 23 * 5;
        const percentage = maxPossible > 0 ? ((total / maxPossible) * 100).toFixed(1) : 0;

        // Update score display if it exists
        const totalEl = document.getElementById('liveTotalScore');
        const avgEl = document.getElementById('liveAvgScore');
        const percentEl = document.getElementById('livePercentScore');

        if (totalEl) totalEl.textContent = total;
        if (avgEl) avgEl.textContent = avg;
        if (percentEl) percentEl.textContent = percentage + '%';
    }

    // Set question count for dynamic score calculation - ALL questions count now
    window.questionCount = <?php echo count($dbQuestions); ?>;

    // Section navigation
    const totalSections = <?php echo $totalSections; ?>;
    let currentSection = 0;

    function goToSection(sectionIndex) {
        if (sectionIndex < 0 || sectionIndex >= totalSections) return;

        // Hide current section
        document.getElementById('section_' + currentSection).classList.remove('active');

        // Show new section
        currentSection = sectionIndex;
        document.getElementById('section_' + currentSection).classList.add('active');

        // Update progress dots
        const dots = document.querySelectorAll('.section-dot');
        dots.forEach((dot, index) => {
            dot.classList.remove('active');
            if (index < currentSection) {
                dot.classList.add('completed');
            } else {
                dot.classList.remove('completed');
            }
        });
        dots[currentSection].classList.add('active');

        // Update section counter
        document.getElementById('currentSectionNum').textContent = currentSection + 1;

        // Scroll to top of form
        document.querySelector('.question-section.active').scrollIntoView({ behavior: 'smooth' });
    }

    // Dark mode toggle
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
    }

    // Check for saved dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
    </script>

    <!-- Footer -->
    <footer class="mt-4 py-3" style="background: linear-gradient(180deg, <?php echo $primaryColor; ?> 0%, <?php echo $secondaryColor; ?> 100%); color: white; border-radius: 8px;">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6">
                    <small><?php echo !empty($settings['copyright_text']) ? htmlspecialchars($settings['copyright_text']) : '&copy; ' . date('Y') . ' ' . htmlspecialchars($settings['institution_name'] ?? 'Institution') . '. All rights reserved.'; ?></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small>Powered by APER System</small>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>