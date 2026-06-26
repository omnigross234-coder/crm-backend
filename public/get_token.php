<?php
// Run this file ONCE to get your refresh token, then DELETE it

$clientId     = 'your_client_id';
$clientSecret = 'your_client_Secret'; // paste GOCSPX-xxx here
$redirectUri  = 'http://localhost/get_token.php';

if (!isset($_GET['code'])) {
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/drive.file',
        'access_type'   => 'offline',
    ]);
    header('Location: ' . $url);
    exit;
}

// Exchange code for token using file_get_contents — no curl, no SSL issues
$postData = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$result = file_get_contents('https://oauth2.googleapis.com/token', false, $context);
$token  = json_decode($result, true);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Your Refresh Token</title>
  <style>
    body { font-family: sans-serif; max-width: 700px; margin: 60px auto; padding: 20px; }
    .box { background: #f0fdf4; border: 2px solid #16a34a; border-radius: 10px; padding: 24px; }
    .err { background: #fef2f2; border-color: #dc2626; }
    code { background: #1e293b; color: #a3e635; display: block; padding: 16px; border-radius: 8px; font-size: 13px; word-break: break-all; margin-top: 12px; }
    pre  { background: #f1f5f9; padding: 12px; border-radius: 8px; font-size: 12px; overflow-x: auto; }
  </style>
</head>
<body>

<?php if (!empty($token['refresh_token'])): ?>
  <div class="box">
    <h2>✅ Success! Copy these 4 lines into your Laravel <code>.env</code></h2>
    <code>
GOOGLE_DRIVE_CLIENT_ID=<?= $clientId ?><br>
GOOGLE_DRIVE_CLIENT_SECRET=<?= htmlspecialchars($clientSecret) ?><br>
GOOGLE_DRIVE_REFRESH_TOKEN=<?= htmlspecialchars($token['refresh_token']) ?><br>
GOOGLE_DRIVE_FOLDER=CRM_Backups
    </code>
    <p style="color:#dc2626; margin-top:16px;">
      ⚠️ <strong>Delete this file after copying!</strong> 
      (<code>C:\laragon\www\CRM\get_token.php</code>)
    </p>
  </div>

<?php elseif (!empty($token['error'])): ?>
  <div class="box err">
    <h2>❌ Error: <?= htmlspecialchars($token['error']) ?></h2>
    <p><?= htmlspecialchars($token['error_description'] ?? '') ?></p>
    <pre><?= htmlspecialchars(print_r($token, true)) ?></pre>
  </div>

<?php else: ?>
  <div class="box err">
    <h2>⚠️ Unexpected response</h2>
    <pre><?= htmlspecialchars(print_r($token, true)) ?></pre>
  </div>
<?php endif; ?>

</body>
</html>