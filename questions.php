<?php
require_once 'config.php';
requireAdminLogin();

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$message = getMessage();
$pdo = getDBConnection();

// Get institution settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$instName = $settings['institution_name'] ?? 'Institution';
$instAddress = $settings['institution_address'] ?? '';
$logo = $settings['institution_logo'] ?? '';

// Check if new columns exist
$hasSubCategory = false;
$hasFileUpload = false;
$hasQuestionLabel = false;
try {
    $pdo->query("SELECT sub_category FROM evaluation_questions LIMIT 1");
    $hasSubCategory = true;
} catch (Exception $e) {}
try {
    $pdo->query("SELECT allowed_file_types FROM evaluation_questions LIMIT 1");
    $hasFileUpload = true;
} catch (Exception $e) {}
try {
    $pdo->query("SELECT question_label FROM evaluation_questions LIMIT 1");
    $hasQuestionLabel = true;
} catch (Exception $e) {}

// Handle add/edit/delete question
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_question'])) {
        $questionType = $_POST['question_type'];
        $options = '';

        // For multiple choice, get options
        if ($questionType === 'multiple_choice' || $questionType === 'single_choice') {
            $options = sanitize($_POST['options']);
        }

        // Handle category - text input now accepts any value directly
        $category = sanitize($_POST['category'] ?? '');

        // Handle sub-category (custom sub-category)
        $subCategory = null;
        if (!empty($_POST['sub_category']) && $_POST['sub_category'] !== '') {
            if ($_POST['sub_category'] === 'custom' && !empty($_POST['custom_sub_category'])) {
                $subCategory = sanitize($_POST['custom_sub_category']);
            } elseif ($_POST['sub_category'] !== 'custom') {
                $subCategory = sanitize($_POST['sub_category']);
            }
        }

        // Handle file upload settings
        $allowedFileTypes = 'pdf,doc,docx';
        $maxFileSize = 5;
        if ($questionType === 'file_upload') {
            $allowedFileTypes = sanitize($_POST['allowed_file_types'] ?? 'pdf,doc,docx');
            $maxFileSize = intval($_POST['max_file_size'] ?? 5);
        }

        // Handle question group and label
        $questionGroup = !empty($_POST['question_group']) ? sanitize($_POST['question_group']) : null;
        $questionLabel = !empty($_POST['question_label']) ? sanitize($_POST['question_label']) : null;
        $questionOrder = intval($_POST['question_order'] ?? 0);

        // Determine category_order for this category.
        // If the category has no existing rows, assign the next available slot so it doesn't fall back to alphabetical order.
        $categoryOrder = null;
        $checkStmt = $pdo->prepare("SELECT category_order FROM evaluation_questions WHERE category = ? AND category_order IS NOT NULL LIMIT 1");
        $checkStmt->execute([$category]);
        $existingOrder = $checkStmt->fetchColumn();
        if ($existingOrder !== false && $existingOrder !== null) {
            $categoryOrder = (int)$existingOrder;
        } else {
            // No existing order for this category — give it the next slot (max + 10, or 10 if table is empty)
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(category_order), 0) FROM evaluation_questions")->fetchColumn();
            $categoryOrder = $maxOrder + 10;
        }

        // Build query based on available columns
        if ($hasQuestionLabel) {
            // Full query with all new columns
            $stmt = $pdo->prepare("INSERT INTO evaluation_questions (category, sub_category, question_text, question_type, options, target_staff_category, allowed_file_types, max_file_size, question_group, question_label, question_order, category_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $category,
                $subCategory,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                sanitize($_POST['target_staff_category'] ?? 'both'),
                $allowedFileTypes,
                $maxFileSize,
                $questionGroup,
                $questionLabel,
                $questionOrder,
                $categoryOrder
            ]);
        } elseif ($hasSubCategory) {
            $stmt = $pdo->prepare("INSERT INTO evaluation_questions (category, sub_category, question_text, question_type, options, target_staff_category) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $category,
                $subCategory,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                sanitize($_POST['target_staff_category'] ?? 'both')
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO evaluation_questions (category, question_text, question_type, options, target_staff_category) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $category,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                sanitize($_POST['target_staff_category'] ?? 'both')
            ]);
        }
        showMessage('Question added successfully!', 'success');

        // Keep modal open and pre-fill form for adding more questions in same category
        // Store values in session to re-populate form
        $_SESSION['last_question_category'] = $_POST['category'] ?? '';
        $_SESSION['last_question_sub_category'] = $_POST['sub_category'] ?? '';
        $_SESSION['last_question_type'] = $_POST['question_type'] ?? 'rating';
        $_SESSION['last_question_group'] = $_POST['question_group'] ?? '';
        $_SESSION['last_question_label'] = $_POST['question_label'] ?? '';
        $_SESSION['last_target_staff_category'] = $_POST['target_staff_category'] ?? 'both';
        $_SESSION['keep_modal_open'] = true;

        // Redirect back to same page with filter to keep modal open
        $redirectFilter = $_GET['filter'] ?? 'all';
        redirect('questions.php' . ($redirectFilter !== 'all' ? '?filter=' . $redirectFilter : ''));
    }

    // Handle question and category reordering
    if (isset($_POST['reorder_questions'])) {
        // Save question orders and move questions between categories
        if (isset($_POST['question_orders'])) {
            $orders = $_POST['question_orders'];
            $categories = $_POST['question_categories'] ?? [];
            foreach ($orders as $questionId => $order) {
                $newCategory = $categories[$questionId] ?? null;
                if ($newCategory) {
                    // Update both order and category (for drag between sections)
                    $stmt = $pdo->prepare("UPDATE evaluation_questions SET question_order = ?, category = ? WHERE id = ?");
                    $stmt->execute([intval($order), sanitize($newCategory), intval($questionId)]);
                } else {
                    // Just update order
                    $stmt = $pdo->prepare("UPDATE evaluation_questions SET question_order = ? WHERE id = ?");
                    $stmt->execute([intval($order), intval($questionId)]);
                }
            }
        }

        // Save category/section orders
        if (isset($_POST['category_orders'])) {
            $catOrders = $_POST['category_orders'];
            foreach ($catOrders as $categoryName => $order) {
                $stmt = $pdo->prepare("UPDATE evaluation_questions SET category_order = ? WHERE category = ?");
                $stmt->execute([intval($order), $categoryName]);
            }
        }

        showMessage('Order saved successfully!', 'success');
    }

    if (isset($_POST['update_question'])) {
        $questionType = $_POST['question_type'];
        $options = '';

        if ($questionType === 'multiple_choice' || $questionType === 'single_choice') {
            $options = sanitize($_POST['options']);
        }

        // Handle category - text input now accepts any value directly
        $category = sanitize($_POST['category'] ?? '');

        // Handle sub-category (custom sub-category)
        $subCategory = null;
        if (!empty($_POST['sub_category']) && $_POST['sub_category'] !== '') {
            if ($_POST['sub_category'] === 'custom' && !empty($_POST['custom_sub_category'])) {
                $subCategory = sanitize($_POST['custom_sub_category']);
            } elseif ($_POST['sub_category'] !== 'custom') {
                $subCategory = sanitize($_POST['sub_category']);
            }
        }

        // Handle file upload settings
        $allowedFileTypes = 'pdf,doc,docx';
        $maxFileSize = 5;
        if ($questionType === 'file_upload') {
            $allowedFileTypes = sanitize($_POST['allowed_file_types'] ?? 'pdf,doc,docx');
            $maxFileSize = intval($_POST['max_file_size'] ?? 5);
        }

        // Handle question group and label
        $questionGroup = !empty($_POST['question_group']) ? sanitize($_POST['question_group']) : null;
        $questionLabel = !empty($_POST['question_label']) ? sanitize($_POST['question_label']) : null;
        $questionOrder = intval($_POST['question_order'] ?? 0);

        // Build query based on available columns
        if ($hasQuestionLabel) {
            $stmt = $pdo->prepare("UPDATE evaluation_questions SET category = ?, sub_category = ?, question_text = ?, question_type = ?, options = ?, is_active = ?, target_staff_category = ?, allowed_file_types = ?, max_file_size = ?, question_group = ?, question_label = ?, question_order = ? WHERE id = ?");
            $stmt->execute([
                $category,
                $subCategory,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                isset($_POST['is_active']) ? 1 : 0,
                sanitize($_POST['target_staff_category'] ?? 'both'),
                $allowedFileTypes,
                $maxFileSize,
                $questionGroup,
                $questionLabel,
                $questionOrder,
                intval($_POST['question_id'])
            ]);
        } elseif ($hasSubCategory) {
            $stmt = $pdo->prepare("UPDATE evaluation_questions SET category = ?, sub_category = ?, question_text = ?, question_type = ?, options = ?, is_active = ?, target_staff_category = ? WHERE id = ?");
            $stmt->execute([
                $category,
                $subCategory,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                isset($_POST['is_active']) ? 1 : 0,
                sanitize($_POST['target_staff_category'] ?? 'both'),
                intval($_POST['question_id'])
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE evaluation_questions SET category = ?, question_text = ?, question_type = ?, options = ?, is_active = ?, target_staff_category = ? WHERE id = ?");
            $stmt->execute([
                $category,
                sanitize($_POST['question_text']),
                $questionType,
                $options,
                isset($_POST['is_active']) ? 1 : 0,
                sanitize($_POST['target_staff_category'] ?? 'both'),
                intval($_POST['question_id'])
            ]);
        }
        showMessage('Question updated successfully!', 'success');
    }

    if (isset($_POST['delete_question'])) {
        $stmt = $pdo->prepare("DELETE FROM evaluation_questions WHERE id = ?");
        $stmt->execute([intval($_POST['question_id'])]);
        showMessage('Question deleted successfully!', 'success');
    }

    // Handle bulk delete by selected question IDs
    if (isset($_POST['bulk_delete_selected'])) {
        $selectedInput = $_POST['selected_questions'] ?? '';

        // Handle both array and comma-separated string
        if (is_array($selectedInput)) {
            $selectedIds = $selectedInput;
        } else {
            $selectedIds = array_filter(array_map('trim', explode(',', $selectedInput)));
        }

        if (!empty($selectedIds)) {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM evaluation_questions WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $selectedIds));
            showMessage(count($selectedIds) . ' question(s) deleted successfully!', 'success');
        } else {
            showMessage('No questions selected for deletion.', 'warning');
        }
        redirect('questions.php' . ($filterCategory !== 'all' ? '?filter=' . $filterCategory : ''));
    }

    // Handle bulk delete by category
    if (isset($_POST['bulk_delete_category'])) {
        $deleteCategory = $_POST['delete_category'] ?? '';
        $deleteStaffCategory = $_POST['delete_staff_category'] ?? 'all';

        if (empty($deleteCategory) && $deleteCategory !== 'all') {
            showMessage('Please select a category to delete.', 'warning');
            redirect('questions.php');
        }

        // Build the WHERE clause based on filters
        $params = [];
        $whereClause = "1=1";

        if ($deleteCategory !== 'all' && !empty($deleteCategory)) {
            $whereClause .= " AND category = ?";
            $params[] = $deleteCategory;
        }

        if ($deleteStaffCategory !== 'all') {
            $whereClause .= " AND target_staff_category = ?";
            $params[] = $deleteStaffCategory;
        }

        // Get count before deletion
        $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM evaluation_questions WHERE $whereClause");
        $countStmt->execute($params);
        $count = $countStmt->fetch()['cnt'];

        // Delete the questions
        $deleteStmt = $pdo->prepare("DELETE FROM evaluation_questions WHERE $whereClause");
        $deleteStmt->execute($params);

        showMessage($count . ' question(s) deleted from category "' . htmlspecialchars($deleteCategory) . '"!', 'success');
        redirect('questions.php' . ($filterCategory !== 'all' ? '?filter=' . $filterCategory : ''));
    }

    redirect('questions.php');
}

// Get filter category
$filterCategory = $_GET['filter'] ?? 'all';

// Check if we should keep modal open after adding question
$keepModalOpen = isset($_SESSION['keep_modal_open']) && $_SESSION['keep_modal_open'];
$lastCategory = $_SESSION['last_question_category'] ?? '';
$lastSubCategory = $_SESSION['last_question_sub_category'] ?? '';
$lastQuestionType = $_SESSION['last_question_type'] ?? 'rating';
$lastQuestionGroup = $_SESSION['last_question_group'] ?? '';
$lastQuestionLabel = $_SESSION['last_question_label'] ?? '';
$lastTargetStaff = $_SESSION['last_target_staff_category'] ?? 'both';

// Clear the flag after reading
unset($_SESSION['keep_modal_open']);

// Get reorder filter category
$reorderFilter = $_GET['reorder_filter'] ?? 'all';

// Get all existing categories from database for dropdown
$allCategories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM evaluation_questions WHERE category IS NOT NULL AND category != '' ORDER BY category");
    while ($row = $stmt->fetch()) {
        $allCategories[] = $row['category'];
    }
} catch (Exception $e) {
    // Use default categories if table doesn't exist
}

// Default categories
$defaultCategories = ['Teaching', 'Research', 'Administrative', 'Community', 'Professional'];

// Merge default + custom categories (remove duplicates)
$categoryOptions = array_unique(array_merge($defaultCategories, $allCategories));
sort($categoryOptions);

// Get sub-categories for dropdown (if table exists)
$subCategories = [];
$subCategoriesByCategory = [];
$allSubCategoriesList = [];
try {
    $stmt = $pdo->query("SELECT * FROM question_sub_categories WHERE is_active = 1 ORDER BY category, sub_category_order");
    $subCategories = $stmt->fetchAll();
    // Group sub-categories by category
    foreach ($subCategories as $sc) {
        $subCategoriesByCategory[$sc['category']][] = $sc;
        $allSubCategoriesList[] = $sc['sub_category_name'];
    }
} catch (Exception $e) {
    // Table doesn't exist yet - sub-categories will be empty
}

// Build query based on filter - Each category shows its specific questions + "both" (all staff) questions
if ($filterCategory === 'supervising-officer') {
    // Supervising Officer - all SO categories (including S.O_academic, S.O_senior, S.O_junior, S.O)
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'S.O_junior') {
    // SO: Junior Staff questions + generic S.O questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('S.O_junior', 'S.O')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'S.O_senior') {
    // SO: Non-Teaching Senior questions + generic S.O questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('S.O_senior', 'S.O')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'S.O_academic') {
    // SO: Academic Staff questions + generic S.O questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('S.O_academic', 'S.O')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'S.O') {
    // All SO questions (including S.O_academic, S.O_senior, S.O_junior, S.O)
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'academic') {
    // Academic: specific academic questions + "both" (all staff) questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('academic', 'both')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'non-teaching') {
    // Non-Teaching Senior: specific non-teaching + "both" questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('non-teaching', 'both')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} elseif ($filterCategory === 'non-teaching-junior') {
    // Junior Staff: specific junior + "both" questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category IN ('non-teaching-junior', 'both')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
} else {
    // All questions EXCEPT Supervising Officer - for general staff questions
    $stmt = $pdo->query("SELECT * FROM evaluation_questions
        WHERE is_active = 1 AND target_staff_category NOT IN ('S.O', 'S.O_junior', 'S.O_senior', 'S.O_academic')
        ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
}
$questions = $stmt->fetchAll();

// DEBUG: Show filter info
error_log("Filter: $filterCategory, Questions found: " . count($questions));

$questionsByCategory = [];
foreach ($questions as $q) {
    $questionsByCategory[$q['category']][] = $q;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Questions - <?php echo htmlspecialchars($instName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="theme-overrides.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-blue: <?php echo $settings['primary_color'] ?? '#247d57'; ?>; }
        body { background: #f3f4f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #247d57 0%, #1a5238 100%); color: white; }
        .sidebar a { color: rgba(255,255,255,0.8); text-decoration: none; padding: 12px 15px; display: block; border-radius: 8px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.15); color: white; }
        /* Fix for modal dropdowns */
        .modal { z-index: 9999 !important; }
        .modal-backdrop { z-index: 9998 !important; }
        .modal.show { display: block !important; }
        .dropdown-menu { z-index: 10000 !important; }
        .form-select { cursor: pointer; }
        select.form-select { cursor: pointer; -webkit-appearance: auto; -moz-appearance: auto; appearance: auto; }
        /* SO Badge Colors */
        .badge.bg-purple { background-color: #6f42c1 !important; color: white !important; }
        .badge.bg-orange { background-color: #fd7e14 !important; color: white !important; }
        .badge.bg-teal { background-color: #20c997 !important; color: white !important; }
        .badge.bg-so { background-color: #dc3545 !important; color: white !important; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center sidebar-header">
                    <?php if (!empty($logo)): ?>
                        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" style="max-height: 55px; margin-bottom: 10px;">
                    <?php else: ?>
                        <i class="fas fa-graduation-cap fa-2x mb-2" style="font-size: 2rem;"></i>
                    <?php endif; ?>
                    <h5 class="mb-0" style="font-weight: 800;"><?php echo htmlspecialchars($instName); ?></h5>
                    <?php if (!empty($instAddress)): ?>
                        <small class="d-block" style="max-width: 180px; margin: 5px auto 0; font-weight: 600;"><?php echo htmlspecialchars($instAddress); ?></small>
                    <?php endif; ?>
                </div>
                <div class="py-3">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    <a href="staff.php"><i class="fas fa-users"></i> Staff</a>
                    <a href="staff-upload.php"><i class="fas fa-upload"></i> Upload Staff</a>
                    <a href="manage-evaluators.php"><i class="fas fa-user-tie"></i> Evaluators</a>
                    <a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Questions</a>
                    <a href="question-sub-categories.php"><i class="fas fa-folder-tree"></i> Sub-Categories</a>
                    <a href="evaluate.php"><i class="fas fa-clipboard-check"></i> Evaluate</a>
                    <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
                    <?php if (hasPermission('download_all_data')): ?>
                    <a href="download-data.php"><i class="fas fa-download"></i> Download Data</a>
                    <?php endif; ?>
                    <a href="sessions.php"><i class="fas fa-calendar"></i> Sessions</a>
                    <a href="logout.php" class="text-warning"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show">
                        <?php echo $message['message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-question-circle me-2"></i>Questions</h2>
                    <div class="d-flex gap-2">
                        <!-- Questions Category Dropdown - Clear Categories -->
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-folder-open me-2"></i>
                                <?php
                                // Show current category name
                                $categoryNames = [
                                    'academic' => 'Academic Staff',
                                    'non-teaching' => 'Non-Teaching Senior',
                                    'non-teaching-junior' => 'Junior Staff',
                                    'S.O_academic' => 'Supervising Officer (Academic)',
                                    'S.O_senior' => 'Supervising Officer (Senior)',
                                    'S.O_junior' => 'Supervising Officer (Junior)'
                                ];
                                echo $categoryNames[$filterCategory] ?? 'Select Category';
                                ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><h6 class="dropdown-header"><i class="fas fa-graduation-cap me-2"></i>Staff Self-Evaluation</h6></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'academic' ? 'active' : ''; ?>" href="questions.php?filter=academic">
                                    <i class="fas fa-chalkboard-teacher me-2"></i>Academic Staff Questions
                                </a></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'non-teaching' ? 'active' : ''; ?>" href="questions.php?filter=non-teaching">
                                    <i class="fas fa-briefcase me-2"></i>Non-Teaching Senior (Level 6+)
                                </a></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'non-teaching-junior' ? 'active' : ''; ?>" href="questions.php?filter=non-teaching-junior">
                                    <i class="fas fa-user-plus me-2"></i>Junior Staff (Level 5 & below)
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><i class="fas fa-user-tie me-2"></i>Supervising Officer Evaluation</h6></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'supervising-officer' ? 'active' : ''; ?>" href="questions.php?filter=supervising-officer">
                                    <i class="fas fa-users me-2"></i>All SO Questions
                                </a></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'S.O_academic' ? 'active' : ''; ?>" href="questions.php?filter=S.O_academic">
                                    <i class="fas fa-user-graduate me-2"></i>For Academic Staff
                                </a></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'S.O_senior' ? 'active' : ''; ?>" href="questions.php?filter=S.O_senior">
                                    <i class="fas fa-user-clock me-2"></i>For Non-Teaching Senior
                                </a></li>
                                <li><a class="dropdown-item <?php echo $filterCategory === 'S.O_junior' ? 'active' : ''; ?>" href="questions.php?filter=S.O_junior">
                                    <i class="fas fa-user-check me-2"></i>For Junior Staff
                                </a></li>
                            </ul>
                        </div>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                            <i class="fas fa-plus me-2"></i>Add Question
                        </button>
                        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#reorderQuestionsModal">
                            <i class="fas fa-sort-numeric-up me-2"></i>Reorder
                        </button>
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
                            <i class="fas fa-trash-alt me-2"></i>Bulk Delete
                        </button>
                    </div>
                </div>

                <!-- Current Category Info -->
                <div class="alert alert-<?php echo in_array($filterCategory, ['academic', 'non-teaching', 'non-teaching-junior', 'supervising-officer', 'S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior']) ? 'info' : 'warning'; ?> mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Viewing:
                    <?php if ($filterCategory === 'academic'): ?>
                        Academic Staff Questions - Questions that academic staff answer for self-evaluation
                    <?php elseif ($filterCategory === 'non-teaching'): ?>
                        Non-Teaching Senior Questions - Questions for Level 6+ non-teaching staff
                    <?php elseif ($filterCategory === 'non-teaching-junior'): ?>
                        Junior Staff Questions - Questions for Level 5 and below staff
                    <?php elseif ($filterCategory === 'supervising-officer'): ?>
                        All Supervising Officer Questions - Questions SO uses to evaluate all staff categories
                    <?php elseif ($filterCategory === 'S.O_academic'): ?>
                        Supervising Officer Questions (Academic) - Questions SO uses to evaluate academic staff
                    <?php elseif ($filterCategory === 'S.O_senior'): ?>
                        Supervising Officer Questions (Senior) - Questions SO uses to evaluate senior non-teaching staff
                    <?php elseif ($filterCategory === 'S.O_junior'): ?>
                        Supervising Officer Questions (Junior) - Questions SO uses to evaluate junior staff
                    <?php else: ?>
                        All Questions
                    <?php endif; ?>
                    </strong>
                </div>

                <?php if ($filterCategory === 'supervising-officer' || $filterCategory === 'S.O'): ?>
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Supervising Officer Evaluation Questions:</strong> These are the questions that Supervising Officers will use to evaluate their staff.
                    You can add, edit, or delete these questions. The questions are grouped by category for easy management.
                </div>
                <?php endif; ?>

                <p class="text-muted mb-4">
                    Customize the questions that staff will answer in their self-evaluation form.
                    You can add, edit, or delete questions for each category.
                </p>

                <!-- Questions by Category -->
                <?php if (empty($questionsByCategory)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No questions found for the selected filter.
                    <a href="questions.php?filter=all">View all questions</a> or
                    <a href="#" data-bs-toggle="modal" data-bs-target="#addQuestionModal">add a new question</a>.
                </div>
                <?php else: ?>
                <?php foreach ($questionsByCategory as $category => $categoryQuestions): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($category); ?> (<?php echo count($categoryQuestions); ?> questions)</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                                    <th>Question Type</th>
                                    <th>Group/Label</th>
                                    <th>Sub-Category</th>
                                    <th>Question</th>
                                    <th>Target Staff</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryQuestions as $q):
                                    $typeLabels = [
                                        'rating' => '⭐ Rating',
                                        'single_choice' => '☐ Single',
                                        'multiple_choice' => '☑ Multiple',
                                        'true_false' => '✓ True/False',
                                        'short_answer' => '✎ Short',
                                        'long_answer' => '📝 Essay',
                                        'yes_no' => 'Yes/No',
                                        'scale' => '📏 Scale',
                                        'file_upload' => '📎 Upload'
                                    ];
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_questions[]" value="<?php echo $q['id']; ?>" class="question-checkbox"></td>
                                    <td><span class="badge bg-info"><?php echo $typeLabels[$q['question_type']] ?? $q['question_type']; ?></span></td>
                                    <td>
                                        <?php if (!empty($q['question_group']) || !empty($q['question_label'])): ?>
                                            <?php if (!empty($q['question_group'])): ?>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($q['question_group']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($q['question_label'])): ?>
                                                <span class="badge bg-warning text-dark">(<?php echo htmlspecialchars($q['question_label']); ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($q['sub_category']) ? '<span class="badge bg-secondary">' . htmlspecialchars($q['sub_category']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($q['question_text']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php
                                            $target = $q['target_staff_category'] ?? 'both';
                                            if ($target === 'S.O_academic') {
                                                echo 'purple'; // Purple for SO Academic
                                            } elseif ($target === 'S.O_senior') {
                                                echo 'orange'; // Orange for SO Senior
                                            } elseif ($target === 'S.O_junior') {
                                                echo 'teal'; // Teal for SO Junior
                                            } elseif (strpos($target, 'S.O') === 0 || $target === 'S.O') {
                                                echo 'danger'; // Red for generic SO
                                            } elseif ($target === 'both') {
                                                echo 'primary'; // Blue for all staff
                                            } elseif ($target === 'academic') {
                                                echo 'success'; // Green for academic
                                            } elseif ($target === 'non-teaching') {
                                                echo 'warning'; // Yellow for non-teaching senior
                                            } else {
                                                echo 'info'; // Light blue for junior
                                            }
                                        ?>">
                                            <?php
                                                $target = $q['target_staff_category'] ?? 'both';
                                                if ($target === 'S.O_academic') {
                                                    echo 'SO: Academic';
                                                } elseif ($target === 'S.O_senior') {
                                                    echo 'SO: Senior';
                                                } elseif ($target === 'S.O_junior') {
                                                    echo 'SO: Junior';
                                                } elseif (strpos($target, 'S.O') === 0 || $target === 'S.O') {
                                                    echo 'SO: All';
                                                } else {
                                                    echo $target == 'both' ? 'All Staff' : ($target == 'academic' ? 'Academic' : ($target == 'non-teaching-junior' ? 'Junior Staff (L5)' : ($target == 'non-teaching' ? 'Non-Teaching Senior (L6+)' : 'Non-Teaching')));
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $q['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $q['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editQuestionModal<?php echo $q['id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" name="delete_question" class="btn btn-sm btn-danger" onclick="return confirm('Delete this question?');">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editQuestionModal<?php echo $q['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Question</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Category</label>
                                                            <input type="text" class="form-control" name="category" id="edit_category_<?php echo $q['id']; ?>" list="categoryListEdit<?php echo $q['id']; ?>" value="<?php echo htmlspecialchars($q['category'] ?? ''); ?>" required>
                                                            <datalist id="categoryListEdit<?php echo $q['id']; ?>">
                                                                <?php
                                                                // Get all unique categories from database
                                                                $allCategoriesEdit = ['Teaching', 'Research', 'Administrative', 'Community', 'Professional'];
                                                                $stmt = $pdo->query("SELECT DISTINCT category FROM evaluation_questions WHERE category IS NOT NULL AND category != '' ORDER BY category");
                                                                while ($row = $stmt->fetch()) {
                                                                    if (!in_array($row['category'], $allCategoriesEdit)) {
                                                                        $allCategoriesEdit[] = $row['category'];
                                                                    }
                                                                }
                                                                foreach ($allCategoriesEdit as $cat): ?>
                                                                <option value="<?php echo htmlspecialchars($cat); ?>">
                                                                <?php endforeach; ?>
                                                            </datalist>
                                                            <small class="text-muted">Type a new category or select from existing</small>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Sub-Category</label>
                                                            <select class="form-select" name="sub_category" id="edit_sub_category_<?php echo $q['id']; ?>">
                                                                <option value="">None</option>
                                                                <?php
                                                                // Get ALL sub-categories from database
                                                                $allSubCategories = [];
                                                                try {
                                                                    $stmt = $pdo->query("SELECT DISTINCT sub_category_name, category FROM question_sub_categories WHERE is_active = 1 ORDER BY sub_category_name");
                                                                    while ($sc = $stmt->fetch()) {
                                                                        if (!empty($sc['sub_category_name'])) {
                                                                            $allSubCategories[] = $sc;
                                                                        }
                                                                    }
                                                                } catch (Exception $e) {}
                                                                // Show all sub-categories as options
                                                                foreach ($allSubCategories as $sc): ?>
                                                                <option value="<?php echo htmlspecialchars($sc['sub_category_name']); ?>" <?php echo ($q['sub_category'] ?? '') == $sc['sub_category_name'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($sc['sub_category_name']); ?>
                                                                </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Question Type</label>
                                                            <select class="form-select" name="question_type" id="edit_type_<?php echo $q['id']; ?>" onchange="toggleOptionsField(this, 'edit_<?php echo $q['id']; ?>')">
                                                                <option value="rating" <?php echo $q['question_type'] == 'rating' ? 'selected' : ''; ?>>⭐ Rating (1-5 Stars)</option>
                                                                <option value="single_choice" <?php echo $q['question_type'] == 'single_choice' ? 'selected' : ''; ?>>☐ Single Choice</option>
                                                                <option value="multiple_choice" <?php echo $q['question_type'] == 'multiple_choice' ? 'selected' : ''; ?>>☑ Multiple Choice</option>
                                                                <option value="true_false" <?php echo $q['question_type'] == 'true_false' ? 'selected' : ''; ?>>✓ True / False</option>
                                                                <option value="short_answer" <?php echo $q['question_type'] == 'short_answer' ? 'selected' : ''; ?>>✎ Short Answer</option>
                                                                <option value="long_answer" <?php echo $q['question_type'] == 'long_answer' ? 'selected' : ''; ?>>📝 Long Response</option>
                                                                <option value="yes_no" <?php echo $q['question_type'] == 'yes_no' ? 'selected' : ''; ?>>Yes / No</option>
                                                                <option value="scale" <?php echo $q['question_type'] == 'scale' ? 'selected' : ''; ?>>📏 Scale (1-10)</option>
                                                                <option value="file_upload" <?php echo ($q['question_type'] ?? '') == 'file_upload' ? 'selected' : ''; ?>>📎 File Upload</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Staff Category</label>
                                                            <select class="form-select" name="target_staff_category">
                                                                <optgroup label="Staff Self-Evaluation">
                                                                    <option value="both" <?php echo ($q['target_staff_category'] ?? 'both') == 'both' ? 'selected' : ''; ?>>All Staff (Both)</option>
                                                                    <option value="academic" <?php echo ($q['target_staff_category'] ?? '') == 'academic' ? 'selected' : ''; ?>>Academic Staff Only</option>
                                                                    <option value="non-teaching" <?php echo ($q['target_staff_category'] ?? '') == 'non-teaching' ? 'selected' : ''; ?>>Non-Teaching Senior (Level 6+)</option>
                                                                    <option value="non-teaching-junior" <?php echo ($q['target_staff_category'] ?? '') == 'non-teaching-junior' ? 'selected' : ''; ?>>Junior Staff (Level 5 & below)</option>
                                                                </optgroup>
                                                                <optgroup label="Supervising Officer Evaluation">
                                                                    <option value="S.O_academic" <?php echo ($q['target_staff_category'] ?? '') == 'S.O_academic' ? 'selected' : ''; ?>>SO: For Academic Staff</option>
                                                                    <option value="S.O_senior" <?php echo ($q['target_staff_category'] ?? '') == 'S.O_senior' ? 'selected' : ''; ?>>SO: For Non-Teaching Senior</option>
                                                                    <option value="S.O_junior" <?php echo ($q['target_staff_category'] ?? '') == 'S.O_junior' ? 'selected' : ''; ?>>SO: For Junior Staff</option>
                                                                    <option value="S.O" <?php echo ($q['target_staff_category'] ?? '') == 'S.O' ? 'selected' : ''; ?>>SO: All Categories</option>
                                                                    <option value="supervising-officer" <?php echo ($q['target_staff_category'] ?? '') == 'supervising-officer' ? 'selected' : ''; ?>>SO: All SO Questions</option>
                                                                </optgroup>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Question Text</label>
                                                        <input type="text" class="form-control" name="question_text" value="<?php echo htmlspecialchars($q['question_text']); ?>" required>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Question Group</label>
                                                            <input type="text" class="form-control" name="question_group" value="<?php echo htmlspecialchars($q['question_group'] ?? ''); ?>" placeholder="e.g., Publications">
                                                            <small class="text-muted">Groups related questions together</small>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Question Label</label>
                                                            <input type="text" class="form-control" name="question_label" value="<?php echo htmlspecialchars($q['question_label'] ?? ''); ?>" placeholder="e.g., a, b, c, I, II, III">
                                                            <small class="text-muted">Add label like (a), (b), I, II for sub-parts</small>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Display Order</label>
                                                            <input type="number" class="form-control" name="question_order" value="<?php echo $q['question_order'] ?? 0; ?>" min="0">
                                                            <small class="text-muted">Questions with lower numbers appear first</small>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3" id="edit_<?php echo $q['id']; ?>_options_field" <?php echo ($q['question_type'] != 'single_choice' && $q['question_type'] != 'multiple_choice') ? 'style="display:none;"' : ''; ?>>
                                                        <label class="form-label">Options (one per line)</label>
                                                        <textarea class="form-control" name="options" rows="4"><?php echo htmlspecialchars($q['options'] ?? ''); ?></textarea>
                                                    </div>
                                                    <div class="mb-3" id="edit_<?php echo $q['id']; ?>_upload_field" <?php echo ($q['question_type'] ?? '') != 'file_upload' ? 'style="display:none;"' : ''; ?>>
                                                        <label class="form-label">Allowed File Types</label>
                                                        <input type="text" class="form-control" name="allowed_file_types" value="<?php echo htmlspecialchars($q['allowed_file_types'] ?? 'pdf,doc,docx'); ?>" placeholder="pdf,doc,docx">
                                                        <small class="text-muted">Comma-separated list (e.g., pdf,doc,docx,jpg,png)</small>
                                                    </div>
                                                    <div class="mb-3" id="edit_<?php echo $q['id']; ?>_size_field" <?php echo ($q['question_type'] ?? '') != 'file_upload' ? 'style="display:none;"' : ''; ?>>
                                                        <label class="form-label">Max File Size (MB)</label>
                                                        <input type="number" class="form-control" name="max_file_size" value="<?php echo htmlspecialchars($q['max_file_size'] ?? 5); ?>" min="1" max="50">
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12 mb-3">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="is_active" id="active<?php echo $q['id']; ?>" <?php echo $q['is_active'] ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="active<?php echo $q['id']; ?>">Active (include in form)</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="update_question" class="btn btn-primary">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php
                $activeCount = 0;
                foreach ($questions as $q) {
                    if (isset($q['is_active']) && $q['is_active'] == 1) {
                        $activeCount++;
                    }
                }
                ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> The total max score will automatically adjust based on the number of active questions.
                    Currently: <strong><?php echo $activeCount; ?></strong> active questions (out of <?php echo count($questions); ?> total).
                </div>
            </div>
        </div>
    </div>

    <!-- Add Question Modal -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Question</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category/Section</label>
                                <input type="text" class="form-control" name="category" id="add_category" list="categoryList" placeholder="Type or select category" required>
                                <datalist id="categoryList">
                                    <?php foreach ($categoryOptions as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Type a new category or select from existing</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Sub-Category</label>
                                <select class="form-select" name="sub_category" id="add_sub_category" onchange="toggleCustomSubCategory(this)">
                                    <option value="">Select Sub-Category (Optional)</option>
                                    <?php
                                    // Group sub-categories by their parent category
                                    $groupedSubCats = [];
                                    foreach ($subCategories as $sc) {
                                        if (!isset($groupedSubCats[$sc['category']])) {
                                            $groupedSubCats[$sc['category']] = [];
                                        }
                                        $groupedSubCats[$sc['category']][] = $sc['sub_category_name'];
                                    }
                                    foreach ($groupedSubCats as $parentCat => $subCats):
                                    ?>
                                    <optgroup label="<?php echo htmlspecialchars($parentCat); ?>">
                                        <?php foreach ($subCats as $subCat): ?>
                                        <option value="<?php echo htmlspecialchars($subCat); ?>"><?php echo htmlspecialchars($subCat); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                    <option value="custom">+ Add New Sub-Category</option>
                                </select>
                                <input type="text" class="form-control mt-2" name="custom_sub_category" id="add_custom_sub_category" placeholder="Enter new sub-category name" style="display:none;">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Question Type</label>
                                <select class="form-select" name="question_type" id="add_question_type" onchange="toggleOptionsField(this, 'add')" required>
                                    <option value="rating">⭐ Rating (1-5 Stars)</option>
                                    <option value="single_choice">☐ Single Choice (Radio)</option>
                                    <option value="multiple_choice">☐ Multiple Choice (Checkboxes)</option>
                                    <option value="true_false">✓ True / False</option>
                                    <option value="short_answer">✎ Short Answer</option>
                                    <option value="long_answer">📝 Long Response (Essay)</option>
                                    <option value="yes_no">Yes / No</option>
                                    <option value="scale">📏 Scale (1-10)</option>
                                    <option value="file_upload">📎 File Upload</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Staff Category</label>
                                <select class="form-select" name="target_staff_category" required>
                                    <optgroup label="Staff Self-Evaluation">
                                        <option value="both" <?php echo $filterCategory === 'all' ? 'selected' : ''; ?>>All Staff (Both)</option>
                                        <option value="academic" <?php echo $filterCategory === 'academic' ? 'selected' : ''; ?>>Academic Staff Only</option>
                                        <option value="non-teaching" <?php echo $filterCategory === 'non-teaching' ? 'selected' : ''; ?>>Non-Teaching Senior (Level 6+)</option>
                                        <option value="non-teaching-junior" <?php echo $filterCategory === 'non-teaching-junior' ? 'selected' : ''; ?>>Junior Staff (Level 5 & below)</option>
                                    </optgroup>
                                    <optgroup label="Supervising Officer Evaluation">
                                        <option value="S.O_academic" <?php echo $filterCategory === 'S.O_academic' ? 'selected' : ''; ?>>SO: For Academic Staff</option>
                                        <option value="S.O_senior" <?php echo $filterCategory === 'S.O_senior' ? 'selected' : ''; ?>>SO: For Non-Teaching Senior</option>
                                        <option value="S.O_junior" <?php echo $filterCategory === 'S.O_junior' ? 'selected' : ''; ?>>SO: For Junior Staff</option>
                                        <option value="S.O" <?php echo $filterCategory === 'S.O' ? 'selected' : ''; ?>>SO: All Categories</option>
                                        <option value="supervising-officer" <?php echo $filterCategory === 'supervising-officer' ? 'selected' : ''; ?>>SO: All SO Questions</option>
                                    </optgroup>
                                </select>
                                <small class="text-muted">Which staff type sees this question. Junior Staff = Level 5 and below non-teaching staff.</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Question Group (Optional)</label>
                                <input type="text" class="form-control" name="question_group" placeholder="e.g., Publications (groups related questions)">
                                <small class="text-muted">Group related questions together</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Question Label (Optional)</label>
                                <input type="text" class="form-control" name="question_label" placeholder="e.g., a, b, c, I, II, III">
                                <small class="text-muted">Add label like (a), (b), I, II for sub-parts</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <input type="text" class="form-control" name="question_text" placeholder="e.g., How would you rate your teaching performance?" required>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Display Order</label>
                                <input type="number" class="form-control" name="question_order" value="0" min="0" placeholder="Order number">
                                <small class="text-muted">Questions with lower numbers appear first</small>
                            </div>
                        </div>
                        <div class="mb-3" id="add_options_field" style="display:none;">
                            <label class="form-label">Options (one per line)</label>
                            <textarea class="form-control" name="options" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                            <small class="text-muted">Enter each option on a new line</small>
                        </div>
                        <div class="mb-3" id="add_upload_field" style="display:none;">
                            <label class="form-label">Allowed File Types</label>
                            <input type="text" class="form-control" name="allowed_file_types" value="pdf,doc,docx" placeholder="pdf,doc,docx">
                            <small class="text-muted">Comma-separated list (e.g., pdf,doc,docx,jpg,png)</small>
                        </div>
                        <div class="mb-3" id="add_size_field" style="display:none;">
                            <label class="form-label">Max File Size (MB)</label>
                            <input type="number" class="form-control" name="max_file_size" value="5" min="1" max="50">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_question" class="btn btn-primary">Add Question</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Modal -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Bulk Delete Questions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#delete-selected-tab" type="button">
                                <i class="fas fa-check-square me-1"></i> Delete Selected
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#delete-by-category-tab" type="button">
                                <i class="fas fa-folder me-1"></i> Delete by Category
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Delete Selected Questions Tab -->
                        <div class="tab-pane fade show active" id="delete-selected-tab">
                            <p class="text-muted mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Select questions by checking the boxes in the table above, then click "Delete Selected" below.
                            </p>
                            <div class="alert alert-info">
                                <strong>Selected:</strong> <span id="selectedCount">0</span> question(s)
                            </div>
                            <form method="POST" id="bulkDeleteSelectedForm">
                                <input type="hidden" name="selected_questions" id="selectedQuestionsInput" value="">
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="bulk_delete_selected" id="bulkDeleteSelectedBtn" class="btn btn-danger" disabled onclick="prepareSelectedQuestions()">
                                        <i class="fas fa-trash me-2"></i>Delete Selected
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Delete by Category Tab -->
                        <div class="tab-pane fade" id="delete-by-category-tab">
                            <p class="text-muted mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Delete all questions from a specific category. Use the filters to target specific staff categories.
                            </p>
                            <form method="POST" id="bulkDeleteCategoryForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Category to Delete</label>
                                        <select class="form-select" name="delete_category" required>
                                            <option value="">Select Category</option>
                                            <option value="all">All Categories</option>
                                            <?php foreach ($categoryOptions as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Target Staff Category (Optional)</label>
                                        <select class="form-select" name="delete_staff_category">
                                            <option value="all">All Staff Categories</option>
                                            <optgroup label="Staff Self-Evaluation">
                                                <option value="both">All Staff (Both)</option>
                                                <option value="academic">Academic Staff Only</option>
                                                <option value="non-teaching">Non-Teaching Senior</option>
                                                <option value="non-teaching-junior">Junior Staff</option>
                                            </optgroup>
                                            <optgroup label="Supervising Officer">
                                                <option value="S.O_academic">SO: Academic Staff</option>
                                                <option value="S.O_senior">SO: Non-Teaching Senior</option>
                                                <option value="S.O_junior">SO: Junior Staff</option>
                                                <option value="S.O">SO: All Categories</option>
                                            </optgroup>
                                        </select>
                                        <small class="text-muted">Leave as "All" to delete regardless of staff type</small>
                                    </div>
                                </div>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Warning:</strong> This action cannot be undone! All matching questions will be permanently deleted.
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="bulk_delete_category" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete all questions in the selected category? This cannot be undone!');">
                                        <i class="fas fa-trash me-2"></i>Delete by Category
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleOptionsField(selectElem, prefix) {
        var questionType = selectElem.value;
        var optionsField = document.getElementById(prefix + '_options_field');
        var uploadField = document.getElementById(prefix + '_upload_field');
        var sizeField = document.getElementById(prefix + '_size_field');

        if (questionType === 'single_choice' || questionType === 'multiple_choice') {
            optionsField.style.display = 'block';
        } else {
            optionsField.style.display = 'none';
        }

        // Handle file upload fields
        if (questionType === 'file_upload') {
            if (uploadField) uploadField.style.display = 'block';
            if (sizeField) sizeField.style.display = 'block';
        } else {
            if (uploadField) uploadField.style.display = 'none';
            if (sizeField) sizeField.style.display = 'none';
        }
    }
    function toggleCustomCategory(selectElem, prefix) {
        var customField = document.getElementById(prefix + '_custom_category');
        if (selectElem.value === 'custom') {
            customField.style.display = 'block';
            customField.required = true;
        } else {
            customField.style.display = 'none';
            customField.required = false;
            customField.value = '';
        }
    }
    function toggleCustomSubCategory(selectElem) {
        var customField = document.getElementById('add_custom_sub_category');
        if (selectElem.value === 'custom') {
            customField.style.display = 'block';
            customField.required = true;
        } else {
            customField.style.display = 'none';
            customField.required = false;
            customField.value = '';
        }
    }
    // Function to update sub-categories based on selected category (placeholder for future use)
    function updateSubCategories(category) {
        // This function can be extended to dynamically load sub-categories based on category
        console.log('Category selected:', category);
    }
    // Add event listener for sub-category dropdown
    document.getElementById('add_sub_category')?.addEventListener('change', function() {
        toggleCustomSubCategory(this);
    });

    // Bulk selection functions
    function toggleSelectAll(source) {
        var checkboxes = document.querySelectorAll('.question-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = source.checked;
        });
        updateSelectedCount();
    }

    function updateSelectedCount() {
        var checked = document.querySelectorAll('.question-checkbox:checked');
        var countSpan = document.getElementById('selectedCount');
        var deleteBtn = document.getElementById('bulkDeleteSelectedBtn');
        if (countSpan) countSpan.textContent = checked.length;
        if (deleteBtn) deleteBtn.disabled = checked.length === 0;
    }

    // Add event listeners to checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        var checkboxes = document.querySelectorAll('.question-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', updateSelectedCount);
        });
    });

    // Prepare selected questions for bulk delete
    function prepareSelectedQuestions() {
        var checked = document.querySelectorAll('.question-checkbox:checked');
        var ids = [];
        checked.forEach(function(checkbox) {
            ids.push(checkbox.value);
        });
        document.getElementById('selectedQuestionsInput').value = ids.join(',');
        return ids.length > 0;
    }
    </script>

    <!-- Reorder Questions Modal -->
    <div class="modal fade" id="reorderQuestionsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="questions.php" id="reorderForm">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-sort-numeric-up me-2"></i>Reorder - Drag & Drop</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Filter Buttons -->
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-filter me-2"></i>Filter by Category:</label>
                            <div class="btn-group flex-wrap" role="group">
                                <a href="?reorder_filter=all" class="btn btn-sm btn-outline-primary <?php echo $reorderFilter === 'all' ? 'active' : ''; ?>">All</a>
                                <a href="?reorder_filter=academic" class="btn btn-sm btn-outline-success <?php echo $reorderFilter === 'academic' ? 'active' : ''; ?>">Academic</a>
                                <a href="?reorder_filter=non-teaching" class="btn btn-sm btn-outline-warning <?php echo $reorderFilter === 'non-teaching' ? 'active' : ''; ?>">Non-Teaching Senior (L6+)</a>
                                <a href="?reorder_filter=non-teaching-junior" class="btn btn-sm btn-outline-info <?php echo $reorderFilter === 'non-teaching-junior' ? 'active' : ''; ?>">Junior Staff (L5)</a>
                                <a href="?reorder_filter=S.O_academic" class="btn btn-sm btn-outline-purple <?php echo $reorderFilter === 'S.O_academic' ? 'active' : ''; ?>" style="border-color:#6f42c1;color:#6f42c1;">SO: Academic</a>
                                <a href="?reorder_filter=S.O_senior" class="btn btn-sm btn-outline-orange <?php echo $reorderFilter === 'S.O_senior' ? 'active' : ''; ?>" style="border-color:orange;color:orange;">SO: Senior</a>
                                <a href="?reorder_filter=S.O_junior" class="btn btn-sm btn-outline-teal <?php echo $reorderFilter === 'S.O_junior' ? 'active' : ''; ?>" style="border-color:#20c997;color:#20c997;">SO: Junior</a>
                                <a href="?reorder_filter=supervising-officer" class="btn btn-sm btn-outline-danger <?php echo $reorderFilter === 'supervising-officer' ? 'active' : ''; ?>" style="border-color:#dc3545;color:#dc3545;">All SO Questions</a>
                            </div>
                        </div>
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#reorder-sections" type="button">
                                    <i class="fas fa-layer-group me-1"></i> Reorder Sections
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#reorder-questions" type="button">
                                    <i class="fas fa-list-ol me-1"></i> Reorder Questions
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- Section/Category Drag & Drop -->
                            <div class="tab-pane fade show active" id="reorder-sections">
                                <p class="text-muted mb-3"><i class="fas fa-arrows-alt-v me-2"></i><strong>Drag and drop</strong> sections to reorder. Top section appears first.</p>
                                <div id="sections-container" style="max-height: 450px; overflow-y: auto;">
                                    <?php
                                    // Filter sections based on reorderFilter
                                    if ($reorderFilter === 'all') {
                                        $categoriesStmt = $pdo->query("SELECT DISTINCT category, category_order FROM evaluation_questions WHERE is_active = 1 ORDER BY COALESCE(category_order, 99999), category");
                                        $countStmt = $pdo->query("SELECT category, COUNT(*) as cnt FROM evaluation_questions WHERE is_active = 1 GROUP BY category");
                                    } elseif ($reorderFilter === 'supervising-officer') {
                                        // Supervising Officer - all SO categories
                                        $categoriesStmt = $pdo->query("SELECT DISTINCT category, category_order FROM evaluation_questions WHERE is_active = 1 AND target_staff_category IN ('S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior') ORDER BY COALESCE(category_order, 99999), category");
                                        $countStmt = $pdo->query("SELECT category, COUNT(*) as cnt FROM evaluation_questions WHERE is_active = 1 AND target_staff_category IN ('S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior') GROUP BY category");
                                    } else {
                                        $categoriesStmt = $pdo->prepare("SELECT DISTINCT category, category_order FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = ? OR target_staff_category = 'both') ORDER BY COALESCE(category_order, 99999), category");
                                        $categoriesStmt->execute([$reorderFilter]);
                                        $countStmt = $pdo->prepare("SELECT category, COUNT(*) as cnt FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = ? OR target_staff_category = 'both') GROUP BY category");
                                        $countStmt->execute([$reorderFilter]);
                                    }
                                    $categories = $categoriesStmt->fetchAll();
                                    $catCounts = [];
                                    while ($row = $countStmt->fetch()) { $catCounts[$row['category']] = $row['cnt']; }
                                    $secIdx = 0;
                                    foreach ($categories as $cat):
                                        $secIdx++;
                                    ?>
                                    <div class="card mb-2 sortable-section" data-category="<?php echo htmlspecialchars($cat['category']); ?>" style="cursor: move;">
                                        <div class="card-body py-2">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-grip-vertical me-3 text-muted"></i>
                                                <strong><?php echo htmlspecialchars($cat['category']); ?></strong>
                                                <span class="badge bg-secondary ms-2"><?php echo $catCounts[$cat['category']] ?? 0; ?></span>
                                                <input type="hidden" name="category_orders[<?php echo htmlspecialchars($cat['category']); ?>]" class="sec-order" value="<?php echo $secIdx; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Question Reordering -->
                            <div class="tab-pane fade" id="reorder-questions">
                                <p class="text-muted mb-3"><i class="fas fa-arrows-alt-v me-2"></i><strong>Drag and drop</strong> questions to reorder. Questions grouped by section.</p>
                                <div id="questions-container" style="max-height: 450px; overflow-y: auto;">
                                    <?php
                                    // Build filter query based on reorderFilter
                                    $grouped = [];
                                    if ($reorderFilter === 'all') {
                                        $qStmt = $pdo->query("SELECT id, question_text, category, question_order FROM evaluation_questions WHERE is_active = 1 ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
                                    } elseif ($reorderFilter === 'supervising-officer') {
                                        // Supervising Officer - all SO categories (including S.O_academic, S.O_senior, S.O_junior, S.O)
                                        $qStmt = $pdo->query("SELECT id, question_text, category, question_order FROM evaluation_questions WHERE is_active = 1 AND target_staff_category IN ('S.O', 'S.O_academic', 'S.O_senior', 'S.O_junior') ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
                                    } else {
                                        $qStmt = $pdo->prepare("SELECT id, question_text, category, question_order FROM evaluation_questions WHERE is_active = 1 AND (target_staff_category = ? OR target_staff_category = 'both') ORDER BY COALESCE(category_order, 99999), category, COALESCE(question_order, 99999), id");
                                        $qStmt->execute([$reorderFilter]);
                                    }
                                    while ($r = $qStmt->fetch()) { $grouped[$r['category']][] = $r; }
                                    foreach ($grouped as $cat => $qs):
                                    ?>
                                    <div class="category-group mb-3">
                                        <div class="card bg-light">
                                            <div class="card-body py-2"><strong><i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($cat); ?></strong></div>
                                        </div>
                                        <div class="questions-list" data-category="<?php echo htmlspecialchars($cat); ?>" style="min-height:30px;">
                                            <?php foreach ($qs as $q): ?>
                                            <div class="card mb-2 sortable-question" data-id="<?php echo $q['id']; ?>" style="cursor:move;">
                                                <div class="card-body py-2">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-grip-lines me-3 text-muted"></i>
                                                        <small class="flex-grow-1"><?php echo htmlspecialchars(substr($q['question_text'],0,50)); ?>...</small>
                                                        <input type="hidden" name="question_orders[<?php echo $q['id']; ?>]" class="q-order" value="<?php echo $q['question_order']??0; ?>">
                                                        <input type="hidden" name="question_categories[<?php echo $q['id']; ?>]" class="q-cat" value="<?php echo htmlspecialchars($cat); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                        </div> <!-- End tab-content -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reorder_questions" class="btn btn-success"><i class="fas fa-save me-2"></i>Save Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <style>
    .sortable-section, .sortable-question { transition: transform 0.2s; }
    .sortable-section.dragging, .sortable-question.dragging { opacity: 0.5; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Sections drag & drop
        var sectionsContainer = document.getElementById('sections-container');
        if (sectionsContainer) {
            new Sortable(sectionsContainer, {
                animation: 150,
                ghostClass: 'dragging',
                onEnd: function(evt) {
                    var items = sectionsContainer.querySelectorAll('.sortable-section');
                    items.forEach(function(item, index) {
                        var input = item.querySelector('.sec-order');
                        if (input) input.value = index + 1;
                    });
                }
            });
        }

        // Questions drag & drop per category
        document.querySelectorAll('.questions-list').forEach(function(list) {
            new Sortable(list, {
                group: 'questions',
                animation: 150,
                ghostClass: 'dragging',
                onEnd: function(evt) {
                    var items = list.querySelectorAll('.sortable-question');
                    items.forEach(function(item, index) {
                        item.querySelector('.q-order').value = index + 1;
                        item.querySelector('.q-cat').value = list.dataset.category;
                    });
                }
            });
        });
    });
    </script>

    <?php if ($keepModalOpen): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-open the Add Question modal
        var addModal = new bootstrap.Modal(document.getElementById('addQuestionModal'));
        addModal.show();

        // Pre-fill form with last used values
        <?php if (!empty($lastCategory)): ?>
        var categoryInput = document.getElementById('add_category');
        if (categoryInput) {
            categoryInput.value = '<?php echo addslashes($lastCategory); ?>';
        }
        <?php endif; ?>

        <?php if (!empty($lastSubCategory)): ?>
        var subCatSelect = document.getElementById('add_sub_category');
        if (subCatSelect) {
            subCatSelect.value = '<?php echo addslashes($lastSubCategory); ?>';
        }
        <?php endif; ?>

        <?php if (!empty($lastQuestionType)): ?>
        var typeSelect = document.getElementById('add_question_type');
        if (typeSelect) {
            typeSelect.value = '<?php echo addslashes($lastQuestionType); ?>';
            // Trigger toggle for options field
            toggleOptionsField(typeSelect, 'add');
        }
        <?php endif; ?>

        <?php if (!empty($lastQuestionGroup)): ?>
        var groupInput = document.querySelector('input[name="question_group"]');
        if (groupInput) {
            groupInput.value = '<?php echo addslashes($lastQuestionGroup); ?>';
        }
        <?php endif; ?>

        <?php if (!empty($lastQuestionLabel)): ?>
        var labelInput = document.querySelector('input[name="question_label"]');
        if (labelInput) {
            labelInput.value = '<?php echo addslashes($lastQuestionLabel); ?>';
        }
        <?php endif; ?>

        <?php if (!empty($lastTargetStaff)): ?>
        var staffSelect = document.querySelector('select[name="target_staff_category"]');
        if (staffSelect) {
            staffSelect.value = '<?php echo addslashes($lastTargetStaff); ?>';
        }
        <?php endif; ?>

        // Clear question text for new entry
        var questionTextInput = document.querySelector('input[name="question_text"]');
        if (questionTextInput) {
            questionTextInput.value = '';
        }

        // Clear order field
        var orderInput = document.querySelector('input[name="question_order"]');
        if (orderInput) {
            orderInput.value = '0';
        }

        // Clear options textarea
        var optionsTextarea = document.querySelector('textarea[name="options"]');
        if (optionsTextarea) {
            optionsTextarea.value = '';
        }
    });
    </script>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="mt-4 py-3" style="background: linear-gradient(180deg, <?php echo $settings['primary_color'] ?? '#247d57'; ?> 0%, <?php echo $settings['secondary_color'] ?? '#1a5238'; ?> 100%); color: white; border-radius: 8px;">
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