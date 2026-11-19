<?php
require __DIR__ . '/inc/bootstrap.php';
$currentUser = current_user();
$isLoggedIn = $currentUser !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BEACON SVLC · Student Welfare & Clinic Management</title>
    <link rel="stylesheet" href="./index.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&display=swap">
    <style>
        body {
            background-color: #f8fafc;
            color: #0f172a;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow-x: hidden;
        }
        .landing-nav {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 20px 48px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .landing-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .landing-logo {
            width: 40px;
            height: 40px;
            background: var(--primary, #4f46e5);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-family: 'Manrope', sans-serif;
            font-size: 1rem;
        }
        .main-container {
            max-width: 900px;
            margin: auto;
            padding: 40px 24px;
            text-align: center;
        }
        .title-main {
            font-family: 'Manrope', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }
        .subtitle-main {
            font-size: 0.95rem;
            color: #64748b;
            margin-bottom: 40px;
        }
        .nav-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            text-align: left;
        }
        .nav-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .nav-card:hover {
            border-color: #6366f1;
            transform: translateY(-2px);
            background: #f8fafc;
        }
        .nav-card-title {
            font-family: 'Manrope', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: #0f172a;
        }
        .nav-card-desc {
            font-size: 0.78rem;
            color: #64748b;
        }
        @media (max-width: 768px) {
            .nav-grid { grid-template-columns: 1fr; }
            .title-main { font-size: 2rem; }
            .landing-nav { padding: 16px 20px; }
        }
    </style>
</head>
<body>

<header class="landing-nav">
    <a href="landing.php" class="landing-brand">
        <div class="landing-logo">SV</div>
        <div>
            <div style="font-family: 'Manrope', sans-serif; font-size: 1.05rem; font-weight: 800; color: #0f172a; line-height: 1.1;">BEACON SVLC</div>
            <div style="font-size: 0.68rem; color: #64748b;">Student Welfare & Clinic</div>
        </div>
    </a>
    <div>
        <?php if ($isLoggedIn): ?>
            <a class="btn btn-primary" href="<?= e(home_for($currentUser)) ?>" style="font-size: 0.8125rem; padding: 8px 16px;">Dashboard (<?= e($currentUser['name']) ?>) →</a>
        <?php else: ?>
            <a class="btn btn-primary" href="login.php" style="font-size: 0.8125rem; padding: 8px 16px;">Log in →</a>
        <?php endif; ?>
    </div>
</header>

<div class="main-container">
    <h1 class="title-main">BEACON</h1>
    <p class="subtitle-main">Select a module below to quickly navigate the student welfare and clinic system.</p>

    <div class="nav-grid">
        <a href="user_overview.php" class="nav-card">
            <span style="font-size: 1.2rem;">📊</span>
            <div class="nav-card-title">Teacher Overview</div>
            <div class="nav-card-desc">Advisory Dashboard & Metrics</div>
        </a>

        <a href="student_search.php" class="nav-card">
            <span style="font-size: 1.2rem;">🔍</span>
            <div class="nav-card-title">Student Lookup</div>
            <div class="nav-card-desc">Learner Profiles & Attendance</div>
        </a>

        <a href="log_conduct.php" class="nav-card">
            <span style="font-size: 1.2rem;">📝</span>
            <div class="nav-card-title">Log Conduct</div>
            <div class="nav-card-desc">Behavior Incidents & Merits</div>
        </a>

        <a href="guidance_desk.php" class="nav-card">
            <span style="font-size: 1.2rem;">📋</span>
            <div class="nav-card-title">Guidance Desk</div>
            <div class="nav-card-desc">Counselor Referral Queue</div>
        </a>

        <a href="case_bip_manager.php" class="nav-card">
            <span style="font-size: 1.2rem;">⚠️</span>
            <div class="nav-card-title">Case & BIP Manager</div>
            <div class="nav-card-desc">Behavior Intervention Plans</div>
        </a>

        <a href="clinic_overview.php" class="nav-card">
            <span style="font-size: 1.2rem;">🏥</span>
            <div class="nav-card-title">Clinic Overview</div>
            <div class="nav-card-desc">Live Health Queue & Visits</div>
        </a>

        <a href="patient_intake.php" class="nav-card">
            <span style="font-size: 1.2rem;">❤️</span>
            <div class="nav-card-title">Patient Intake</div>
            <div class="nav-card-desc">Clinic Check-ins & Triage</div>
        </a>

        <a href="medical_inventory.php" class="nav-card">
            <span style="font-size: 1.2rem;">💊</span>
            <div class="nav-card-title">Medical Inventory</div>
            <div class="nav-card-desc">Pharmaceutical Stock Levels</div>
        </a>

        <a href="parent_welfare_feed.php" class="nav-card">
            <span style="font-size: 1.2rem;">📢</span>
            <div class="nav-card-title">Parent Welfare Feed</div>
            <div class="nav-card-desc">Family Portal Broadcasts</div>
        </a>
    </div>
</div>

<footer style="text-align: center; padding: 20px; border-top: 1px solid var(--border); color: #64748b; font-size: 0.75rem; background: #ffffff;">
    BEACON &copy; Student Welfare System
</footer>

</body>
</html>