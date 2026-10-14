<?php
// 织联 API: PHP 7.4 target runtime, verified on PHP 7.4.33.
declare(strict_types=1);
date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
const ROOT = __DIR__ . '/..';
class ApiError extends Exception { public $status; public function __construct($message, $status=400) { parent::__construct($message); $this->status=$status; } }
function reply($data, $status=200) { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function timestamp() { return gmdate('Y-m-d\TH:i:s\Z'); }
function uid() { return bin2hex(random_bytes(12)); }
function query($db,$sql,$args=[]) { $s=$db->prepare($sql); $s->execute($args); return $s; }
function env_config() {
  $env=[];
  if (is_file(ROOT.'/.env')) foreach (file(ROOT.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    $line=trim($line); if ($line==='' || $line[0]==='#' || strpos($line,'=')===false) continue;
    [$key,$value]=explode('=',$line,2); $env[trim($key)]=trim($value," \t\n\r\0\x0B\"'");
  }
  foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','SESSION_SECURE'] as $k) if (getenv($k)!==false) $env[$k]=getenv($k);
  return $env;
}
function database($env) {
  if (!extension_loaded('pdo_mysql')) throw new ApiError('PHP 尚未启用 pdo_mysql 扩展，请按 README 配置。',503);
  if (empty($env['DB_DATABASE']) || empty($env['DB_USERNAME'])) throw new ApiError('请先配置 backend/.env 中的 MySQL 连接信息。',503);
  $dsn='mysql:host='.($env['DB_HOST']??'127.0.0.1').';port='.($env['DB_PORT']??'3306').';dbname='.$env['DB_DATABASE'].';charset=utf8mb4';
  try { return new PDO($dsn,$env['DB_USERNAME'],$env['DB_PASSWORD']??'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
  catch(PDOException $e) { error_log($e->getMessage()); throw new ApiError('MySQL 连接失败，请检查 backend/.env 和数据库服务。',503); }
}
function migrate($db) {
  foreach(explode(';',file_get_contents(ROOT.'/schema.sql')) as $sql) if(trim($sql)) $db->exec($sql);
}
function public_user($u) { return array_intersect_key($u,array_flip(['id','username','name','role','active'])); }
function current_user($db,$required=true,$role=null) {
  $u=null;
  if (!empty($_COOKIE['zhilian_session'])) $u=query($db,'SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>? AND u.active=1',[hash('sha256',$_COOKIE['zhilian_session']),time()])->fetch();
  if($required && !$u) throw new ApiError('请先登录',401);
  return $u;
}
function make_session($db,$u,$env) {
  $token=bin2hex(random_bytes(32));
  query($db,'DELETE FROM sessions WHERE expires<?',[time()]);
  query($db,'INSERT INTO sessions(token,user_id,expires) VALUES(?,?,?)',[hash('sha256',$token),$u['id'],time()+604800]);
  setcookie('zhilian_session',$token,['expires'=>time()+604800,'path'=>'/','secure'=>($env['SESSION_SECURE']??'0')==='1','httponly'=>true,'samesite'=>'Strict']);
}
function log_change($db,$u,$project,$action,$kind,$id,$label,$before=null,$after=null) {
  query($db,'INSERT INTO audit(project_id,at,actor_id,actor,action,kind,object_id,label,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?,?)',
    [$project,timestamp(),$u['id'],$u['name'],$action,$kind,$id,$label,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE)]);
}
function validate_user($data,$check_password=true) {
  foreach(['username','name'] as $k) if (string_field($data,$k,80)==='') throw new ApiError('请填写登录账号和姓名（最多 80 字）');
  if($check_password && (!is_string($data['password']??null) || preg_match_all('/./us',$data['password'],$unused)<8 || strlen($data['password'])>72 || strpos($data['password'],"\0")!==false)) throw new ApiError('密码至少 8 个字符，最多 72 字节（中文通常占 3 字节）');
}
function string_field($data,$key,$limit=500) {
  if(isset($data[$key]) && !is_string($data[$key])) throw new ApiError('字段格式不正确：'.$key);
  $v=trim($data[$key]??''); $length=preg_match_all('/./us',$v,$unused);
  if($length===false) throw new ApiError('字段编码不正确：'.$key);
  if($length>$limit) throw new ApiError('字段内容过长：'.$key); return $v;
}
function date_field($data,$key) {
  $v=string_field($data,$key,40); if($v!=='' && (strtotime($v)===false || !preg_match('/^\d{4}-\d{2}-\d{2}/',$v))) throw new ApiError('日期格式不正确'); return $v;
}
function clean_entity($data) {
  $n=[]; foreach(['name'=>160,'type'=>40,'owner'=>120,'description'=>5000] as $k=>$limit) $n[$k]=string_field($data,$k,$limit);
  if(!$n['name'] || !$n['type']) throw new ApiError('请填写对象名称和类型');
  $n['status']=$data['status']??'active'; if(!in_array($n['status'],['active','inactive','archived'],true)) throw new ApiError('无效的对象状态');
  $tags=$data['tags']??[]; $fields=$data['fields']??[];
  if(!is_array($tags) || count($tags)>30) throw new ApiError('标签格式不正确');
  foreach($tags as $tag) if(!is_string($tag)||strlen($tag)>180) throw new ApiError('标签格式不正确');
  if(!is_array($fields)||count($fields)>30) throw new ApiError('扩展字段格式不正确');
  foreach($fields as $k=>$v) if(!is_string($v)||strlen((string)$k)>240||strlen($v)>6000) throw new ApiError('扩展字段格式不正确');
  $n['tags']=array_values(array_unique($tags)); $n['fields']=(object)$fields; $n['verified_at']=date_field($data,'verified_at');
  foreach(['x','y'] as $k) if(isset($data[$k])) { if(!is_numeric($data[$k])||!is_finite((float)$data[$k])||abs((float)$data[$k])>10000) throw new ApiError('节点位置不正确'); $n[$k]=(float)$data[$k]; }
  return $n;
}
function clean_relation($data,$db,$project) {
  $r=[]; foreach(['source'=>32,'target'=>32,'type'=>60,'role'=>120,'owner'=>120,'description'=>5000,'evidence'=>2000] as $k=>$limit) $r[$k]=string_field($data,$k,$limit);
  if(!$r['source']||!$r['target']||!$r['type']) throw new ApiError('请选择两端对象并填写关系类型');
  if($r['source']===$r['target']) throw new ApiError('请选择两个不同的对象');
  foreach(['source','target'] as $k) if(!query($db,'SELECT id FROM entities WHERE id=? AND project_id=?',[$r[$k],$project])->fetch()) throw new ApiError('关联对象不存在或不属于当前项目');
  $r['status']=$data['status']??'active'; if(!in_array($r['status'],['active','ended'],true)) throw new ApiError('无效的关系状态');
  foreach(['verified_at','start_date','end_date'] as $k) $r[$k]=date_field($data,$k);
  if($r['start_date'] && $r['end_date'] && $r['start_date']>$r['end_date']) throw new ApiError('结束日期不能早于开始日期');
  return $r;
}
function all_records($db,$table,$project) {
  return array_map(function($r){$v=json_decode($r['body'],true);$v['version']=(int)$r['version'];return $v;},query($db,"SELECT * FROM $table WHERE project_id=?",[$project])->fetchAll());
}
function insert_entity($db,$n,$project) { query($db,'INSERT INTO entities(id,project_id,body,version) VALUES(?,?,?,?)',[$n['id'],$project,json_encode($n,JSON_UNESCAPED_UNICODE),$n['version']]); }
function insert_relation($db,$r,$project) { query($db,'INSERT INTO relations(id,project_id,source,target,body,version) VALUES(?,?,?,?,?,?)',[$r['id'],$project,$r['source'],$r['target'],json_encode($r,JSON_UNESCAPED_UNICODE),$r['version']]); }
require ROOT.'/projects.php';
require ROOT.'/dispatch.php';
