import test from 'node:test';
import assert from 'node:assert/strict';
import {canvasRecords,descendantsOf,scopeTrail,hierarchyOrder} from '../src/subgraphs.js';

const nodes=[{id:'system',has_subgraph:true},{id:'external'},{id:'a',parent_id:'system',has_subgraph:true},{id:'b',parent_id:'system'},{id:'deep',parent_id:'a'}];
const edges=[{id:'root',source:'system',target:'external'},{id:'flow',source:'a',target:'b'},{id:'hidden',source:'deep',target:'b'}];
test('每层只展示直属对象和同层关系，顶层兼容无 parent_id 的旧对象',()=>{
  assert.deepEqual(canvasRecords(nodes,edges).entities.map(n=>n.id),['system','external']);
  assert.deepEqual(canvasRecords(nodes,edges).relations.map(n=>n.id),['root']);
  assert.deepEqual(canvasRecords(nodes,edges,'system').entities.map(n=>n.id),['a','b']);
  assert.deepEqual(canvasRecords(nodes,edges,'system').relations.map(n=>n.id),['flow']);
  assert.deepEqual(canvasRecords(nodes,edges,'a').entities.map(n=>n.id),['deep']);
});
test('面包屑支持多层，损坏层级不会死循环',()=>{
  assert.deepEqual(scopeTrail('a',nodes).map(n=>n.id),['system','a']);
  assert.deepEqual(scopeTrail('missing',nodes),[]);
  assert.deepEqual(scopeTrail('x',[{id:'x',parent_id:'y'},{id:'y',parent_id:'x'}]),[]);
});
test('删除范围包含全部后代，保留兄弟和外部对象',()=>{
  assert.deepEqual(descendantsOf('system',nodes).map(n=>n.id),['a','b','deep']);
  assert.deepEqual(descendantsOf('a',nodes).map(n=>n.id),['deep']);
  assert.deepEqual(descendantsOf('b',nodes),[]);
});
test('导入按父对象优先排序，拒绝循环、重复、缺失父对象和未开启子图',()=>{
  assert.deepEqual(hierarchyOrder([...nodes].reverse()).map(n=>n.id),['external','system','b','a','deep']);
  for(const data of [[{id:'a',has_subgraph:true,parent_id:'a'}],[{id:'a',has_subgraph:true,parent_id:'b'},{id:'b',has_subgraph:true,parent_id:'a'}],[{id:'a'},{id:'a'}],[{id:'a',parent_id:'missing'}],[{id:'a'},{id:'b',parent_id:'a'}]])assert.throws(()=>hierarchyOrder(data));
});
