<?php
function project_list($db,$u) {
  $list=query($db,'SELECT p.*,m.role,m.active FROM projects p JOIN project_members m ON m.project_id=p.id WHERE m.user_id=? AND m.active=1 ORDER BY p.created_at,p.id',[$u['id']])->fetchAll();
  foreach($list as &$p) $p['owned']=$p['owner_id']===$u['id'];
  return $list;
}
function project_access($db,$u,$id=null,$role=null) {
  if($id===null) $id=$_SERVER['HTTP_X_PROJECT_ID']??($_GET['project_id']??'');
  if(!$id) { $list=project_list($db,$u); $id=$list[0]['id']??null; }
  // Serialize writes with membership changes so a revoked role cannot authorize a later write.
  $lock=$db->inTransaction()?' FOR UPDATE':'';
  $p=$id?query($db,'SELECT p.*,m.role FROM projects p JOIN project_members m ON p.id=m.project_id WHERE p.id=? AND m.user_id=? AND m.active=1'.$lock,[$id,$u['id']])->fetch():false;
  if(!$p) throw new ApiError('项目不存在或你没有访问权限',403);
  if($role==='editor' && $p['role']==='viewer') throw new ApiError('当前项目仅有查看权限',403);
  if($role==='admin' && $p['role']!=='admin') throw new ApiError('此操作需要项目管理员权限',403);
  $p['owned']=$p['owner_id']===$u['id'];return $p;
}
function create_project($db,$u,$data) {
  $name=string_field($data,'name',80);if(!$name) throw new ApiError('请填写项目名称');
  $id=uid();$t=timestamp();$desc=string_field($data,'description',2000);
  query($db,'INSERT INTO projects(id,owner_id,name,description,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$id,$u['id'],$name,$desc,$t,$t]);
  query($db,"INSERT INTO project_members(project_id,user_id,role,active,created_at) VALUES(?,?,'admin',1,?)",[$id,$u['id'],$t]);
  log_change($db,$u,$id,'创建','项目',$id,$name,null,['name'=>$name,'description'=>$desc]);
  if(!empty($data['with_demo'])) {
    $seed=json_decode(file_get_contents(ROOT.'/seed.json'),true);$map=[];
    foreach($seed['entities'] as $n) {$v=clean_entity($n);$map[$n['id']]=uid();$v=array_merge($v,['id'=>$map[$n['id']],'version'=>1,'created_at'=>$t,'updated_at'=>$t]);insert_entity($db,$v,$id);}
    foreach($seed['relations'] as $r) {$r['source']=$map[$r['source']];$r['target']=$map[$r['target']];$v=array_merge(clean_relation($r,$db,$id),['id'=>uid(),'version'=>1,'created_at'=>$t,'updated_at'=>$t]);insert_relation($db,$v,$id);}
  }
  return project_access($db,$u,$id);
}
function project_members($db,$project) {
  return query($db,'SELECT u.id,u.username,u.name,m.role,m.active FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? ORDER BY m.created_at,u.id',[$project])->fetchAll();
}
function throttle($action,$limit=10) {
  $ratePath=ROOT.'/storage/'.$action.'-'.hash('sha256',$_SERVER['REMOTE_ADDR']??'local').'.json';
  $f=fopen($ratePath,'c+');if(!$f) throw new ApiError('限流存储不可用',503);flock($f,LOCK_EX);
  $a=json_decode(stream_get_contents($f),true)?:[];$a=array_values(array_filter($a,function($t){return $t>time()-300;}));
  if(count($a)>=$limit) {flock($f,LOCK_UN);fclose($f);throw new ApiError('尝试过多，请 5 分钟后重试',429);}
  $a[]=time();ftruncate($f,0);rewind($f);fwrite($f,json_encode($a));flock($f,LOCK_UN);fclose($f);return $ratePath;
}
function clear_throttle($path) {$f=fopen($path,'c+');if($f){flock($f,LOCK_EX);ftruncate($f,0);rewind($f);fwrite($f,'[]');flock($f,LOCK_UN);fclose($f);}}
function ensure_schema($db) {
  $settings=(bool)query($db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='settings'")->fetch();
  if($settings && query($db,"SELECT value FROM settings WHERE `key`='schema_version'")->fetchColumn()==='4') return;
  $lock='zhilian_schema_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,24);
  if((int)query($db,'SELECT GET_LOCK(?,15)',[$lock])->fetchColumn()!==1) throw new ApiError('数据库正在升级，请稍后重试',503);
  try {
    if(!$settings) {migrate($db);}
    $version=query($db,"SELECT value FROM settings WHERE `key`='schema_version'")->fetchColumn();
    if($version==='4') return;
    // Existing tables remain intact; create new tables before adding project columns.
    migrate($db);
    if($version==='3') {migrate_relation_type_history($db);return;}
    if($version==='2') {migrate_entity_type_history($db);migrate_relation_type_history($db);return;}
    foreach(['entities','relations','audit'] as $table) {
      if(!query($db,"SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name='project_id'",[$table])->fetch()) $db->exec("ALTER TABLE $table ADD COLUMN project_id VARCHAR(32) NULL, ADD INDEX(project_id)");
    }
    $db->beginTransaction();
    $users=query($db,'SELECT * FROM users ORDER BY created_at,id')->fetchAll();
    $orphan=(int)$db->query('SELECT COUNT(*) FROM entities WHERE project_id IS NULL')->fetchColumn()+(int)$db->query('SELECT COUNT(*) FROM audit WHERE project_id IS NULL')->fetchColumn()+(int)$db->query('SELECT COUNT(*) FROM relations WHERE project_id IS NULL')->fetchColumn();
    if($users && ($orphan || !query($db,'SELECT 1 FROM projects LIMIT 1')->fetch())) {
      $owner=$users[0];foreach($users as $candidate)if($candidate['role']==='admin'&&$candidate['active']){$owner=$candidate;break;}
      $name=query($db,"SELECT value FROM settings WHERE `key`='workspace'")->fetchColumn()?:'原团队项目';
      $p=create_project($db,$owner,['name'=>$name,'description'=>'由原共享空间迁移，保留原成员与数据。']);
      foreach($users as $member) query($db,'INSERT INTO project_members(project_id,user_id,role,active,created_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),active=VALUES(active)',[$p['id'],$member['id'],$member['id']===$owner['id']?'admin':$member['role'],(int)$member['active'],timestamp()]);
      foreach(['entities','relations','audit'] as $table) query($db,"UPDATE $table SET project_id=? WHERE project_id IS NULL",[$p['id']]);
    }
    foreach($users as $member) if(!query($db,'SELECT 1 FROM projects WHERE owner_id=?',[$member['id']])->fetch()) create_project($db,$member,['name'=>$member['name'].'的项目','description'=>'个人关联信息库']);
    $db->commit();
    // NOT NULL prevents records without project ownership on upgraded databases.
    foreach(['entities','relations','audit'] as $table) $db->exec("ALTER TABLE $table MODIFY project_id VARCHAR(32) NOT NULL");
    query($db,"INSERT INTO settings(`key`,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value='2'");
    migrate_entity_type_history($db);
    migrate_relation_type_history($db);
  } finally {query($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
}
