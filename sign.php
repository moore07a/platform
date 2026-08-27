<?php require_once(__DIR__ . '/init.php'); ?>
<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/includes/functions.php');
require_once(__DIR__ . '/api/api_helpers.php');

function verifyLoginPassword(PDO $pdo, array $user, string $password): bool {
    if (password_verify($password, $user['password'])) return true;

    // Compatibility for manually created database users that were entered as plain text.
    // On successful login we immediately upgrade the stored password to a secure hash.
    if (password_get_info($user['password'])['algo'] === 0 && hash_equals((string) $user['password'], $password)) {
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        return true;
    }

    return false;
}

function ensurePlatformOwnerWorkspace(PDO $pdo, array $user): array {
    $pdo->exec("INSERT INTO farms (name, slug, subscription_plan, subscription_status)
                SELECT 'Renee Farms Platform', 'owner', 'platform', 'active'
                WHERE NOT EXISTS (SELECT 1 FROM farms WHERE slug = 'owner')");
    $farmStmt = $pdo->query("SELECT id, name, subscription_status FROM farms WHERE slug = 'owner' LIMIT 1");
    $farm = $farmStmt->fetch(PDO::FETCH_ASSOC);
    if (!$farm) return $user;

    if ((int) ($user['farm_id'] ?? 0) !== (int) $farm['id']) {
        $pdo->prepare('UPDATE users SET farm_id = ? WHERE id = ?')->execute([(int) $farm['id'], (int) $user['id']]);
    }
    $user['farm_id'] = (int) $farm['id'];
    $user['farm_name'] = $farm['name'];
    $user['subscription_status'] = $farm['subscription_status'];
    return $user;
}

$selectedAccountType = 'farm';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_rate_limit('login_attempt', 12, 300);
    $accountType = ($_POST['account_type'] ?? 'farm') === 'platform' ? 'platform' : 'farm';
    $selectedAccountType = $accountType;
    $farmSlug = strtolower(trim($_POST['farm_slug'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($accountType === 'platform') {
        $stmt = $pdo->prepare("SELECT u.*, COALESCE(f.name, 'Renee Farms Platform') AS farm_name, COALESCE(f.subscription_status, 'active') AS subscription_status
                               FROM users u
                               LEFT JOIN farms f ON f.id = u.farm_id
                               LEFT JOIN user_roles ur ON ur.user_id = u.id
                               LEFT JOIN roles r ON r.id = ur.role_id
                               WHERE u.username = ?
                                 AND (u.user_type IN ('platform_owner', 'platform_admin') OR r.code IN ('platform_owner', 'platform_admin'))
                               GROUP BY u.id
                               LIMIT 1");
        $stmt->execute([$username]);
    } else {
        $stmt = $pdo->prepare("SELECT u.*, f.name AS farm_name, f.subscription_status
                               FROM users u INNER JOIN farms f ON f.id = u.farm_id
                               WHERE u.username = ? AND f.slug = ? LIMIT 1");
        $stmt->execute([$username, $farmSlug]);
    }
    $user = $stmt->fetch();

    if ($user && ($accountType === 'platform' || !in_array($user['subscription_status'], ['suspended', 'cancelled'], true)) && verifyLoginPassword($pdo, $user, $password)) {
        if ($accountType === 'platform') $user = ensurePlatformOwnerWorkspace($pdo, $user);
        $previousLogin = $user['last_login_at'] ?? null;

        $updateLoginStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $updateLoginStmt->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['farm_id'] = (int) $user['farm_id'];
        $_SESSION['farm_name'] = $user['farm_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['last_login_at'] = $previousLogin;

        header('Location: dashboard.php');
        exit();
    }

    log_app_error('login_failed', ['account_type' => $accountType, 'farm_slug' => $farmSlug, 'username' => $username]);
    $error = 'Invalid workspace, username, or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Sign In | Renee Farms Workspace</title>
    <meta name="theme-color" content="#1b4332">
    <meta name="description" content="Secure access to Renee Farms operational workspace.">

    <link rel="icon" href="assets/images/favicon.ico?v=2024.06.01" type="image/x-icon" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/favicon.ico?v=2024.06.01">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-1: #081c15;
            --bg-2: #1b4332;
            --bg-3: #2d6a4f;
            --surface: rgba(255, 255, 255, 0.9);
            --text: #0f172a;
            --muted: #4f6d61;
            --success: #2d6a4f;
            --danger: #c62828;
            --input-border: rgba(45, 106, 79, 0.2);
            --ring: rgba(45, 106, 79, 0.22);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, sans-serif;
            color: var(--text);
            min-height: 100vh;
            background:
                radial-gradient(circle at 10% 20%, rgba(183, 228, 199, 0.2), transparent 34%),
                radial-gradient(circle at 90% 0%, rgba(64, 145, 108, 0.28), transparent 40%),
                linear-gradient(140deg, var(--bg-1), var(--bg-2) 54%, var(--bg-3));
            display: grid;
            place-items: center;
            padding: 1rem;
        }

        .auth-shell {
            width: min(1080px, 100%);
            min-height: min(640px, 92vh);
            border-radius: 24px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 24px 55px rgba(0, 0, 0, 0.3);
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
        }

        .auth-hero {
            padding: 2rem;
            color: #f1fff5;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                radial-gradient(circle at 0% 0%, rgba(116, 198, 157, 0.24), transparent 34%),
                linear-gradient(160deg, rgba(14, 52, 36, 0.7), rgba(27, 67, 50, 0.5));
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.78rem;
        }

        .brand img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.4);
        }

        .brand strong {
            display: block;
            letter-spacing: 0.5px;
            font-size: 0.93rem;
        }

        .brand span {
            color: rgba(234, 255, 241, 0.86);
            font-size: 0.76rem;
        }

        .auth-hero h1 {
            margin: 1.6rem 0 0.8rem;
            font-size: clamp(2rem, 3vw, 2.6rem);
            line-height: 1.08;
            letter-spacing: -0.02em;
        }

        .auth-hero p {
            margin: 0;
            font-size: 1rem;
            color: rgba(233, 255, 240, 0.9);
            max-width: 36ch;
        }

        .points {
            display: grid;
            gap: 0.6rem;
            margin-top: 1.3rem;
        }

        .points span {
            display: inline-flex;
            align-items: center;
            gap: 0.52rem;
            font-size: 0.88rem;
            color: rgba(241, 255, 246, 0.94);
        }

        .auth-card {
            background: var(--surface);
            padding: 2.1rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .chip {
            width: fit-content;
            font-size: 0.76rem;
            font-weight: 600;
            color: #1b4a35;
            background: rgba(45, 106, 79, 0.12);
            border: 1px solid rgba(45, 106, 79, 0.18);
            padding: 0.35rem 0.56rem;
            border-radius: 999px;
            margin-bottom: 0.6rem;
        }

        .auth-card h2 {
            margin: 0;
            font-size: 1.6rem;
            line-height: 1.2;
            color: #10281e;
        }

        .auth-card p {
            margin: 0.5rem 0 1.4rem;
            color: var(--muted);
            font-size: 0.95rem;
        }

        form {
            display: grid;
            gap: 0.9rem;
        }

        label {
            font-weight: 600;
            color: #254838;
            font-size: 0.9rem;
            display: block;
            margin-bottom: 0.4rem;
        }

        input {
            width: 100%;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
            font-size: 0.95rem;
            background: #fff;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        input:focus {
            outline: none;
            border-color: #2d6a4f;
            box-shadow: 0 0 0 4px var(--ring);
        }

        .button {
            margin-top: 0.4rem;
            border: none;
            cursor: pointer;
            border-radius: 12px;
            background: linear-gradient(135deg, #2d6a4f, #40916c);
            color: #fff;
            font-weight: 700;
            letter-spacing: 0.2px;
            padding: 0.8rem 1rem;
            box-shadow: 0 10px 20px rgba(45, 106, 79, 0.28);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(36, 91, 67, 0.34);
        }

        .error {
            border: 1px solid rgba(198, 40, 40, 0.2);
            background: rgba(198, 40, 40, 0.08);
            color: var(--danger);
            border-radius: 12px;
            padding: 0.65rem 0.8rem;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .helper {
            margin-top: 0.8rem;
            font-size: 0.82rem;
            color: #617f73;
            text-align: center;
        }

        .back-link {
            text-decoration: none;
            color: #f1fff5;
            font-size: 0.86rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            width: fit-content;
            padding: 0.45rem 0.78rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.38);
            box-shadow: 0 6px 18px rgba(2, 22, 15, 0.24);
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .back-link:hover,
        .back-link:focus-visible {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.24);
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(2, 22, 15, 0.32);
            outline: none;
        }


        .login-type-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 1rem;
            border: 1px solid var(--input-border);
            border-radius: 999px;
            overflow: hidden;
            background: rgba(255,255,255,.72);
        }

        .login-type-toggle label {
            flex: 1 1 0;
            text-align: center;
            padding: .72rem .85rem;
            cursor: pointer;
            color: #496b5d;
            font-weight: 700;
            transition: background .2s ease, color .2s ease;
        }

        .login-type-toggle .toggle-divider {
            width: 1px;
            align-self: stretch;
            background: var(--input-border);
        }

        .login-type-toggle input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .login-type-toggle label:has(input:checked) {
            background: linear-gradient(135deg, #2d6a4f, #40916c);
            color: #fff;
        }

        .form-hint {
            display: block;
            margin-top: .35rem;
            color: var(--muted);
            font-size: .82rem;
        }

        @media (max-width: 960px) {
            .auth-shell {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .auth-hero {
                gap: 1.5rem;
                padding-bottom: 1.2rem;
            }

            .auth-hero h1 {
                margin-top: 1rem;
            }
        }

        @media (max-width: 540px) {
            .auth-card,
            .auth-hero {
                padding: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <main class="auth-shell" aria-label="Renee Farms secure sign in layout">
        <section class="auth-hero">
            <div>
                <div class="brand">
                    <img src="assets/images/logo.jpg?v=2024.06.01" alt="Renee Farms logo" width="46" height="46" decoding="async">
                    <div>
                        <strong>RENEE FARMS LTD</strong>
                        <span>Farm Operations Workspace</span>
                    </div>
                </div>
                <h1>Welcome back to your agricultural command center.</h1>
                <p>
                    Securely access production insights, stock flows, and performance data from one professional workspace.
                </p>
                <div class="points" aria-hidden="true">
                    <span>✅ Unified poultry and ruminant records</span>
                    <span>✅ Real-time inventory and expense intelligence</span>
                    <span>✅ Role-based access with secure authentication</span>
                </div>
            </div>
            <a class="back-link" href="index.php">← Back to website</a>
        </section>

        <section class="auth-card">
            <span class="chip">Protected Access</span>
            <h2>Sign in to continue</h2>
            <p>Use your official credentials to open the Renee Farms dashboard.</p>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="login-type-toggle" role="radiogroup" aria-label="Account type">
                    <label><input type="radio" name="account_type" value="farm" <?php echo $selectedAccountType === 'farm' ? 'checked' : ''; ?> data-login-type> Farm login</label>
                    <span class="toggle-divider" aria-hidden="true"></span>
                    <label><input type="radio" name="account_type" value="platform" <?php echo $selectedAccountType === 'platform' ? 'checked' : ''; ?> data-login-type> Platform owner</label>
                </div>
                <div id="farmWorkspaceField">
                    <label for="farm_slug">Farm Workspace ID</label>
                    <input id="farm_slug" name="farm_slug" type="text" required autofocus autocapitalize="none" placeholder="e.g. green-valley-farm">
                    <small class="form-hint">Use the workspace ID created by the platform owner when your farm was added.</small>
                </div>
                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required>
                </div>
                <div>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <button class="button" type="submit">Enter Workspace</button>
            </form>

            <p class="helper">Need access? Contact your administrator for account setup.</p>
        </section>
    </main>
<script>
function syncLoginType() {
  const checked = document.querySelector('[data-login-type]:checked');
  const isPlatform = checked && checked.value === 'platform';
  const workspace = document.getElementById('farmWorkspaceField');
  const input = document.getElementById('farm_slug');
  workspace.style.display = isPlatform ? 'none' : '';
  input.required = !isPlatform;
}
document.querySelectorAll('[data-login-type]').forEach((field) => field.addEventListener('change', syncLoginType));
syncLoginType();
</script>
</body>
</html>
