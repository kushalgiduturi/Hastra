<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/seo.php
// One place for the social/search tags every public page shares: description,
// robots, canonical, theme colour, Open Graph, Twitter card and the favicon
// set. Absolute URLs come from APP_URL, so the same code is right on localhost,
// on Render and on a custom domain.
//
//   astra_seo_meta([
//       'title'       => 'Cryptography & File Armor · Hastra Labs',   // og/twitter title
//       'description' => '...',
//       'path'        => 'labs/crypto',                                // canonical, relative to the app root
//       'robots'      => 'index, follow',                              // 'noindex, follow' for sign-in pages
//   ]);

const ASTRA_SEO_DEFAULT_DESCRIPTION =
    'Hastra is a governed, auditable workspace for software delivery, plus free browser-native labs: post-quantum '
  . 'cryptography, SIEM and threat-intel simulators, and a syllabus accelerator.';
const ASTRA_SEO_KEYWORDS =
    'post-quantum cryptography, ML-KEM, ML-DSA, SIEM, threat intelligence, cybersecurity labs, secure software delivery, syllabus accelerator';

function astra_seo_meta(array $o = []): void {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $base   = rtrim(APP_URL, '/') . '/';
    $title  = $o['title'] ?? 'Hastra';
    $desc   = $o['description'] ?? ASTRA_SEO_DEFAULT_DESCRIPTION;
    $robots = $o['robots'] ?? 'index, follow';
    $url    = $base . ltrim((string)($o['path'] ?? ''), '/');
    $image  = $base . 'assets/images/og-preview.png';
    $type   = $o['type'] ?? 'website';
    ?>
<meta name="description" content="<?= $e($desc) ?>">
<meta name="keywords" content="<?= $e($o['keywords'] ?? ASTRA_SEO_KEYWORDS) ?>">
<meta name="author" content="Hastra">
<meta name="robots" content="<?= $e($robots) ?>">
<meta name="theme-color" content="#050505">
<link rel="canonical" href="<?= $e($url) ?>">
<meta property="og:site_name" content="Hastra">
<meta property="og:type" content="<?= $e($type) ?>">
<meta property="og:url" content="<?= $e($url) ?>">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($desc) ?>">
<meta property="og:image" content="<?= $e($image) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Hastra, the torii H mark on a dark crimson background">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($title) ?>">
<meta name="twitter:description" content="<?= $e($desc) ?>">
<meta name="twitter:image" content="<?= $e($image) ?>">
<link rel="icon" type="image/svg+xml" href="<?= $e($base) ?>assets/images/hastra-logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $e($base) ?>assets/images/favicon-32.png">
<link rel="alternate icon" href="<?= $e($base) ?>favicon.ico">
<link rel="apple-touch-icon" href="<?= $e($base) ?>assets/images/apple-touch-icon.png">
<?php
}

// Per-page description, canonical and social tags for pages that don't call
// astra_seo_meta() themselves (the auth steps and the signed-in workspace).
// Keyed by the script path relative to the app root. Those pages are private
// or one step in a flow, so they stay out of search results (noindex), but a
// shared link still unfurls with a real title, description and image.
const ASTRA_PAGE_DESCRIPTIONS = [
    'dashboard.php'                          => 'Your Hastra dashboard: the projects, tasks and approvals waiting on you, with quick links into every part of the governed delivery workspace you can open.',
    'portals/index.php'                      => 'Your Hastra workspace home. Hastra sends you to the portal for your role, where projects, delivery status and audit history are kept in one governed place.',
    'auth/forgot.php'                        => 'Forgot your Hastra password? Enter the email on your account and we will send a one-time code so you can set a new password securely in a few minutes.',
    'auth/forgot_otp.php'                    => 'Enter the six-digit code we emailed to reset your Hastra password. Codes expire after ten minutes and work only once, so request a fresh one if needed.',
    'auth/otp.php'                           => 'Two-step verification for Hastra. Enter the one-time code sent to your email to finish signing in; codes expire after ten minutes and work only once.',
    'auth/reset.php'                         => 'Choose a new password for your Hastra account. Use at least twelve characters: long passphrases are easier to remember and much harder to guess.',
    'auth/set_password.php'                  => 'Activate your Hastra account by choosing a password. Your invitation link works once; after this you can sign in and see the projects shared with you.',
    'auth/verify_register.php'               => 'Confirm your email to finish creating your Hastra account. Enter the six-digit code we sent; it expires in ten minutes and you can ask for a new one.',
    'core/google_oauth.php'                  => 'Signing you in to Hastra with Google. This page finishes the secure hand-off from Google, then sends you on to your own workspace automatically.',
    'portals/admin/admin_portal.php'         => 'Hastra admin portal: manage projects, people, approvals and delivery for your organization from one governed, audited workspace with role-based access.',
    'portals/admin/attendance.php'           => 'Attendance matrix and logs for your organization in Hastra: review each day, correct entries and connect a device webhook, with every change recorded.',
    'portals/admin/billing.php'              => 'Billing in Hastra: milestone invoices, payment status and escrow releases for your projects, shown in full with every amount and fee listed up front.',
    'portals/admin/create_employee.php'      => 'Add a team member to your Hastra workspace. They receive an activation email, sign in with their own password and see only what their role allows.',
    'portals/admin/delivery.php'             => 'Delivery control in Hastra: hand over source, documentation and deployments to clients through single-use, encrypted delivery links that are logged.',
    'portals/admin/deployments.php'          => 'Approve or reject deployments in Hastra. Each decision moves the project forward or back to testing and is written to the tamper-evident activity log.',
    'portals/admin/directory.php'            => 'User directory for your Hastra organization: everyone with access, their role and status, with tools to invite, change roles and remove members safely.',
    'portals/admin/doc_editor.php'           => 'Edit and approve project documentation in Hastra. Drafts are versioned, approvals are recorded, and clients only see the version you have approved.',
    'portals/admin/leave_management.php'     => 'Leave management in Hastra: review requests, set your leave policy and see balances, with each approval or rejection recorded in the activity log.',
    'portals/admin/project_portal.php'       => 'Project portal in Hastra: team, tasks, milestones and status for one project, with every assignment and change tracked for a clean audit trail.',
    'portals/admin/requirements.php'         => 'Review client requirements in Hastra: read each submission, compare revisions, ask questions and approve the scope before work on the project starts.',
    'portals/admin/security.php'             => 'System security in Hastra: sign-in activity, locked addresses and security events for your organization, read from the tamper-evident activity log.',
    'portals/admin/testing.php'              => 'Testing and bug overview in Hastra: open defects by project and severity, retest status and closure history, so every release ships with a record.',
    'portals/client/client_portal.php'       => 'Your Hastra client portal: follow every project you commissioned, from requirements and sprints to testing, delivery, sign-off and final invoices.',
    'portals/client/delivery_billing.php'    => 'Delivery and billing for your projects in Hastra: open single-use delivery links, view invoices with every fee listed, and download your records.',
    'portals/client/docs.php'                => 'Project documentation shared with you in Hastra. Only versions your delivery team has approved appear here, each one shown with its approval date.',
    'portals/client/doc_view.php'            => 'Read an approved project document in Hastra. The version and approval date are shown, so you always know exactly which text your team signed off.',
    'portals/client/my_projects.php'         => 'All your projects in Hastra in one list, with live status, the next milestone and links to requirements, documentation and delivery for each one.',
    'portals/client/my_requirements.php'     => 'Your requirements in Hastra: every submission, its review status and each revision, so you can see exactly what was agreed for each of your projects.',
    'portals/client/project_view.php'        => 'Handover status for your project in Hastra: what has been delivered, what is still pending, and the single-use links to collect each part securely.',
    'portals/client/requirements_diff.php'   => 'Compare requirement revisions in Hastra side by side, with additions and removals highlighted, so every change in scope is visible to everyone.',
    'portals/client/security_view.php'       => 'The security summary for your delivered project in Hastra. It opens once, so read or print it now; findings and their fixes are listed in plain words.',
    'portals/client/submit_requirement.php'  => 'Submit a new requirement to your delivery team in Hastra. Describe the goal, budget range and deadline; you can revise it until it has been approved.',
    'portals/client/team.php'                => 'Your organization\'s team in Hastra: who has access, their role and status, with tools for your IT manager to invite people or update their roles.',
    'portals/deliveries/dossier.php'         => 'An ephemeral dossier in Hastra: an encrypted, single-use delivery package that is destroyed once opened, with the moment of opening recorded.',
    'portals/deliveries/terminal.php'        => 'Hastra handover terminal: collect delivered source code, documentation and credentials through a verified, single-use channel that logs each step.',
    'portals/emlpoyee/deployment_portal.php' => 'Deployment portal in Hastra: prepare releases, attach build notes and send them for approval, with every action recorded against the project.',
    'portals/emlpoyee/employee_portal.php'   => 'Your Hastra workspace: the tasks assigned to you, attendance, leave and the projects you are on, all in one place with clear next steps for each.',
    'portals/emlpoyee/my_tasks.php'          => 'Your tasks in Hastra: what is due, what is blocked and what is done, with files and comments kept alongside each task for the whole project team.',
    'portals/emlpoyee/team_lead.php'         => 'Team lead view in Hastra: workload, progress and blockers across your team\'s tasks, with tools to reassign work and keep milestones on track.',
    'portals/emlpoyee/testing_portal.php'    => 'Testing and QA in Hastra: log bugs with evidence, retest fixes and close defects, with every status change kept in the project history for audit.',
    'portals/projects/signoff.php'           => 'Milestone sign-off in Hastra: review what was delivered, confirm with a one-time code or raise a dispute, with each decision recorded permanently.',
    'portals/security/scan_center.php'       => 'Hastra scan centre: run code analysis and web scans on your projects, track findings by severity and confirm the fixes before each release ships.',
    'portals/sysadmin/logs.php'              => 'Activity logs and security architecture in Hastra: the tamper-evident, hash-chained record of sign-ins, changes and security events platform-wide.',
    'portals/sysadmin/migrate.php'           => 'Database migrations for Hastra: apply schema updates safely, see which ones have run and confirm the database matches what the application expects.',
    'portals/sysadmin/roles.php'             => 'Role management in Hastra: define roles, grant permissions and see who holds each one, so access across the platform always follows least privilege.',
    'portals/sysadmin/security_dashboard.php'=> 'Hastra security dashboard: sign-in risk, blocked addresses, chain integrity and incident trends across the platform, refreshed as new events arrive.',
    'portals/sysadmin/sysadmin_portal.php'   => 'Hastra system administration: organizations, platform settings, security posture and health, for the operator who runs this Hastra installation.',
    'portals/user/newuser_portal.php'        => 'Welcome to Hastra. Your account is ready; your organization will add you to projects soon, and in the meantime the free Labs are open to explore.',
    'portals/user/profile.php'               => 'Your Hastra profile and settings: name, contact details, password, two-step sign-in and privacy controls, including deleting your account and data.',
];

function astra_page_meta(?string $title = null): void {
    $root   = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rel    = ltrim(substr($script, strlen($root)), '/');
    $path   = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base   = rtrim((string)parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    astra_seo_meta([
        'title'       => $title ?? 'Hastra',
        'description' => ASTRA_PAGE_DESCRIPTIONS[$rel] ?? ASTRA_SEO_DEFAULT_DESCRIPTION,
        'path'        => ltrim($path, '/'),
        'robots'      => 'noindex, nofollow',
    ]);
}
