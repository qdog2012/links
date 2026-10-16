<?php
function entity_record($row) {
  $n=json_decode($row['body'],true);
  $n['version']=(int)$row['version'];$n['parent_id']=$row['parent_id']??null;$n['has_subgraph']=$n['has_subgraph']??false;
  return $n;
}
function validate_entity_scope($db,$project,$record,$before=null) {
  if($before && $record['parent_id']!==($before['parent_id']??null))throw new ApiError('暂不支持将已有对象移动到其他图层');
  if($record['parent_id']!==null) {
    $row=query($db,'SELECT * FROM entities WHERE id=? AND project_id=?',[$record['parent_id'],$project])->fetch();
    if(!$row||empty(entity_record($row)['has_subgraph']))throw new ApiError('子图所属对象不存在、未启用子图或不属于当前项目');
  }
  if($before && !$record['has_subgraph'] && query($db,'SELECT 1 FROM entities WHERE parent_id=? AND project_id=? LIMIT 1',[$before['id'],$project])->fetch())throw new ApiError('子图中还有对象，不能关闭子图');
}
function hierarchy_order($records) {
  $byId=[];$children=[];$roots=[];
  foreach($records as $n){if(!is_array($n)||!is_string($n['id']??null)||$n['id']===''||isset($byId[$n['id']]))throw new ApiError('对象编号缺失或重复');$byId[$n['id']]=$n;}
  foreach($records as $n){$parent=$n['parent_id']??null;if($parent===null)$roots[]=$n;else{if(!is_string($parent)||!isset($byId[$parent])||empty($byId[$parent]['has_subgraph']))throw new ApiError('子图所属对象不存在或未启用子图');$children[$parent][]=$n;}}
  for($i=0;$i<count($roots);$i++)foreach($children[$roots[$i]['id']]??[] as $n)$roots[]=$n;
  if(count($roots)!==count($records))throw new ApiError('子图不能包含自身或形成循环嵌套');
  return $roots;
}
function entity_descendants($id,$records) {
  $children=[];foreach($records as $n)if(($n['parent_id']??null)!==null)$children[$n['parent_id']][]=$n;
  $found=[];$queue=[$id];$seen=[$id=>true];
  for($i=0;$i<count($queue);$i++)foreach($children[$queue[$i]]??[] as $n)if(!isset($seen[$n['id']])){$seen[$n['id']]=true;$queue[]=$n['id'];$found[]=$n;}
  return $found;
}
function confirm_deletion_snapshot($expected,$records,$label) {
  if(!is_array($expected))throw new ApiError('缺少'.$label.'的删除预览，请重新确认');
  $confirmed=[];$actual=[];
  foreach($expected as $r){if(!is_array($r)||!is_string($r['id']??null)||!is_int($r['version']??null)||isset($confirmed[$r['id']]))throw new ApiError($label.'的删除预览格式不正确');$confirmed[$r['id']]=$r['version'];}
  foreach($records as $r)$actual[$r['id']]=(int)$r['version'];ksort($confirmed);ksort($actual);
  if($confirmed!==$actual)throw new ApiError($label.'已发生变化，请更新删除预览后重新确认',409);
}
function migrate_subgraphs($db) {
  // Additive and restartable: legacy objects remain in the root canvas.
  if(!query($db,"SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='entities' AND column_name='parent_id'")->fetch())$db->exec('ALTER TABLE entities ADD COLUMN parent_id VARCHAR(32) NULL, ADD INDEX(project_id,parent_id)');
  query($db,"INSERT INTO settings(`key`,value) VALUES('schema_version','5') ON DUPLICATE KEY UPDATE value='5'");
}
