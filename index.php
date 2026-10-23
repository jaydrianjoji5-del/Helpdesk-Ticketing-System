<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
// PHP/MariaDB API and static frontend entry point. Configure DB_* in Apache/PHP.
const MAX_UPLOAD = 8388608;

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'helpdesk';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function reply($data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array {
    // PHP parses multipart POST fields into $_POST; php://input is not a JSON body here.
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) return $_POST;
    $raw=file_get_contents('php://input'); $d=json_decode($raw ?: '{}',true);
    return is_array($d) ? $d : $_POST;
}
function safeUser($u): ?array { if(!$u)return null; $safe=array_intersect_key($u,array_flip(['id','username','name','role','category','active','email','email_verified','two_factor_enabled']));$safe['email_verified']=(bool)($u['email_verified']??false);$safe['two_factor_enabled']=(bool)($u['two_factor_enabled']??false);$safe['has_avatar']=!empty($u['avatar_path']);return $safe; }
function applicationSecret(): string { $secret=getenv('APP_SECRET')?:getenv('DB_PASSWORD')?:@file_get_contents(__DIR__.'/data/app-secret');if(!$secret)throw new RuntimeException('Run setup.php or configure APP_SECRET before using email verification.');return $secret; }
function otpDigest(string $code): string { return hash_hmac('sha256',$code,applicationSecret()); }
function publicTicketToken(int $id): string { return substr(hash_hmac('sha256','public-ticket:'.$id,applicationSecret()),0,32); }
function mailFromAddress(): string {
    $configured=getenv('MAIL_FROM');
    $from=trim($configured!==false&&$configured!==''?$configured:(string)ini_get('sendmail_from'));
    if($from==='')$from='no-reply@localhost.localdomain';
    if(!filter_var($from,FILTER_VALIDATE_EMAIL))reply(['error'=>'MAIL_FROM or PHP sendmail_from must be a valid email address.'],503);
    return $from;
}
function sendEmailCode(int $userId,string $email,string $purpose): void {
    $recent=one('SELECT id FROM email_codes WHERE user_id=? AND purpose=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)',[$userId,$purpose]);
    if($recent)reply(['error'=>'Wait a minute before requesting another code.'],429);
    $from=mailFromAddress();
    $code=(string)random_int(100000,999999);$expires=date('Y-m-d H:i:s',time()+600);
    db()->prepare('DELETE FROM email_codes WHERE user_id=? AND purpose=?')->execute([$userId,$purpose]);
    db()->prepare('INSERT INTO email_codes(user_id,purpose,target_email,code_hash,expires_at) VALUES(?,?,?,?,?)')->execute([$userId,$purpose,$email,otpDigest($code),$expires]);
    try{$previousSender=ini_get('sendmail_from');if(ini_set('sendmail_from',$from)===false)throw new RuntimeException('Set sendmail_from to MAIL_FROM in C:\\xampp\\php\\php.ini and restart Apache.');try{$mailer=new PHPMailer\PHPMailer\PHPMailer(true);$mailer->isMail();$mailer->setFrom($from,'Helpdesk');$mailer->addAddress($email);$mailer->Subject='Your Helpdesk verification code';$mailer->Body="Your verification code is $code. It expires in 10 minutes. If you did not request this, ignore this email.";$mailer->send();}finally{ini_set('sendmail_from',(string)$previousSender);}}
    catch(Throwable $e){db()->prepare('DELETE FROM email_codes WHERE user_id=? AND purpose=?')->execute([$userId,$purpose]);$detail=trim(preg_replace('/[\r\n\t]+/',' ',$e->getMessage()));$phpError=error_get_last();if($phpError&&str_contains((string)$phpError['message'],'mail(): SMTP server response:'))$detail=trim(preg_replace('/[\r\n\t]+/',' ',$phpError['message']));$detail=mb_substr($detail,0,240);error_log('OTP email delivery failed: '.$detail);$message='Email delivery failed. Check the local mail relay and MAIL_FROM.';if($detail!=='')$message.=' Mailer: '.$detail;reply(['error'=>$message],503);}
}
function consumeEmailCode(int $userId,string $purpose,string $code,?string $email=null): bool {
    $record=one('SELECT id,code_hash,attempts,target_email FROM email_codes WHERE user_id=? AND purpose=? AND consumed_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1',[$userId,$purpose]);
    if(!$record||(int)$record['attempts']>=5||($email!==null&&!hash_equals((string)$record['target_email'],$email)))return false;
    if(!hash_equals($record['code_hash'],otpDigest($code))){db()->prepare('UPDATE email_codes SET attempts=attempts+1 WHERE id=?')->execute([$record['id']]);return false;}
    db()->prepare('UPDATE email_codes SET consumed_at=NOW() WHERE id=?')->execute([$record['id']]);return true;
}
function currentUser(): ?array { $id=$_SESSION['uid']??0; if(!$id)return null; $q=db()->prepare('SELECT * FROM users WHERE id=? AND active=1');$q->execute([$id]);return $q->fetch() ?: null; }
function needUser(array $roles=[]): array { $u=currentUser(); if(!$u)reply(['error'=>'Please sign in.'],401); if($roles && !in_array($u['role'],$roles,true))reply(['error'=>'You do not have permission to do that.'],403); return $u; }
function rows(string $sql,array $args=[]): array { $q=db()->prepare($sql);$q->execute($args);return $q->fetchAll(); }
function one(string $sql,array $args=[]) { $q=db()->prepare($sql);$q->execute($args);return $q->fetch(); }
function ticket(int $id): ?array {
    $t=one('SELECT * FROM tickets WHERE id=?',[$id]); if(!$t)return null;
    $t['id']=(int)$t['id'];$t['requester']=$t['requester_name'] ?: (one('SELECT name FROM users WHERE id=?',[$t['requester_id']])['name'] ?? 'Public requester');
    $t['assignees']=rows("SELECT u.id,u.name,u.username FROM user_ticket ut JOIN users u ON u.id=ut.user_id WHERE ut.ticket_id=? AND ut.assignment_role='assignee'",[$id]);
    $t['files']=rows('SELECT id,kind,original_name,mime_type,created_at FROM ticket_files WHERE ticket_id=? ORDER BY id',[$id]);
    $t['form']=one('SELECT f.id,f.title,f.status,f.comments FROM ticket_form tf JOIN forms f ON f.id=tf.form_id WHERE tf.ticket_id=? ORDER BY f.id DESC LIMIT 1',[$id]);return $t;
}
function uploaded(string $field, int $tid, ?int $uid, string $kind): void {
    if(empty($_FILES[$field])) return;
    if(!is_array($_FILES[$field]['name'])) foreach(['name','type','tmp_name','error','size'] as $key) $_FILES[$field][$key]=[$_FILES[$field][$key]];
    $allowed=$kind==='screenshot'?['image/png','image/jpeg','image/gif']:['application/pdf','image/png','image/jpeg'];
    $dir=__DIR__.'/data/uploads'; if(!is_dir($dir))mkdir($dir,0770,true);
    foreach($_FILES[$field]['name'] as $i=>$name){ if($_FILES[$field]['error'][$i]===UPLOAD_ERR_NO_FILE)continue; if($_FILES[$field]['error'][$i]!==UPLOAD_ERR_OK || $_FILES[$field]['size'][$i]>MAX_UPLOAD)throw new RuntimeException('Each file must be 8 MB or smaller.');
      $tmp=$_FILES[$field]['tmp_name'][$i];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!in_array($mime,$allowed,true))throw new RuntimeException('Unsupported file type: '.$name);
      $clean=mb_substr(basename($name),0,180);$ext=substr(strtolower(pathinfo($clean,PATHINFO_EXTENSION)),0,12);$stored=bin2hex(random_bytes(18)).($ext?'.'.$ext:'');
      if(!move_uploaded_file($tmp,"$dir/$stored") && !rename($tmp,"$dir/$stored"))throw new RuntimeException('Could not store uploaded file.');
      $actual=$field==='signed_form'?'signed_form':$kind;$q=db()->prepare('INSERT INTO ticket_files(ticket_id,uploader_id,kind,original_name,stored_name,mime_type) VALUES(?,?,?,?,?,?)');$q->execute([$tid,$uid,$actual,$clean,$stored,$mime]);
    }
}
session_name('helpdesk_session'); session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']),'path'=>'/']); session_start();
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';$method=$_SERVER['REQUEST_METHOD'];
$appBase=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/');
if($appBase!=='' && ($path===$appBase || str_starts_with($path,$appBase.'/'))) $path=substr($path,strlen($appBase)) ?: '/';
// PHP does not populate $_FILES for multipart PUT requests, which the existing UI uses for signed forms.
if($method==='PUT' && str_contains($_SERVER['CONTENT_TYPE']??'','multipart/form-data')) {
    $raw=file_get_contents('php://input');
    if(preg_match('/boundary=(?:"([^"]+)"|([^;]+))/',$_SERVER['CONTENT_TYPE'],$bm)) {
        $boundary='--'.trim($bm[1]?:$bm[2]);
        foreach(explode($boundary,$raw) as $part) {
            $part=ltrim($part,"\r\n"); if(!$part||$part==='--')continue;
            [$headers,$value]=array_pad(explode("\r\n\r\n",$part,2),2,'');$value=preg_replace('/\r\n--?\s*$/','',$value);
            if(!preg_match('/Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?/i',$headers,$dm))continue;
            $field=$dm[1]; if(!isset($dm[2])){$_POST[$field]=$value;continue;} if($dm[2]==='')continue;
            $tmp=tempnam(sys_get_temp_dir(),'hd_');file_put_contents($tmp,$value);$mime='application/octet-stream';if(preg_match('/Content-Type:\s*([^\r\n]+)/i',$headers,$mm))$mime=trim($mm[1]);
            $_FILES[$field]=['name'=>$dm[2],'type'=>$mime,'tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>strlen($value)];
        }
    }
}
try {
 if(str_starts_with($path,'/api/')) {
  if($method==='GET'){
   if(preg_match('#^/api/public/tickets/(\d+)$#',$path,$m)){if(!hash_equals(publicTicketToken((int)$m[1]),strtolower((string)($_GET['access_key']??''))))reply(['error'=>'Ticket not found.'],404);$t=one('SELECT id,title,description,status,created_at,updated_at FROM tickets WHERE id=? AND requester_id IS NULL',[(int)$m[1]]);if(!$t)reply(['error'=>'Ticket not found.'],404);$t['id']=(int)$t['id'];reply(['ticket'=>$t]);}
   $u=needUser();
   if($path==='/api/me')reply(['user'=>safeUser($u)]);
    if($path==='/api/me/avatar'){$file=__DIR__.'/data/uploads/avatars/'.basename((string)($u['avatar_path']??''));if(empty($u['avatar_path'])||!is_file($file))reply(['error'=>'Profile picture not found.'],404);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))reply(['error'=>'Invalid profile picture.'],404);header('Content-Type: '.$mime);header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');readfile($file);exit;}
   if($path==='/api/assignees')reply(['users'=>rows("SELECT id,username,name,role,category,active FROM users WHERE active=1 AND role IN ('it_staff','admin') ORDER BY name")]);
   if($path==='/api/users'){$a=needUser(['admin']);$cat=$_GET['category']??'';$sql="SELECT id,username,name,role,category,active FROM users WHERE role IN ('admin','it_staff')";$args=[];if(in_array($cat,['Admin','IT Staff'],true)){$sql.=' AND category=?';$args[]=$cat;}reply(['users'=>rows($sql.' ORDER BY name',$args)]);}
   if($path==='/api/users/stats'){needUser(['admin']);$counts=[];foreach(rows("SELECT category,COUNT(*) n FROM users WHERE active=1 AND role IN ('admin','it_staff') GROUP BY category") as $r)$counts[$r['category']]=(int)$r['n'];reply(['admin'=>$counts['Admin']??0,'it_staff'=>$counts['IT Staff']??0,'total'=>array_sum($counts)]);}
   if($path==='/api/reports')reply(['status'=>rows('SELECT status label,COUNT(*) count FROM tickets GROUP BY status'),'priority'=>rows('SELECT priority label,COUNT(*) count FROM tickets GROUP BY priority'),'requester'=>rows("SELECT COALESCE(t.requester_name,u.name,'Public requester') label,COUNT(*) count FROM tickets t LEFT JOIN users u ON u.id=t.requester_id GROUP BY label ORDER BY count DESC LIMIT 8")]);
   if($path==='/api/tickets'){ $out=[];foreach(rows('SELECT id FROM tickets ORDER BY updated_at DESC') as $r)$out[]=ticket((int)$r['id']);reply(['tickets'=>$out]);}
   if(preg_match('#^/api/files/(\d+)$#',$path,$m)){needUser();$f=one('SELECT * FROM ticket_files WHERE id=?',[(int)$m[1]]);$file=__DIR__.'/data/uploads/'.($f['stored_name']??'');if(!$f||!is_file($file))reply(['error'=>'File not found.'],404);header('Content-Type: '.$f['mime_type']);header('Content-Disposition: inline; filename="'.addcslashes($f['original_name'],'"\\').'"');readfile($file);exit;}
   reply(['error'=>'Not found.'],404);
  }
  if($method==='POST'){
    if($path==='/api/logout'){$_SESSION=[];session_destroy();reply(['ok'=>true]);}
    if($path==='/api/login/cancel'){if(isset($_SESSION['pending_2fa_uid']))db()->prepare("DELETE FROM email_codes WHERE user_id=? AND purpose='login'")->execute([(int)$_SESSION['pending_2fa_uid']]);unset($_SESSION['pending_2fa_uid']);reply(['ok'=>true]);}
    if($path==='/api/login'){unset($_SESSION['pending_2fa_uid']);$d=body();$identity=trim((string)($d['username']??''));$u=one('SELECT * FROM users WHERE (username=? OR email=?) AND active=1',[$identity,$identity]);if(!$u||!password_verify((string)($d['password']??''),$u['password_hash']))reply(['error'=>'Account details are incorrect.'],401);if((int)$u['two_factor_enabled']===1){if(!(int)$u['email_verified']||!$u['email'])reply(['error'=>'Email two-factor authentication is not configured for this account.'],403);session_regenerate_id(true);unset($_SESSION['uid']);$_SESSION['pending_2fa_uid']=(int)$u['id'];sendEmailCode((int)$u['id'],$u['email'],'login');reply(['requires_two_factor'=>true]);}session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];reply(['user'=>safeUser($u)]);}
    if($path==='/api/login/verify'){$uid=(int)($_SESSION['pending_2fa_uid']??0);$d=body();$u=$uid?one('SELECT * FROM users WHERE id=? AND active=1 AND two_factor_enabled=1 AND email_verified=1',[$uid]):null;if(!$u||!consumeEmailCode($uid,'login',trim((string)($d['code']??''))))reply(['error'=>'The verification code is invalid or expired.'],401);session_regenerate_id(true);unset($_SESSION['pending_2fa_uid']);$_SESSION['uid']=$uid;reply(['user'=>safeUser($u)]);}
    if($path==='/api/me/password'){$u=needUser(['it_staff','admin']);$d=body();$current=(string)($d['currentPassword']??'');$next=(string)($d['newPassword']??'');if(!password_verify($current,$u['password_hash']))reply(['error'=>'Current password is incorrect.'],400);if(strlen($next)<10||strlen($next)>200)reply(['error'=>'New password must be between 10 and 200 characters.'],400);db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($next,PASSWORD_DEFAULT),$u['id']]);reply(['ok'=>true]);}
   if($path==='/api/me/email-code'){$u=needUser(['it_staff','admin']);$d=body();$email=trim((string)($d['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)reply(['error'=>'Enter a valid email address.'],400);if(one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']]))reply(['error'=>'That email address is already in use.'],409);sendEmailCode((int)$u['id'],$email,'enroll');reply(['ok'=>true]);}
    if($path==='/api/me/email-verify'){$u=needUser(['it_staff','admin']);$d=body();$email=trim((string)($d['email']??''));$code=trim((string)($d['code']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!consumeEmailCode((int)$u['id'],'enroll',$code,$email))reply(['error'=>'The verification code is invalid or expired.'],400);if(one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']]))reply(['error'=>'That email address is already in use.'],409);try{db()->prepare('UPDATE users SET email=?,email_verified=1 WHERE id=?')->execute([$email,$u['id']]);}catch(PDOException $e){if($e->getCode()==='23000')reply(['error'=>'That email address is already in use.'],409);throw $e;}reply(['user'=>safeUser(one('SELECT * FROM users WHERE id=?',[$u['id']]))]);}
    if($path==='/api/me/avatar'){$u=needUser(['it_staff','admin']);$upload=$_FILES['avatar']??null;if(!$upload||$upload['error']!==UPLOAD_ERR_OK)reply(['error'=>'Choose a profile picture to upload.'],400);if($upload['size']>3*1024*1024)reply(['error'=>'Profile pictures must be 3 MB or smaller.'],400);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$image=@getimagesize($upload['tmp_name']);if(!isset($extensions[$mime])||!$image||$image[0]*$image[1]>16000000)reply(['error'=>'Use a valid JPG, PNG or WebP image no larger than 16 megapixels.'],400);$dir=__DIR__.'/data/uploads/avatars';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))reply(['error'=>'Could not prepare profile picture storage.'],500);$filename=bin2hex(random_bytes(18)).'.'.$extensions[$mime];$path=$dir.'/'.$filename;if(!move_uploaded_file($upload['tmp_name'],$path))reply(['error'=>'Could not save the profile picture.'],500);$old=$u['avatar_path']??null;db()->prepare('UPDATE users SET avatar_path=? WHERE id=?')->execute([$filename,$u['id']]);if($old&&basename($old)===$old&&is_file($dir.'/'.$old))@unlink($dir.'/'.$old);reply(['user'=>safeUser(one('SELECT * FROM users WHERE id=?',[$u['id']]))]);}
   if(in_array($path,['/api/public/tickets','/api/tickets'],true)){$u=$path==='/api/tickets'?needUser():null;$d=body();$title=trim((string)($d['title']??''));$desc=trim((string)($d['description']??''));$name=mb_substr(trim((string)($d['name']??($u['name']??''))),0,100);$email=mb_substr(trim((string)($d['email']??'')),0,254);if(!$title||!$desc)reply(['error'=>'Enter a title and description.'],400);if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))reply(['error'=>'Enter a valid email address or leave it blank.'],400);$q=db()->prepare('INSERT INTO tickets(title,description,requester_id,requester_name,requester_email) VALUES(?,?,?,?,?)');$q->execute([$title,$desc,$u['id']??null,$name?:'Public requester',$email?:null]);$id=(int)db()->lastInsertId();db()->prepare('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)')->execute([$id,$u['id']??null,$u?'Ticket submitted by signed-in user':'Ticket submitted through public form']);uploaded('screenshots',$id,$u['id']??null,'screenshot');if(!$u)reply(['ticket'=>['id'=>$id,'public_access_key'=>publicTicketToken($id)]],201);reply(['ticket'=>ticket($id)],201);}
   $u=needUser();if(preg_match('#^/api/tickets/(\d+)/form$#',$path,$m)){needUser(['it_staff','admin']);$id=(int)$m[1];$t=one('SELECT * FROM tickets WHERE id=?',[$id]);if(!$t)reply(['error'=>'Ticket not found.'],404);$f=one("SELECT f.id FROM forms f JOIN ticket_form tf ON tf.form_id=f.id WHERE tf.ticket_id=? AND f.status='Pending signature' ORDER BY f.id DESC LIMIT 1",[$id]);if(!$f){db()->prepare("INSERT INTO forms(title,status,created_by) VALUES(?, 'Pending signature', ?)")->execute(['Completion form for '.$t['title'],$u['id']]);$fid=(int)db()->lastInsertId();db()->prepare('INSERT INTO ticket_form(ticket_id,form_id) VALUES(?,?)')->execute([$id,$fid]);db()->prepare('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)')->execute([$id,$u['id'],'Completion form prepared for signature']);}else $fid=(int)$f['id'];reply(['form_id'=>$fid],201);}
   if($path==='/api/users'){needUser(['admin']);$d=body();$name=trim((string)($d['name']??''));$username=trim((string)($d['username']??''));$pw=(string)($d['password']??'');$cat=$d['category']??'';if(!$name||mb_strlen($name)>100||mb_strlen($username)<3||mb_strlen($username)>60||strlen($pw)<10||!in_array($cat,['Admin','IT Staff'],true))reply(['error'=>'Enter a name, username, Admin or IT Staff category, and password of at least 10 characters.'],400);try{db()->prepare('INSERT INTO users(username,name,password_hash,role,category) VALUES(?,?,?,?,?)')->execute([$username,$name,password_hash($pw,PASSWORD_DEFAULT),$cat==='Admin'?'admin':'it_staff',$cat]);}catch(PDOException $e){if($e->getCode()==='23000')reply(['error'=>'That username is already in use.'],409);throw $e;}reply(['user'=>safeUser(one('SELECT * FROM users WHERE id=?',[db()->lastInsertId()]))],201);}
  }
  if($method==='PATCH'&&preg_match('#^/api/tickets/(\d+)$#',$path,$m)){ $u=needUser(['it_staff','admin']);$id=(int)$m[1];$t=one('SELECT * FROM tickets WHERE id=?',[$id]);if(!$t)reply(['error'=>'Ticket not found.'],404);$d=body();$pdo=db();if(isset($d['priority'])){if(!in_array($d['priority'],['Low','Medium','High','Urgent'],true))reply(['error'=>'Invalid priority.'],400);$pdo->prepare('UPDATE tickets SET priority=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$d['priority'],$id]);}if(isset($d['assignees'])){if(!is_array($d['assignees']))reply(['error'=>'Assignees must be a list.'],400);foreach($d['assignees'] as $aid)if(!one("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('it_staff','admin')",[(int)$aid]))reply(['error'=>'Select active IT staff accounts.'],400);$pdo->prepare("DELETE FROM user_ticket WHERE ticket_id=? AND assignment_role='assignee'")->execute([$id]);foreach(array_unique(array_map('intval',$d['assignees'])) as $aid)$pdo->prepare("INSERT INTO user_ticket(user_id,ticket_id,assignment_role) VALUES(?,?,'assignee')")->execute([$aid,$id]);if($d['assignees']&&$t['status']==='Open')$pdo->prepare("UPDATE tickets SET status='In Progress',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);}if(isset($d['status'])){if(!in_array($d['status'],['Open','In Progress','Closed'],true))reply(['error'=>'Invalid status.'],400);if($d['status']==='Closed'){if(!one("SELECT id FROM ticket_files WHERE ticket_id=? AND kind='signed_form'",[$id]))reply(['error'=>'Upload the signed completion form before closing this ticket.'],400);$pdo->prepare("UPDATE tickets SET status='Closed',closed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);$pdo->prepare("UPDATE forms SET status='Signed' WHERE id IN (SELECT form_id FROM ticket_form WHERE ticket_id=?)")->execute([$id]);}else $pdo->prepare('UPDATE tickets SET status=?,closed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$d['status'],$id]);}$pdo->prepare('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)')->execute([$id,$u['id'],mb_substr((string)($d['comment']??'Ticket updated'),0,500)]);reply(['ticket'=>ticket($id)]);}
  if($method==='PUT'){
    if($path==='/api/me'){$u=needUser(['it_staff','admin']);$d=body();$name=trim((string)($d['name']??$u['name']));if(!$name||mb_strlen($name)>100)reply(['error'=>'Enter a name of 1 to 100 characters.'],400);$twoFactor=(bool)($d['two_factor_enabled']??$u['two_factor_enabled']);if($twoFactor&&(!(int)$u['email_verified']||!$u['email']))reply(['error'=>'Verify an email address before enabling email two-factor authentication.'],400);db()->prepare('UPDATE users SET name=?,two_factor_enabled=? WHERE id=?')->execute([$name,(int)$twoFactor,$u['id']]);reply(['user'=>safeUser(one('SELECT * FROM users WHERE id=?',[$u['id']]))]);}
   if(preg_match('#^/api/users/(\d+)$#',$path,$m)){$u=needUser(['admin']);$d=body();$cat=$d['category']??'';$name=trim((string)($d['name']??''));if(!$name||mb_strlen($name)>100||!in_array($cat,['IT Staff','Admin'],true))reply(['error'=>'Enter a valid name and Admin/IT Staff category.'],400);$q=db()->prepare('UPDATE users SET name=?,role=?,category=? WHERE id=?');$q->execute([$name,$cat==='Admin'?'admin':'it_staff',$cat,(int)$m[1]]);if(!$q->rowCount()&&!one('SELECT id FROM users WHERE id=?',[(int)$m[1]]))reply(['error'=>'User not found.'],404);reply(['user'=>safeUser(one('SELECT * FROM users WHERE id=?',[(int)$m[1]]))]);}
   $u=needUser(['it_staff','admin']);if(!preg_match('#^/api/tickets/(\d+)/form$#',$path,$m))reply(['error'=>'Not found.'],404);$id=(int)$m[1];$t=one('SELECT * FROM tickets WHERE id=?',[$id]);if(!$t)reply(['error'=>'Ticket not found.'],404);if(empty($_FILES['signed_form']))reply(['error'=>'Choose a signed PDF or image form to upload.'],400);uploaded('signed_form',$id,(int)$u['id'],'signed_form');$f=one("SELECT f.id FROM forms f JOIN ticket_form tf ON tf.form_id=f.id WHERE tf.ticket_id=? AND f.status='Pending signature' ORDER BY f.id DESC LIMIT 1",[$id]);if($f)db()->prepare("UPDATE forms SET status='Signed',comments=? WHERE id=?")->execute([mb_substr((string)($_POST['comments']??''),0,2000),$f['id']]);else{db()->prepare("INSERT INTO forms(title,status,comments,created_by) VALUES(?, 'Signed', ?, ?)")->execute(['Completion form for '.$t['title'],mb_substr((string)($_POST['comments']??''),0,2000),$u['id']]);$fid=db()->lastInsertId();db()->prepare('INSERT INTO ticket_form(ticket_id,form_id) VALUES(?,?)')->execute([$id,$fid]);}if($t['status']==='Closed')db()->prepare("UPDATE tickets SET status='In Progress',closed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);db()->prepare('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)')->execute([$id,$u['id'],'Signed completion form uploaded']);reply(['ticket'=>ticket($id)],201);
  }
  if($method==='DELETE'){$u=needUser(['admin']);if(!preg_match('#^/api/(users|tickets)/(\d+)$#',$path,$m))reply(['error'=>'Not found.'],404);$id=(int)$m[2];if($m[1]==='tickets'){$files=rows('SELECT stored_name FROM ticket_files WHERE ticket_id=?',[$id]);$q=db()->prepare('DELETE FROM tickets WHERE id=?');$q->execute([$id]);if(!$q->rowCount())reply(['error'=>'Ticket not found.'],404);foreach($files as $f)@unlink(__DIR__.'/data/uploads/'.$f['stored_name']);reply(['ok'=>true]);}if($id===(int)$u['id'])reply(['error'=>'You cannot remove your own account.'],400);$q=db()->prepare('UPDATE users SET active=0 WHERE id=?');$q->execute([$id]);if(!$q->rowCount())reply(['error'=>'User not found.'],404);reply(['ok'=>true]);}
  reply(['error'=>'Not found.'],404);
 }
} catch(Throwable $e) { if($e instanceof RuntimeException)reply(['error'=>$e->getMessage()],400); error_log((string)$e);reply(['error'=>'The server could not complete this request.'],500); }
// Keep the existing frontend entry point and all its asset paths unchanged.
if(preg_match('#^/tickets/(?:lookup|\d+)/?$#',$path)){
    $requestPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
    $assetBase=$appBase;
    if(preg_match('#^(.*)/tickets/(?:lookup|\d+)/?$#',$requestPath,$routeMatch))$assetBase=rtrim($routeMatch[1],'/');
    $html=file_get_contents(__DIR__.'/index.html');
    if($html===false){http_response_code(500);exit('Could not load the public ticket page.');}
    $base=$assetBase===''?'/':$assetBase.'/';
    $html=preg_replace('~(<head\b[^>]*>)~i','$1<base href="'.htmlspecialchars($base,ENT_QUOTES,'UTF-8').'">',$html,1);
    header('Content-Type: text/html; charset=utf-8');echo $html;exit;
}
if($path==='/')$path='/index.html';$file=realpath(__DIR__.$path);$root=realpath(__DIR__);if(!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit('Not found');} $mime=mime_content_type($file)?:'application/octet-stream';header('Content-Type: '.$mime);readfile($file);
