<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Kolkata');

$dbPath = __DIR__ . '/data/fampay.sqlite';
if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0775, true);
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'merchant', upi_id TEXT DEFAULT '', created_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, order_id TEXT UNIQUE NOT NULL, amount REAL NOT NULL, status TEXT NOT NULL, customer TEXT DEFAULT '', method TEXT DEFAULT 'UPI', created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (user_id INTEGER PRIMARY KEY, upi_id TEXT DEFAULT '', business_name TEXT DEFAULT 'My Business', FOREIGN KEY(user_id) REFERENCES users(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, label TEXT NOT NULL, public_key TEXT NOT NULL, secret_hash TEXT NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, subject TEXT NOT NULL, message TEXT NOT NULL, status TEXT DEFAULT 'Open', created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id))");

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function go(string $page='dashboard'): never { header('Location: ?page='.urlencode($page)); exit; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals(csrf(), $_POST['csrf'] ?? '')) { http_response_code(419); exit('Session expired. Go back and retry.'); } }
function money($v): string { return '₹'.number_format((float)$v,2); }

$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@fampayxl.local';
$adminPass = getenv('ADMIN_PASSWORD') ?: 'ChangeMe123!';
$exists = $pdo->prepare("SELECT id FROM users WHERE email=?"); $exists->execute([$adminEmail]);
if (!$exists->fetch()) {
  $st=$pdo->prepare("INSERT INTO users(name,email,password,role,created_at) VALUES(?,?,?,?,?)");
  $st->execute(['FAMPAY.XL Admin',$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT),'admin',date('Y-m-d H:i:s')]);
}
$page = $_GET['page'] ?? 'home';
$notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf(); $action=$_POST['action'] ?? '';
  if ($action==='register') {
    $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??'';
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<8) $notice='Enter a name, valid email and password (8+ characters).';
    else {
      try {
        $s=$pdo->prepare("INSERT INTO users(name,email,password,role,created_at) VALUES(?,?,?,?,?)");
        $s->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),'merchant',date('Y-m-d H:i:s')]);
        $_SESSION['user']=['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'email'=>$email,'role'=>'merchant'];
        $_SESSION['notice']='Welcome to FAMPAY.XL demo.'; go('dashboard');
      } catch(Throwable $e) { $notice='That email is already registered.'; }
    }
    $page='register';
  } elseif ($action==='login') {
    $s=$pdo->prepare("SELECT * FROM users WHERE email=?"); $s->execute([strtolower(trim($_POST['email']??''))]); $u=$s->fetch(PDO::FETCH_ASSOC);
    if ($u && password_verify($_POST['password']??'', $u['password'])) { $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]; go('dashboard'); }
    $notice='Incorrect email or password.'; $page='login';
  } elseif ($action==='logout') { session_destroy(); header('Location: /'); exit; }
  elseif ($action==='save_upi' && current_user()) {
    $uid=current_user()['id']; $upi=trim($_POST['upi_id']??''); $biz=trim($_POST['business_name']??'My Business');
    if ($upi!=='' && !preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/',$upi)) $notice='UPI ID format looks invalid.';
    else { $s=$pdo->prepare("INSERT INTO settings(user_id,upi_id,business_name) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET upi_id=excluded.upi_id,business_name=excluded.business_name"); $s->execute([$uid,$upi,$biz]); $notice='UPI profile saved. QR uses this UPI ID.'; }
    $page='integrations';
  } elseif ($action==='demo_payment' && current_user()) {
    $amount=(float)($_POST['amount']??0); $uid=current_user()['id'];
    if ($amount<1 || $amount>1000000) $notice='Enter a demo amount between ₹1 and ₹10,00,000.';
    else { $oid='FX'.date('ymdHis').strtoupper(bin2hex(random_bytes(2))); $s=$pdo->prepare("INSERT INTO transactions(user_id,order_id,amount,status,customer,created_at) VALUES(?,?,?,?,?,?)"); $s->execute([$uid,$oid,$amount,$_POST['result']==='success'?'SUCCESS':'FAILED','Demo Customer',date('Y-m-d H:i:s')]); $notice='Sandbox transaction created: '.$oid; }
    $page='transactions';
  } elseif ($action==='create_key' && current_user()) {
    $uid=current_user()['id']; $secret='fx_live_demo_'.bin2hex(random_bytes(18)); $pub='fx_pub_demo_'.bin2hex(random_bytes(10));
    $s=$pdo->prepare("INSERT INTO api_keys(user_id,label,public_key,secret_hash,created_at) VALUES(?,?,?,?,?)"); $s->execute([$uid,trim($_POST['label']??'Default key')?:'Default key',$pub,password_hash($secret,PASSWORD_DEFAULT),date('Y-m-d H:i:s')]);
    $_SESSION['new_secret']=$secret; $_SESSION['new_public']=$pub; $notice='Demo API key created. Copy the secret now; it is shown only once.'; $page='api';
  } elseif ($action==='ticket' && current_user()) {
    $s=$pdo->prepare("INSERT INTO tickets(user_id,subject,message,created_at) VALUES(?,?,?,?)"); $s->execute([current_user()['id'],trim($_POST['subject']??''),trim($_POST['message']??''),date('Y-m-d H:i:s')]); $notice='Support ticket created.'; $page='support';
  }
}
$user=current_user();
$settings=null;
if ($user) { $s=$pdo->prepare("SELECT * FROM settings WHERE user_id=?"); $s->execute([$user['id']]); $settings=$s->fetch(PDO::FETCH_ASSOC) ?: ['upi_id'=>'','business_name'=>'My Business']; }
if ($user && $page==='home') $page='dashboard';
if (in_array($page,['dashboard','transactions','links','api','webhooks','integrations','accounts','profile','support','docs','status','admin']) && !$user) go('login');
$nav=[['dashboard','Dashboard','grid'],['transactions','Transactions','receipt'],['links','Payment Links','link'],['api','API Keys','key'],['webhooks','Webhooks','send'],['integrations','Integrations','plug'],['accounts','Multi-Accounts','layers'],['profile','Profile','user'],['support','Support Tickets','help-circle'],['docs','Documentation','book-open'],['status','System Status','activity']];
function icon_svg($name){$paths=['grid'=>'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>','receipt'=>'<path d="M4 2h16v20l-4-2-4 2-4-2-4 2z"/><path d="M8 8h8M8 12h8"/>','link'=>'<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20l1.1-1.1"/>','key'=>'<circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 8-8 2 2-2 2 2 2-3 3-2-2-3 3"/>','send'=>'<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>','plug'=>'<path d="M9 7V3m6 4V3M5 7h14v4a7 7 0 0 1-14 0zM12 18v3"/>','layers'=>'<path d="m12 2 9 5-9 5-9-5zM3 12l9 5 9-5M3 17l9 5 9-5"/>','user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/>','help-circle'=>'<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3m.1 4h.01"/>','book-open'=>'<path d="M2 4h7a4 4 0 0 1 4 4v14a4 4 0 0 0-4-4H2zM22 4h-7a4 4 0 0 0-4 4v14a4 4 0 0 1 4-4h7z"/>','activity'=>'<path d="M3 12h4l3-9 4 18 3-9h4"/>']; return '<svg viewBox="0 0 24 24" aria-hidden="true">'.($paths[$name]??'').'</svg>'; }
function shell_start($title,$user,$nav,$page,$notice){ ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> · FAMPAY.XL</title><link rel="stylesheet" href="style.css"></head><body>
<div class="app"><aside class="sidebar"><a class="brand" href="?page=dashboard"><span class="brandmark">F</span><span>FAM<span>PAY.XL</span><small>PAYMENT PLATFORM</small></span></a><div class="navlabel">MAIN</div><?php if(($user['role']??'')==='admin'): ?><a class="navitem <?=$page==='admin'?'active':''?>" href="?page=admin">◎ <span>Admin Panel</span></a><?php endif; ?><?php foreach($nav as $n): if(in_array($n[0],['profile','support','docs','status'])) continue; ?><a class="navitem <?=$page===$n[0]?'active':''?>" href="?page=<?=$n[0]?>"><?=icon_svg($n[2])?><span><?=h($n[1])?></span></a><?php endforeach; ?><div class="navlabel">SETTINGS</div><?php foreach($nav as $n): if(!in_array($n[0],['profile','support','docs','status'])) continue; ?><a class="navitem <?=$page===$n[0]?'active':''?>" href="?page=<?=$n[0]?>"><?=icon_svg($n[2])?><span><?=h($n[1])?></span></a><?php endforeach; ?><form method="post" class="logout"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="logout"><button>↪ Sign out</button></form></aside><main class="main"><header class="topbar"><div class="mobilebrand">FAMPAY.XL</div><span class="env">SANDBOX MODE</span><div class="userchip"><span class="avatar"><?=strtoupper(substr($user['name']??'F',0,1))?></span><?=h($user['name']??'Guest')?></div></header><section class="content"><?php if($notice): ?><div class="notice"><?=h($notice)?></div><?php endif; ?>
<?php }
function shell_end(){?></section></main></div><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script src="app.js"></script></body></html><?php }

if ($page==='home' || $page==='login' || $page==='register') {
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FAMPAY.XL · Payments, simplified</title><link rel="stylesheet" href="style.css"></head><body class="landing"><nav class="landingnav"><a class="brand darkbrand" href="/"><span class="brandmark">F</span><span>FAM<span>PAY.XL</span><small>PAYMENT PLATFORM</small></span></a><div><a href="?page=docs">Developers</a><a href="?page=login">Login</a><a class="btn primary" href="?page=register">Get started</a></div></nav><section class="hero"><div class="eyebrow">PAYMENT INFRASTRUCTURE FOR DEVELOPERS</div><h1>Payments, <span>simplified.</span></h1><p>One clean dashboard for payment links, UPI QR creation, transaction tracking and developer integrations.</p><div class="heroactions"><a class="btn primary" href="?page=register">Create free demo account</a><a class="btn ghost" href="?page=docs">Explore API docs</a></div><div class="herofoot">DEMO ENVIRONMENT · NO REAL MONEY IS COLLECTED</div><div class="mockdash"><div class="mocktop"><b>FAMPAY.XL</b><span>● Sandbox active</span></div><div class="mockstats"><div><small>TOTAL REVENUE</small><h2>₹12,450.00</h2></div><div><small>PAYMENT REQUESTS</small><h2>248</h2></div><div><small>SUCCESSFUL</small><h2>216</h2></div></div><div class="mockchart"><div style="height:28%"></div><div style="height:48%"></div><div style="height:38%"></div><div style="height:76%"></div><div style="height:58%"></div><div style="height:92%"></div><div style="height:67%"></div></div></div></section><section class="features"><div><b>UPI QR</b><p>Generate a QR from the UPI ID you configure.</p></div><div><b>Payment links</b><p>Create shareable sandbox checkout links.</p></div><div><b>Developer API</b><p>Demo credentials and webhook documentation.</p></div></section><?php if($page==='login'||$page==='register'): ?><div class="authwrap"><form class="authcard" method="post"><h2><?=$page==='login'?'Welcome back':'Create your account'?></h2><p>Access your FAMPAY.XL sandbox dashboard.</p><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="<?=$page?>"><?php if($page==='register'): ?><label>Full name</label><input name="name" required placeholder="Your name"><?php endif; ?><label>Email address</label><input name="email" type="email" required placeholder="you@example.com"><label>Password</label><input name="password" type="password" minlength="8" required placeholder="At least 8 characters"><button class="btn primary full"><?=$page==='login'?'Login':'Create account'?></button><small><?=$page==='login'?'New here? <a href="?page=register">Create an account</a>':'Already registered? <a href="?page=login">Login</a>'?></small></form></div><?php endif; ?></body></html><?php
exit;
}

shell_start(ucwords(str_replace('-',' ',$page)),$user,$nav,$page,$notice);
$uid=$user['id'];
if ($page==='admin' && ($user['role']??'')==='admin') {
 $totalUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='merchant'")->fetchColumn();
 $totalTx=(int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
 $totalRev=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='SUCCESS'")->fetchColumn();
 $all=$pdo->query("SELECT t.*,u.email FROM transactions t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
 ?><div class="heading"><div><h1>Admin Control Center</h1><p>Platform overview · sandbox data only.</p></div><span class="pill green">Admin access</span></div><div class="stats"><div class="stat"><small>MERCHANT ACCOUNTS</small><strong><?=$totalUsers?></strong><span>Registered demo merchants</span></div><div class="stat"><small>TOTAL REQUESTS</small><strong><?=$totalTx?></strong><span>Sandbox transactions</span></div><div class="stat"><small>SIMULATED VOLUME</small><strong><?=money($totalRev)?></strong><span>Not actual collected funds</span></div><div class="stat"><small>PLATFORM STATUS</small><strong class="greenText">Online</strong><span>Demo environment</span></div></div><div class="panel"><div class="panelhead"><div><h3>Latest platform transactions</h3><p>Across all merchant demo accounts</p></div></div><div class="tablewrap"><table><thead><tr><th>Order ID</th><th>Merchant email</th><th>Created</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach($all as $r): ?><tr><td><?=h($r['order_id'])?></td><td><?=h($r['email'])?></td><td><?=h($r['created_at'])?></td><td><?=money($r['amount'])?></td><td><span class="pill <?=$r['status']==='SUCCESS'?'green':'red'?>"><?=h($r['status'])?></span></td></tr><?php endforeach; ?></tbody></table></div></div><?php
} elseif ($page==='dashboard') {
 $s=$pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN status='SUCCESS' THEN amount ELSE 0 END),0) rev, SUM(CASE WHEN status='SUCCESS' THEN 1 ELSE 0 END) ok, SUM(CASE WHEN status!='SUCCESS' THEN 1 ELSE 0 END) bad FROM transactions WHERE user_id=?"); $s->execute([$uid]); $stats=$s->fetch(PDO::FETCH_ASSOC);
 $s=$pdo->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 6"); $s->execute([$uid]); $recent=$s->fetchAll(PDO::FETCH_ASSOC);
 ?>
 <div class="heading"><div><h1>Good evening, <?=h(explode(' ',$user['name'])[0])?> 👋</h1><p>Here's what's happening with your API today.</p></div><span class="pill green">● Sandbox healthy</span></div>
 <div class="stats"><div class="stat"><small>TOTAL REVENUE</small><strong class="greenText"><?=money($stats['rev'])?></strong><span>Sandbox successes only</span></div><div class="stat"><small>TOTAL REQUESTS</small><strong><?=number_format((int)$stats['c'])?></strong><span>All demo requests</span></div><div class="stat"><small>SUCCESSFUL</small><strong><?=number_format((int)$stats['ok'])?></strong><span>Simulated success</span></div><div class="stat"><small>FAILED / PENDING</small><strong><?=number_format((int)$stats['bad'])?></strong><span>Demo failures</span></div></div>
 <div class="panel setup"><div><h3>Quick Setup Guide</h3><p>Set your UPI ID, create a demo API key and try a sandbox payment.</p><ul class="checklist"><li>✓ Create your account</li><li>○ Add your UPI ID in Integrations</li><li>○ Generate a UPI QR</li><li>○ Create a demo transaction</li><li>○ Explore API documentation</li></ul><a class="btn secondary" href="?page=integrations">Configure integrations</a></div><div class="setupart"><div class="bigicon">⌁</div><b>FAMPAY.XL</b><span>Payment tools for your business</span><small>Sandbox mode only</small></div></div>
 <div class="panel"><div class="panelhead"><div><h3>Recent Transactions</h3><p>Your latest demo orders</p></div><a href="?page=transactions">View all →</a></div><?php render_transactions($recent); ?></div>
 <?php
} elseif ($page==='transactions') {
 $s=$pdo->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC"); $s->execute([$uid]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
 ?><div class="heading"><div><h1>Transactions</h1><p>Review your sandbox payment activity.</p></div><span class="pill">DEMO DATA</span></div><div class="panel"><div class="panelhead"><h3>All transactions</h3></div><?php render_transactions($rows); ?></div><div class="panel"><h3>Create test transaction</h3><p>Choose success or failure to test your dashboard. No money moves.</p><form method="post" class="inlineform"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="demo_payment"><input name="amount" type="number" min="1" max="1000000" step=".01" placeholder="Amount ₹" required><select name="result"><option value="success">Simulate success</option><option value="failed">Simulate failure</option></select><button class="btn primary">Create demo payment</button></form></div><?php
} elseif ($page==='integrations') {
 ?><div class="heading"><div><h1>Integrations</h1><p>Connect your UPI ID for QR generation and configure developer tools.</p></div></div><div class="tabs"><span class="tab active">UPI & QR</span><span class="tab">Setup Instructions</span></div><div class="panel"><div class="panelhead"><div><h3>UPI QR Configuration</h3><p>QR code is generated from the UPI ID you enter below.</p></div><span class="pill green">● Local configuration</span></div><form method="post" class="formgrid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_upi"><label>Business name<input name="business_name" value="<?=h($settings['business_name'])?>" placeholder="My Business"></label><label>UPI ID / VPA<input id="upiInput" name="upi_id" value="<?=h($settings['upi_id'])?>" placeholder="yourname@bank" autocomplete="off"></label><button class="btn primary">Save UPI details</button></form><div class="qrbox"><div><h3>Your UPI QR preview</h3><p>Scan with a UPI app. Verify the payee name in the UPI app before paying.</p><div id="qrcode"></div><small id="qrHint">Save a valid UPI ID to generate a QR.</small></div><div class="qrinfo"><b>UPI payment address</b><p><?=h($settings['upi_id']?:'Not configured')?></p><span class="pill">QR preview only</span><p class="warningtext">This QR opens a UPI payment intent. This website cannot confirm that money was received. Never mark an order paid from a screenshot or browser redirect.</p></div></div></div><div class="panel"><h3>FamPay / Gmail integration</h3><p>Screenshot-style Gmail IMAP integration is not enabled in this free demo. Gmail alerts are not an official payment confirmation channel and can be delayed, duplicated or spoofed. For real automatic verification, use a regulated payment provider's server-to-server webhook or bank-approved API. Do not enter your Gmail password or app password into an untrusted demo.</p><span class="pill amber">Not connected · Safe demo</span></div><?php
} elseif ($page==='links') {
 ?><div class="heading"><div><h1>Payment Links</h1><p>Create shareable demo checkout links.</p></div></div><div class="panel"><h3>UPI checkout preview</h3><p>Set your UPI ID in Integrations first. This link is a demo intent, not a verified collection.</p><a class="btn primary" href="?page=integrations">Configure UPI QR</a></div><div class="panel"><h3>Demo payment link builder</h3><form class="formgrid" onsubmit="event.preventDefault();makeLink()"><label>Amount (INR)<input id="linkAmount" type="number" min="1" step=".01" placeholder="100" required></label><label>Customer note<input id="linkNote" placeholder="Order description"></label><button class="btn primary">Generate preview link</button></form><div id="linkResult" class="result"></div></div><?php
} elseif ($page==='api') {
 $s=$pdo->prepare("SELECT * FROM api_keys WHERE user_id=? ORDER BY id DESC"); $s->execute([$uid]); $keys=$s->fetchAll(PDO::FETCH_ASSOC);
 ?><div class="heading"><div><h1>API Keys</h1><p>Use sandbox credentials to test your integration.</p></div></div><?php if(!empty($_SESSION['new_secret'])): ?><div class="notice secret">Copy once — Public: <?=h($_SESSION['new_public'])?> · Secret: <?=h($_SESSION['new_secret'])?></div><?php unset($_SESSION['new_secret'],$_SESSION['new_public']); endif; ?><div class="panel"><h3>Create API key</h3><form method="post" class="inlineform"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create_key"><input name="label" placeholder="Key label" value="Development key"><button class="btn primary">Generate sandbox key</button></form></div><div class="panel"><h3>Your keys</h3><?php if(!$keys): ?><p>No keys created yet.</p><?php else: foreach($keys as $k): ?><div class="keyrow"><div><b><?=h($k['label'])?></b><small><?=h($k['public_key'])?></small></div><span class="pill green">Sandbox</span></div><?php endforeach; endif; ?></div><?php
} elseif ($page==='webhooks') {
 ?><div class="heading"><div><h1>Webhooks</h1><p>Receive server-side events in your application.</p></div></div><div class="panel"><h3>Webhook endpoint</h3><label>Endpoint URL<input placeholder="https://your-domain.com/webhook" disabled></label><p>Webhook delivery is a documentation/demo placeholder. Live event delivery requires a backend signing secret, retries, idempotency and verified provider events.</p><span class="pill amber">Sandbox documentation</span></div><?php
} elseif ($page==='accounts') {
 ?><div class="heading"><div><h1>Multi-Accounts</h1><p>Organise multiple business profiles.</p></div></div><div class="panel"><h3>Business profile</h3><p><?=h($settings['business_name'])?></p><p>One demo merchant profile is available in this starter.</p><a href="?page=integrations" class="btn secondary">Edit business profile</a></div><?php
} elseif ($page==='profile') {
 ?><div class="heading"><div><h1>Profile</h1><p>Your merchant account details.</p></div></div><div class="panel"><h3>Account details</h3><p><b>Name:</b> <?=h($user['name'])?></p><p><b>Email:</b> <?=h($user['email'])?></p><p><b>Account type:</b> <?=h($user['role'])?></p><p><b>Joined:</b> Demo account</p></div><?php
} elseif ($page==='support') {
 ?><div class="heading"><div><h1>Support Tickets</h1><p>Send a message to the demo support desk.</p></div></div><div class="panel"><form method="post" class="formgrid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="ticket"><label>Subject<input name="subject" required></label><label>Message<textarea name="message" required rows="4"></textarea></label><button class="btn primary">Create ticket</button></form></div><?php
} elseif ($page==='docs') {
 ?><div class="heading"><div><h1>Documentation</h1><p>FAMPAY.XL sandbox integration guide.</p></div></div><div class="panel"><h3>1. Create an account</h3><p>Register and create a sandbox API key from the API Keys section.</p><h3>2. Configure your UPI ID</h3><p>Enter your own UPI ID in Integrations. The QR preview encodes a standard UPI payment URI.</p><h3>3. Verify payments safely</h3><p>For real payments, use an authorised payment provider or bank integration. Verify signed server-to-server webhooks and reconcile transactions. Never trust a client-side success page, QR scan, screenshot or Gmail notification as proof of payment.</p><h3>Demo API example</h3><pre>POST /api/demo-payment
Content-Type: application/json
{
  "amount": 100,
  "currency": "INR",
  "mode": "sandbox"
}</pre><span class="pill amber">Illustrative endpoint — not a live payment API</span></div><?php
} elseif ($page==='status') {
 ?><div class="heading"><div><h1>System Status</h1><p>Demo environment status.</p></div></div><div class="panel statuslist"><p><span class="dot"></span> Dashboard <b>Operational</b></p><p><span class="dot"></span> Local database <b>Operational</b></p><p><span class="dot"></span> UPI QR preview <b>Operational</b></p><p><span class="dot amberdot"></span> Live payment processing <b>Not enabled</b></p></div><?php
} else { ?><div class="panel"><h1>Page not found</h1><a href="?page=dashboard">Back to dashboard</a></div><?php }
shell_end();

function render_transactions($rows){ if(!$rows){echo '<div class="empty">No transactions yet. Create a sandbox transaction to see it here.</div>';return;} echo '<div class="tablewrap"><table><thead><tr><th>Order ID</th><th>Customer</th><th>Method</th><th>Created on</th><th>Amount</th><th>Status</th></tr></thead><tbody>'; foreach($rows as $r){echo '<tr><td>'.h($r['order_id']).'</td><td>'.h($r['customer']).'</td><td>'.h($r['method']).'</td><td>'.h($r['created_at']).'</td><td>'.money($r['amount']).'</td><td><span class="pill '.($r['status']==='SUCCESS'?'green':'red').'">'.h($r['status']).'</span></td></tr>';} echo '</tbody></table></div>'; }
