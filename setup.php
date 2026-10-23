<?php
declare(strict_types=1);
// Run once from the project directory: php setup.php
$host=getenv('DB_HOST')?:'127.0.0.1';$name=getenv('DB_NAME')?:'helpdesk';$user=getenv('DB_USER')?:'root';$pass=getenv('DB_PASSWORD')?:'';
$pdo=new PDO("mysql:host=$host;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`','',$name).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `'.str_replace('`','',$name).'`');
$schema=file_get_contents(__DIR__.'/database.sql');
$schema=preg_replace('/^CREATE DATABASE.*;\s*|^USE .*;\s*/mi','',$schema);
foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema) as $statement){$statement=trim($statement);if($statement!==''&&!str_starts_with($statement,'--'))$pdo->exec($statement);}
$columns=array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC),'Field');
foreach(['email'=>'VARCHAR(254) NULL','email_verified'=>'TINYINT(1) NOT NULL DEFAULT 0','two_factor_enabled'=>'TINYINT(1) NOT NULL DEFAULT 0','avatar_path'=>'VARCHAR(255) NULL'] as $column=>$definition)if(!in_array($column,$columns,true))$pdo->exec("ALTER TABLE users ADD COLUMN `$column` $definition");
$indexes=array_column($pdo->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC),'Key_name');
if(!in_array('uq_users_email',$indexes,true))$pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
$codeColumns=array_column($pdo->query('SHOW COLUMNS FROM email_codes')->fetchAll(PDO::FETCH_ASSOC),'Field');
if(!in_array('target_email',$codeColumns,true))$pdo->exec('ALTER TABLE email_codes ADD COLUMN target_email VARCHAR(254) NULL AFTER purpose');
if(!in_array('consumed_at',$codeColumns,true))$pdo->exec('ALTER TABLE email_codes ADD COLUMN consumed_at DATETIME NULL AFTER attempts');
$pdo->exec('ALTER TABLE tickets MODIFY title LONGTEXT NOT NULL, MODIFY description LONGTEXT NOT NULL');
$q=$pdo->query('SELECT COUNT(*) FROM users');
if((int)$q->fetchColumn()===0){$insert=$pdo->prepare('INSERT INTO users(username,name,password_hash,role,category) VALUES(?,?,?,?,?)');$adminPassword=bin2hex(random_bytes(18));$itStaffPassword=bin2hex(random_bytes(18));$insert->execute(['admin','Workspace Admin',password_hash($adminPassword,PASSWORD_DEFAULT),'admin','Admin']);$insert->execute(['itstaff','IT Support',password_hash($itStaffPassword,PASSWORD_DEFAULT),'it_staff','IT Staff']);}
if(!is_dir(__DIR__.'/data/uploads'))mkdir(__DIR__.'/data/uploads',0770,true);
if(!is_dir(__DIR__.'/data/uploads/avatars'))mkdir(__DIR__.'/data/uploads/avatars',0770,true);
$secretFile=__DIR__.'/data/app-secret';if(!is_file($secretFile)){if(file_put_contents($secretFile,bin2hex(random_bytes(32)),LOCK_EX)===false)throw new RuntimeException('Could not create the application secret in data/app-secret.');@chmod($secretFile,0600);}
if(isset($adminPassword,$itStaffPassword))echo "Initial account passwords (shown once):\nadmin: $adminPassword\nitstaff: $itStaffPassword\n";
echo "Database ready. Change the initial account passwords after signing in.\n";
