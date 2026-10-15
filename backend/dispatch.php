<?php
$db=null;
try {
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];$env=env_config();
  if($method==='GET'&&$path==='/api/health') {
    try {$db=database($env);reply(['ok'=>true,'database'=>true,'mysql_version'=>$db->query('SELECT VERSION()')->fetchColumn()]);}
    catch(ApiError $e){reply(['ok'=>true,'database'=>false,'message'=>$e->getMessage()]);}
  }
  if(!in_array($method,['GET','POST','PUT'],true))throw new ApiError('请求方法不支持',405);
  $data=[];
  if($method!=='GET') {
    $origin=$_SERVER['HTTP_ORIGIN']??'';$host=$_SERVER['HTTP_HOST']??'';$parts=$origin?parse_url($origin):[];
    $originHost=($parts['host']??'').(isset($parts['port'])?':'.$parts['port']:'');
    if($origin&&$originHost!==$host)throw new ApiError('拒绝跨站写入请求',403);
    if(($_SERVER['HTTP_X_ZHILIAN_REQUEST']??'')!=='1')throw new ApiError('缺少请求校验标记',403);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>5000000)throw new ApiError('请求内容超过 5 MB');
    $data=json_decode(file_get_contents('php://input'),true);
    if(!is_array($data)||json_last_error()!==JSON_ERROR_NONE)throw new ApiError('请求内容不是有效的 JSON 对象');
  }
  $db=database($env);ensure_schema($db);
  if($method==='POST'&&in_array($path,['/api/register','/api/bootstrap'],true)) {
    throttle('register',20);validate_user($data);
    if(isset($data['password_confirmation'])&&$data['password_confirmation']!==$data['password'])throw new ApiError('两次输入的密码不一致');
    if($path==='/api/bootstrap'&&query($db,'SELECT 1 FROM users LIMIT 1')->fetch())throw new ApiError('已有用户，请使用注册入口',409);
    $db->beginTransaction();
    $username=trim($data['username']);if(query($db,'SELECT 1 FROM users WHERE username=?',[$username])->fetch())throw new ApiError('登录账号已存在，请换一个或直接登录',409);
    $id=uid();query($db,"INSERT INTO users(id,username,name,password,role,active,created_at) VALUES(?,?,?,?,'editor',1,?)",[$id,$username,trim($data['name']),password_hash($data['password'],PASSWORD_DEFAULT),timestamp()]);
    $u=query($db,'SELECT * FROM users WHERE id=?',[$id])->fetch();
    $p=create_project($db,$u,['name'=>$data['project_name']??($data['workspace']??($u['name'].'的项目')),'description'=>'个人关联信息库','with_demo'=>!empty($data['with_demo'])]);
    make_session($db,$u,$env);$db->commit();reply(['user'=>public_user($u),'project'=>$p]);
  }
  if($method==='POST'&&$path==='/api/login') {
    $rate=throttle('login');$u=query($db,'SELECT * FROM users WHERE username=? AND active=1',[string_field($data,'username',80)])->fetch();
    if(!$u||!password_verify((string)($data['password']??''),$u['password']))throw new ApiError('账号或密码不正确',401);
    clear_throttle($rate);$db->beginTransaction();make_session($db,$u,$env);$db->commit();reply(['user'=>public_user($u)]);
  }
  if($method==='GET'&&$path==='/api/state') {
    $u=current_user($db,false);if(!$u)reply(['user'=>null,'configured'=>true,'registration'=>true,'demo'=>true,'database'=>true,'entity_types'=>[],'relation_types'=>[]]);
    $list=project_list($db,$u);
    $entityTypes=entity_type_options($db,$u);
    $relationTypes=relation_type_options($db,$u);
    if(!$list)reply(['user'=>public_user($u),'projects'=>[],'project'=>null,'workspace'=>'我的项目','entities'=>[],'relations'=>[],'demo'=>false,'database'=>true,'entity_types'=>$entityTypes,'relation_types'=>$relationTypes]);
    $p=project_access($db,$u);$public=public_user($u);$public['role']=$p['role'];
    reply(['user'=>$public,'projects'=>$list,'project'=>$p,'workspace'=>$p['name'],'demo'=>false,'configured'=>true,'database'=>true,'entity_types'=>$entityTypes,'relation_types'=>$relationTypes,'entities'=>all_records($db,'entities',$p['id']),'relations'=>all_records($db,'relations',$p['id'])]);
  }
  $u=current_user($db);
  if($method==='POST'&&$path==='/api/logout') {query($db,'DELETE FROM sessions WHERE token=?',[hash('sha256',$_COOKIE['zhilian_session']??'')]);setcookie('zhilian_session','',['expires'=>1,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);reply(['ok'=>true]);}
  if($path==='/api/projects') {
    if($method==='GET')reply(['projects'=>project_list($db,$u)]);
    if($method!=='POST')throw new ApiError('请求方法不支持',405);
    $db->beginTransaction();$p=create_project($db,$u,$data);$db->commit();reply(['project'=>$p]);
  }
  if(preg_match('#^/api/projects/([a-f0-9]{24})$#',$path,$matches)&&$method==='PUT') {
    $db->beginTransaction();$p=project_access($db,$u,$matches[1],'admin');
    if(!$p['owned'])throw new ApiError('只有项目创建者可以修改项目资料',403);
    $name=string_field($data,'name',80);$description=string_field($data,'description',2000);if(!$name)throw new ApiError('请填写项目名称');
    query($db,'UPDATE projects SET name=?,description=?,updated_at=? WHERE id=?',[$name,$description,timestamp(),$p['id']]);
    log_change($db,$u,$p['id'],'修改','项目',$p['id'],$name,['name'=>$p['name'],'description'=>$p['description']],['name'=>$name,'description'=>$description]);$db->commit();reply(['project'=>project_access($db,$u,$p['id'])]);
  }
  $p=project_access($db,$u);$pid=$p['id'];
  if($method==='GET') {
    if($path==='/api/audit') {
      $entries=query($db,'SELECT * FROM audit WHERE project_id=? ORDER BY id DESC LIMIT 500',[$pid])->fetchAll();
      foreach($entries as &$e){$e['before']=$e['before_json']?json_decode($e['before_json'],true):null;$e['after']=$e['after_json']?json_decode($e['after_json'],true):null;unset($e['before_json'],$e['after_json']);}
      reply(['entries'=>$entries]);
    }
    if($path==='/api/users') {project_access($db,$u,$pid,'admin');reply(['users'=>project_members($db,$pid)]);}
    if($path==='/api/export') {project_access($db,$u,$pid,'editor');reply(['format'=>'zhilian-library','schema_version'=>1,'project_name'=>$p['name'],'exported_at'=>timestamp(),'entities'=>all_records($db,'entities',$pid),'relations'=>all_records($db,'relations',$pid)]);}
    throw new ApiError('接口不存在',404);
  }
  $db->beginTransaction();$p=project_access($db,$u,$pid,'editor');
  if(preg_match('#^/api/(entities|relations)(?:/([a-f0-9]{24}))?$#',$path,$matches)) {
    $table=$matches[1];$id=$matches[2]??null;$kind=$table==='entities'?'对象':'关系';
    if(($method==='PUT')!==($id!==null))throw new ApiError('请求路径不正确',404);
    $initial=array_key_exists('initial_relation',$data);
    if($initial && ($table!=='entities'||$method!=='POST'||!is_array($data['initial_relation'])))throw new ApiError('起始关系只能在新建对象时指定，且必须为对象格式');
    $before=null;$version=1;
    if($id) {
      $old=query($db,"SELECT * FROM $table WHERE id=? AND project_id=? FOR UPDATE",[$id,$pid])->fetch();if(!$old)throw new ApiError('记录不存在于当前项目',404);
      if(($data['version']??null)!==(int)$old['version'])throw new ApiError('记录已被其他成员修改，请刷新后重新编辑',409);
      $before=json_decode($old['body'],true);$version=(int)$old['version']+1;
    } else $id=uid();
    $clean=$table==='entities'?clean_entity($data):clean_relation($data,$db,$pid);
    $clean=array_merge($clean,['id'=>$id,'version'=>$version,'created_at'=>$before['created_at']??timestamp(),'updated_at'=>timestamp()]);
    if($before) {
      if($table==='entities')query($db,'UPDATE entities SET body=?,version=? WHERE id=? AND project_id=?',[json_encode($clean,JSON_UNESCAPED_UNICODE),$version,$id,$pid]);
      else query($db,'UPDATE relations SET source=?,target=?,body=?,version=? WHERE id=? AND project_id=?',[$clean['source'],$clean['target'],json_encode($clean,JSON_UNESCAPED_UNICODE),$version,$id,$pid]);
    } else {if($table==='entities')insert_entity($db,$clean,$pid);else insert_relation($db,$clean,$pid);}
    log_change($db,$u,$pid,$before?'修改':'新增',$kind,$id,$clean['name']??$clean['type'],$before,$clean);$result=['record'=>$clean];
    if($table==='entities' && (!$before || $before['type']!==$clean['type'])) remember_entity_type($db,$u,$clean['type']);
    if($table==='relations' && (!$before || $before['type']!==$clean['type'])) remember_relation_type($db,$u,$clean['type']);
    if($initial) {
      // The object, initial directed relation and both audit entries commit together.
      $relation=clean_relation(array_merge(['owner'=>$clean['owner']?:$u['name'],'status'=>'active','verified_at'=>timestamp()],$data['initial_relation'],['target'=>$id]),$db,$pid);
      $relation=array_merge($relation,['id'=>uid(),'version'=>1,'created_at'=>timestamp(),'updated_at'=>timestamp()]);
      insert_relation($db,$relation,$pid);
      remember_relation_type($db,$u,$relation['type']);
      log_change($db,$u,$pid,'新增','关系',$relation['id'],$relation['type'],null,$relation);
      $result['relation']=$relation;
    }
  } elseif($path==='/api/import') {
    if(($data['format']??'')!=='zhilian-library'||($data['schema_version']??null)!==1)throw new ApiError('请选择织联导出的 JSON 文件（版本 1）');
    $nodes=$data['entities']??null;$edges=$data['relations']??null;
    if(!is_array($nodes)||!is_array($edges)||count($nodes)>2000||count($edges)>10000)throw new ApiError('导入最多支持 2000 个对象、10000 条关系');
    $map=[];
    foreach($nodes as $n){if(!is_array($n)||!is_string($n['id']??null)||isset($map[$n['id']]))throw new ApiError('对象编号缺失或重复');$clean=clean_entity($n);$id=uid();$map[$n['id']]=$id;$clean=array_merge($clean,['id'=>$id,'version'=>1,'created_at'=>timestamp(),'updated_at'=>timestamp()]);insert_entity($db,$clean,$pid);remember_entity_type($db,$u,$clean['type']);}
    foreach($edges as $r){if(!is_array($r)||!isset($map[$r['source']??''])||!isset($map[$r['target']??'']))throw new ApiError('关系引用了不存在的对象');$r['source']=$map[$r['source']];$r['target']=$map[$r['target']];$clean=array_merge(clean_relation($r,$db,$pid),['id'=>uid(),'version'=>1,'created_at'=>timestamp(),'updated_at'=>timestamp()]);insert_relation($db,$clean,$pid);remember_relation_type($db,$u,$clean['type']);}
    log_change($db,$u,$pid,'导入','项目',$pid,'导入关联信息',null,['对象数'=>count($nodes),'关系数'=>count($edges)]);$result=['entities'=>count($nodes),'relations'=>count($edges)];
  } elseif($path==='/api/users'&&$method==='POST') {
    project_access($db,$u,$pid,'admin');$role=$data['role']??'editor';if(!in_array($role,['admin','editor','viewer'],true))throw new ApiError('无效的项目角色');
    $member=query($db,'SELECT * FROM users WHERE username=? AND active=1',[string_field($data,'username',80)])->fetch();if(!$member)throw new ApiError('该账号尚未注册，请先让对方注册');
    $old=query($db,'SELECT * FROM project_members WHERE project_id=? AND user_id=?',[$pid,$member['id']])->fetch();
    if($old&&$old['active'])throw new ApiError('该用户已在当前项目中',409);
    query($db,'INSERT INTO project_members(project_id,user_id,role,active,created_at) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE role=VALUES(role),active=1',[$pid,$member['id'],$role,timestamp()]);
    log_change($db,$u,$pid,'添加','成员',$member['id'],$member['name'],null,['username'=>$member['username'],'name'=>$member['name'],'role'=>$role]);$result=['ok'=>true];
  } elseif(preg_match('#^/api/users/([a-f0-9]{24})$#',$path,$matches)&&$method==='PUT') {
    project_access($db,$u,$pid,'admin');$id=$matches[1];
    $old=query($db,'SELECT u.id,u.username,u.name,m.role,m.active FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? AND m.user_id=? FOR UPDATE',[$pid,$id])->fetch();if(!$old)throw new ApiError('成员不在当前项目中',404);
    $role=$data['role']??$old['role'];$active=isset($data['active'])?(int)(bool)$data['active']:(int)$old['active'];
    if(!in_array($role,['admin','editor','viewer'],true))throw new ApiError('无效的项目角色');
    if(isset($data['password']))throw new ApiError('项目管理员不能修改其他用户的登录密码',403);
    if($id===$p['owner_id']&&($role!=='admin'||!$active))throw new ApiError('项目创建者始终保留管理员权限');
    query($db,'UPDATE project_members SET role=?,active=? WHERE project_id=? AND user_id=?',[$role,$active,$pid,$id]);
    $after=array_merge($old,['role'=>$role,'active'=>$active]);log_change($db,$u,$pid,'修改','成员',$id,$old['name'],$old,$after);$result=['ok'=>true];
  } else throw new ApiError('接口不存在',404);
  $db->commit();reply($result);
} catch(ApiError $e){if($db&&$db->inTransaction())$db->rollBack();reply(['error'=>$e->getMessage()],$e->status);}
catch(Throwable $e){if($db&&$db->inTransaction())$db->rollBack();error_log((string)$e);$duplicate=$e instanceof PDOException&&($e->errorInfo[1]??null)===1062;reply(['error'=>$duplicate?'账号或记录已存在，请刷新后重试。':'服务器未能完成请求，请查看 PHP 日志并重试。'],$duplicate?409:500);}
